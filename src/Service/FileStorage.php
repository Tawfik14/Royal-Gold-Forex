<?php

namespace App\Service;

class FileStorage
{
    private string $dataDir;

    public function __construct(string $projectDir)
    {
        $this->dataDir = rtrim($projectDir, '/').'/var/data';
        if (!is_dir($this->dataDir)) {
            @mkdir($this->dataDir, 0777, true);
        }
    }

    public function appendJsonLine(string $filename, array $row): void
    {
        $path = $this->dataDir.'/'.$filename;
        $line = json_encode($row, JSON_UNESCAPED_UNICODE).PHP_EOL;
        file_put_contents($path, $line, FILE_APPEND);
    }

    public function readJsonLines(string $filename): array
    {
        $path = $this->dataDir.'/'.$filename;
        if (!is_file($path)) {
            return [];
        }
        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        return array_reverse($rows); // newest first
    }
}
