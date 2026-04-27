<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class TenantWorkspaceContentDocumentParser
{
    /**
     * @return array{markdown:string,summary:string,content_json:?array<string,mixed>,structured_data_workspace_path:?string}
     */
    public function parse(string $path, string $originalFilename, string $mimeType = ''): array
    {
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        return match ($extension) {
            'txt', 'md' => $this->parseTextLike((string) file_get_contents($path), $originalFilename),
            'csv' => $this->parseCsv((string) file_get_contents($path), $originalFilename),
            'docx' => $this->parseDocx($path, $originalFilename),
            'xlsx' => $this->parseXlsx($path, $originalFilename),
            'pdf' => $this->parsePdf((string) file_get_contents($path), $originalFilename),
            default => throw new RuntimeException('This document type is not supported yet.'),
        };
    }

    /**
     * @return array{markdown:string,summary:string,content_json:?array<string,mixed>,structured_data_workspace_path:?string}
     */
    private function parseTextLike(string $contents, string $originalFilename): array
    {
        $normalized = trim(str_replace("\r\n", "\n", $contents));
        $summary = $this->buildSummary($normalized, sprintf('Imported text content from %s.', $originalFilename));

        return [
            'markdown' => "# {$originalFilename}\n\n".$normalized."\n",
            'summary' => $summary,
            'content_json' => null,
            'structured_data_workspace_path' => null,
        ];
    }

    /**
     * @return array{markdown:string,summary:string,content_json:array<string,mixed>,structured_data_workspace_path:string}
     */
    private function parseCsv(string $contents, string $originalFilename): array
    {
        $rows = preg_split('/\r?\n/', trim($contents)) ?: [];
        $parsedRows = [];

        foreach ($rows as $row) {
            if (trim($row) === '') {
                continue;
            }

            $parsedRows[] = str_getcsv($row);
        }

        if ($parsedRows === []) {
            throw new RuntimeException('The CSV file was empty.');
        }

        return $this->tabularPayload($parsedRows, $originalFilename);
    }

    /**
     * @return array{markdown:string,summary:string,content_json:?array<string,mixed>,structured_data_workspace_path:?string}
     */
    private function parseDocx(string $path, string $originalFilename): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The DOCX file could not be opened.');
        }

        $documentXml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($documentXml) || trim($documentXml) === '') {
            throw new RuntimeException('The DOCX file did not contain readable text.');
        }

        $paragraphs = [];
        $xml = @simplexml_load_string($documentXml);

        if ($xml !== false) {
            $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $nodes = $xml->xpath('//w:p');

            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $textNodes = $node->xpath('.//w:t');
                    $parts = [];

                    foreach (is_array($textNodes) ? $textNodes : [] as $textNode) {
                        $parts[] = trim((string) $textNode);
                    }

                    $paragraph = trim(implode(' ', array_filter($parts, static fn (string $part): bool => $part !== '')));

                    if ($paragraph !== '') {
                        $paragraphs[] = $paragraph;
                    }
                }
            }
        }

        $text = trim(implode("\n\n", $paragraphs));

        if ($text === '') {
            throw new RuntimeException('The DOCX file did not contain readable text.');
        }

        return [
            'markdown' => "# {$originalFilename}\n\n".$text."\n",
            'summary' => $this->buildSummary($text, sprintf('Imported document content from %s.', $originalFilename)),
            'content_json' => null,
            'structured_data_workspace_path' => null,
        ];
    }

    /**
     * @return array{markdown:string,summary:string,content_json:array<string,mixed>,structured_data_workspace_path:string}
     */
    private function parseXlsx(string $path, string $originalFilename): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The spreadsheet could not be opened.');
        }

        $sharedStrings = [];
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');

        if (is_string($sharedStringsXml) && trim($sharedStringsXml) !== '') {
            $sharedXml = @simplexml_load_string($sharedStringsXml);

            if ($sharedXml !== false && isset($sharedXml->si)) {
                foreach ($sharedXml->si as $item) {
                    $text = '';

                    if (isset($item->t)) {
                        $text = (string) $item->t;
                    } elseif (isset($item->r)) {
                        foreach ($item->r as $run) {
                            $text .= (string) ($run->t ?? '');
                        }
                    }

                    $sharedStrings[] = trim($text);
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        if (! is_string($sheetXml) || trim($sheetXml) === '') {
            throw new RuntimeException('The spreadsheet did not contain a readable first sheet.');
        }

        $xml = @simplexml_load_string($sheetXml);

        if ($xml === false || ! isset($xml->sheetData)) {
            throw new RuntimeException('The spreadsheet could not be parsed.');
        }

        $rows = [];

        foreach ($xml->sheetData->row as $rowNode) {
            $cells = [];

            foreach ($rowNode->c as $cell) {
                $reference = (string) ($cell['r'] ?? '');
                $column = preg_replace('/\d+/', '', $reference) ?: 'A';
                $index = $this->columnIndex($column);
                $type = (string) ($cell['t'] ?? '');
                $value = isset($cell->v) ? (string) $cell->v : '';

                if ($type === 's') {
                    $value = $sharedStrings[(int) $value] ?? '';
                }

                $cells[$index] = trim($value);
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $lastIndex = max(array_keys($cells));
            $row = [];

            for ($index = 0; $index <= $lastIndex; $index++) {
                $row[] = $cells[$index] ?? '';
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            throw new RuntimeException('The spreadsheet did not contain any readable rows.');
        }

        return $this->tabularPayload($rows, $originalFilename);
    }

    /**
     * @return array{markdown:string,summary:string,content_json:?array<string,mixed>,structured_data_workspace_path:?string}
     */
    private function parsePdf(string $contents, string $originalFilename): array
    {
        $text = $this->extractPdfText($contents);

        if ($text === '') {
            $text = 'A PDF document was uploaded, but Sync360 could not safely extract readable text from it. Use the filename and summary as a clue, and escalate if an exact document reading is required.';
        }

        return [
            'markdown' => "# {$originalFilename}\n\n".$text."\n",
            'summary' => $this->buildSummary($text, sprintf('Imported PDF reference from %s.', $originalFilename)),
            'content_json' => null,
            'structured_data_workspace_path' => null,
        ];
    }

    /**
     * @param  array<int, array<int, string>>  $rows
     * @return array{markdown:string,summary:string,content_json:array<string,mixed>,structured_data_workspace_path:string}
     */
    private function tabularPayload(array $rows, string $originalFilename): array
    {
        $header = array_map(
            static fn (mixed $value): string => trim((string) $value) !== '' ? trim((string) $value) : 'Column',
            $rows[0]
        );
        $dataRows = array_slice($rows, 1);
        $normalizedRows = [];

        foreach ($dataRows as $row) {
            $normalizedRow = [];

            foreach ($header as $index => $column) {
                $columnKey = $column !== '' ? $column : 'Column '.($index + 1);
                $normalizedRow[$columnKey] = trim((string) ($row[$index] ?? ''));
            }

            if (collect($normalizedRow)->filter(static fn (string $value): bool => $value !== '')->isNotEmpty()) {
                $normalizedRows[] = $normalizedRow;
            }
        }

        $previewRows = array_slice($normalizedRows, 0, 25);
        $markdown = [
            '# '.$originalFilename,
            '',
            sprintf('Imported %d row%s and %d column%s.', count($normalizedRows), count($normalizedRows) === 1 ? '' : 's', count($header), count($header) === 1 ? '' : 's'),
            '',
            '| '.implode(' | ', $header).' |',
            '| '.implode(' | ', array_fill(0, count($header), '---')).' |',
        ];

        foreach ($previewRows as $row) {
            $markdown[] = '| '.implode(' | ', array_map(
                static fn (string $value): string => str_replace('|', '\\|', $value),
                array_values($row)
            )).' |';
        }

        if (count($normalizedRows) > count($previewRows)) {
            $markdown[] = '';
            $markdown[] = sprintf('_Showing the first %d rows. Use the structured data payload for the full imported sheet._', count($previewRows));
        }

        return [
            'markdown' => implode("\n", $markdown)."\n",
            'summary' => sprintf('Imported %d row%s from %s.', count($normalizedRows), count($normalizedRows) === 1 ? '' : 's', $originalFilename),
            'content_json' => [
                'columns' => $header,
                'rows' => $normalizedRows,
            ],
            'structured_data_workspace_path' => '',
        ];
    }

    private function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function buildSummary(string $content, string $fallback): string
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $content) ?? $content);

        if ($trimmed === '') {
            return $fallback;
        }

        return mb_substr($trimmed, 0, 180).(mb_strlen($trimmed) > 180 ? '…' : '');
    }

    private function extractPdfText(string $contents): string
    {
        preg_match_all('/stream(.*?)endstream/s', $contents, $matches);
        $chunks = [];

        foreach ($matches[1] ?? [] as $rawChunk) {
            $chunk = ltrim((string) $rawChunk, "\r\n");
            $decoded = @gzuncompress($chunk);

            if (! is_string($decoded) || $decoded === '') {
                $decoded = $chunk;
            }

            preg_match_all('/\((.*?)\)/s', $decoded, $stringMatches);

            foreach ($stringMatches[1] ?? [] as $value) {
                $clean = trim((string) preg_replace('/\\\\([nrtbf()\\\\])/', ' ', $value));

                if ($clean !== '') {
                    $chunks[] = $clean;
                }
            }
        }

        $text = trim(preg_replace('/\s+/', ' ', implode(' ', $chunks)) ?? implode(' ', $chunks));

        return mb_substr($text, 0, 12000);
    }
}
