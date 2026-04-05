<?php

namespace G4T\Swagger\Commands;

use G4T\Swagger\Controllers\DocumentationController;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class PublishSwaggerMockServerCommand extends Command
{
    private const MOCK_JSON = 'mock.json';

    private const ENV_APP_ID_KEY = 'MOCK_SERVER_APP_ID';

    protected $signature = 'swagger:mock-server
                            {--url= : Override config swagger.mock_server_url}
                            {--app-id= : Existing mock app UUID (update; skips prompts)}';

    protected $description = 'Generate mock.json from OpenAPI and publish it to the G4T-hosted mock API';

    public function handle(): int
    {
        if (! config('swagger.enable', true)) {
            $this->error('Swagger is disabled (swagger.enable). Enable it to generate documentation.');

            return self::FAILURE;
        }

        $mockPath = $this->writeMockJson();
        if ($mockPath === null) {
            return self::FAILURE;
        }

        $this->info("OpenAPI written to {$mockPath}");

        $baseUrl = rtrim((string) ($this->option('url') ?: config('swagger.mock_server_url', 'https://mock.g4t.io')), '/');
        $endpoint = "{$baseUrl}/api/mocks";

        $resolved = $this->resolveAppIdForRequest();
        if ($resolved === false) {
            return self::FAILURE;
        }
        $appId = $resolved;

        try {
            $response = $this->sendMultipart($endpoint, $mockPath, $appId);
        } catch (Throwable $e) {
            $this->error('Mock server request failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->reportMockServerFailure($response, $appId);

            return self::FAILURE;
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $this->error('Unexpected response (not JSON).');

            return self::FAILURE;
        }

        $this->displaySuccess($payload);

        $uuid = $payload['app_uuid'] ?? null;
        if (is_string($uuid) && $uuid !== '' && $this->shouldPersistAppIdToEnv()) {
            if ($this->persistAppIdInEnv($uuid)) {
                $this->info('Updated '.base_path('.env').' with '.self::ENV_APP_ID_KEY.'='.$uuid);
            } else {
                $this->warn('Could not write '.self::ENV_APP_ID_KEY.' to .env.');
            }
        }

        return self::SUCCESS;
    }

    private function writeMockJson(): ?string
    {
        $path = public_path(self::MOCK_JSON);
        $doc = new DocumentationController;
        $jsonData = $doc->getSwaggerData();
        $flags = JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT;
        $written = file_put_contents($path, json_encode($jsonData, $flags));

        if ($written === false) {
            $this->error("Could not write {$path}");

            return null;
        }

        return $path;
    }

    /**
     * @return string|null|false UUID to send as app_id, null for create, false on validation error
     */
    private function resolveAppIdForRequest(): string|null|false
    {
        $fromOption = $this->option('app-id');
        if (is_string($fromOption) && $fromOption !== '') {
            if (! $this->isValidUuid($fromOption)) {
                $this->error('--app-id must be a valid UUID.');

                return false;
            }

            return Str::lower($fromOption);
        }

        $fromConfig = config('swagger.mock_server_app_id');
        if (is_string($fromConfig) && $fromConfig !== '') {
            if (! $this->isValidUuid($fromConfig)) {
                $this->error(self::ENV_APP_ID_KEY.' in .env is not a valid UUID. Fix or unset it.');

                return false;
            }

            return Str::lower(trim($fromConfig));
        }

        if ($this->option('no-interaction')) {
            return null;
        }

        $update = $this->confirm('Do you want to update an existing mockup server?', false);

        if (! $update) {
            return null;
        }

        $appId = (string) $this->ask('App id (UUID)', '');
        $appId = trim($appId);

        if ($appId === '') {
            $this->warn('No app id provided; sending a create request without app_id.');

            return null;
        }

        if (! $this->isValidUuid($appId)) {
            $this->error('App id must look like fa724792-3f97-44c2-bf92-480ebc24c56c');

            return false;
        }

        return Str::lower($appId);
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function sendMultipart(string $endpoint, string $absolutePath, ?string $appId)
    {
        $contents = file_get_contents($absolutePath);
        if ($contents === false) {
            throw new \RuntimeException('Could not read mock.json for upload.');
        }

        $pending = Http::timeout(120)
            ->acceptJson()
            ->attach('file', $contents, self::MOCK_JSON, ['Content-Type' => 'application/json']);

        $fields = [];
        if ($appId !== null && $appId !== '') {
            $fields['app_id'] = $appId;
        }

        return $pending->post($endpoint, $fields);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function displaySuccess(array $payload): void
    {
        $this->info('Mock server updated successfully.');
        $rows = [];
        foreach (['app_uuid', 'docs_url', 'openapi_url'] as $key) {
            if (isset($payload[$key])) {
                $rows[] = [$key, (string) $payload[$key]];
            }
        }
        if ($rows !== []) {
            $this->table(['Key', 'Value'], $rows);
        }
    }

    private function reportMockServerFailure(Response $response, ?string $appIdSent): void
    {
        $status = $response->status();
        $detail = $this->extractMockServerErrorMessage($response);
        $body = $response->body();

        $this->error("Mock server request failed (HTTP {$status}).");

        if ($detail !== null && $detail !== '') {
            $this->line($detail);
        } elseif ($body !== '') {
            $this->line($body);
        }

        $hint = $this->hintForMockServerFailure($status, $detail, $appIdSent);
        if ($hint !== null) {
            $this->newLine();
            foreach ($hint as $line) {
                $this->comment($line);
            }
        }
    }

    private function extractMockServerErrorMessage(Response $response): ?string
    {
        $data = $response->json();
        if (! is_array($data)) {
            return null;
        }

        if (isset($data['message'])) {
            $m = $data['message'];
            if (is_string($m) && $m !== '') {
                return $m;
            }
            if (is_array($m)) {
                return json_encode($m, JSON_UNESCAPED_UNICODE);
            }
        }

        if (isset($data['error']) && is_string($data['error']) && $data['error'] !== '') {
            return $data['error'];
        }

        if (isset($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $messages) {
                if (is_array($messages)) {
                    $first = reset($messages);

                    return is_string($first) ? $first : null;
                }
                if (is_string($messages)) {
                    return $messages;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function hintForMockServerFailure(int $status, ?string $detail, ?string $appIdSent): ?array
    {
        if ($status !== 404 || $appIdSent === null || $appIdSent === '') {
            return null;
        }

        $lower = $detail !== null ? Str::lower($detail) : '';
        $unknownApp = $detail === null || $detail === ''
            || str_contains($lower, 'mock app')
            || str_contains($lower, 'app_uuid')
            || str_contains($lower, 'app uuid');

        if (! $unknownApp) {
            return null;
        }

        return [
            'The G4T mock service has no project for that app id (wrong UUID, revoked project, or typo).',
            'To create a new mock: run again and decline "update", or remove '.self::ENV_APP_ID_KEY.' from .env and do not pass --app-id.',
        ];
    }

    private function shouldPersistAppIdToEnv(): bool
    {
        if ($this->option('no-interaction')) {
            return false;
        }

        $existing = config('swagger.mock_server_app_id');
        if (is_string($existing) && trim($existing) !== '') {
            return false;
        }

        return $this->confirm('Save app id to .env ('.self::ENV_APP_ID_KEY.') so future runs update this mock without asking?', true);
    }

    private function persistAppIdInEnv(string $appUuid): bool
    {
        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            return false;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return false;
        }

        $pattern = '/^\s*'.preg_quote(self::ENV_APP_ID_KEY, '/').'\s*=/';
        $replacement = self::ENV_APP_ID_KEY.'='.$appUuid;
        $found = false;
        $out = [];

        foreach ($lines as $line) {
            if (preg_match($pattern, $line)) {
                $out[] = $replacement;
                $found = true;
            } else {
                $out[] = $line;
            }
        }

        if (! $found) {
            $out[] = $replacement;
        }

        return file_put_contents($path, implode(PHP_EOL, $out).PHP_EOL) !== false;
    }

    private function isValidUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            trim($value)
        );
    }
}
