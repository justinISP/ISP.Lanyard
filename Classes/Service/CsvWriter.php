<?php
declare(strict_types=1);

namespace ISP\Lanyard\Service;

use Neos\Flow\Annotations as Flow;

/**
 * Baut CSV-Dateien für die Exporte im Backend.
 *
 * Trennzeichen und BOM kommen aus ISP.Lanyard.backend.csv – mit BOM
 * erkennt Excel UTF-8 und zeigt Umlaute richtig.
 */
#[Flow\Scope('singleton')]
class CsvWriter
{
    #[Flow\InjectConfiguration(path: 'backend.csv', package: 'ISP.Lanyard')]
    protected array $settings = [];

    /**
     * @param array<int, string> $header
     * @param iterable<array<int, scalar|null>> $rows
     */
    public function write(array $header, iterable $rows): string
    {
        $delimiter = (string)($this->settings['delimiter'] ?? ';');
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $header, $delimiter);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(static fn($value): string => (string)$value, $row), $delimiter);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return (($this->settings['includeBom'] ?? true) ? "\xEF\xBB\xBF" : '') . $csv;
    }
}
