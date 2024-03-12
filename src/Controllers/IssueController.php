<?php

namespace G4T\Swagger\Controllers;

use App\Http\Controllers\Controller;

class IssueController extends Controller
{

    public function index()
    {
        $logFilePath = storage_path('logs/laravel.log');
        $logContents = file_get_contents($logFilePath);
        $issues = $this->parseNewLogEntries($logContents);
        return $issues;
    }

    private function parseNewLogEntries($logContents)
    {
        $newEntries = [];
        $logLines = explode("\n", $logContents);
        foreach ($logLines as $line) {
            if (str_contains($line, 'g4t/swagger') && str_contains($line, 'local.')) {
                $newEntries[] = $line;
            }
        }
        return $newEntries;
    }
}
