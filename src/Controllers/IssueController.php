<?php

namespace G4T\Swagger\Controllers;

class IssueController
{

    public function index()
    {
        $logFilePath = storage_path('logs/laravel.log');
        if (!file_exists($logFilePath)) {
            $issues = [];
        } else {
            $logContents = file_get_contents($logFilePath);
            $issues = $this->parseNewLogEntries($logContents);
        }
        $issues = array_reverse($issues);
        return view('swagger::issues', ['issues' => $issues]);
    }

    private function parseNewLogEntries($logContents)
    {
        $newEntries = [];
        $logLines = explode("\n", $logContents);
        foreach ($logLines as $line) {
            if (str_contains($line, 'g4t/swagger') && str_contains($line, 'local.')) {
                preg_match_all('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches);
                $newEntries[] = [
                    'message' => $line,
                    'date' => isset($matches[1][0]) ? $matches[1][0] : "Unknwon"
                ];
            }
        }
        return $newEntries;
    }
}
