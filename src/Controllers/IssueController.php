<?php

namespace G4T\Swagger\Controllers;

use Illuminate\Http\Response;

class IssueController
{
    /**
     * Only the end of the log is scanned, so a large laravel.log never exhausts memory.
     */
    private const MAX_BYTES = 512 * 1024;

    public function index()
    {
        if (! config('swagger.enable')) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $issues = array_reverse($this->parseNewLogEntries($this->readLogTail(storage_path('logs/laravel.log'))));

        return view('swagger::issues', ['issues' => $issues]);
    }

    private function readLogTail(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return '';
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        $size = filesize($path) ?: 0;
        if ($size > self::MAX_BYTES) {
            fseek($handle, -self::MAX_BYTES, SEEK_END);
            fgets($handle); // drop the partial first line
        }
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $contents;
    }

    private function parseNewLogEntries(string $logContents): array
    {
        $entries = [];
        foreach (explode("\n", $logContents) as $line) {
            if (! str_contains($line, 'g4t/swagger') || ! preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+\w+\.\w+:/', $line, $matches)) {
                continue;
            }
            $entries[] = [
                'message' => $line,
                'date' => $matches[1],
            ];
        }

        return $entries;
    }
}
