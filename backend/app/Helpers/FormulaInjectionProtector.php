<?php

declare(strict_types=1);

namespace App\Helpers;

class FormulaInjectionProtector
{
    private static array $dangerousPrefixes = ['=', '+', '-', '@', "\t", "\r"];

    public static function escapeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        $str = (string) $value;
        if ($str === '') {
            return '';
        }
        $firstChar = $str[0];
        if (in_array($firstChar, self::$dangerousPrefixes, true)) {
            return "'" . $str;
        }
        return $str;
    }

    public static function buildCsv(array $headers, array $rows): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return '';
        }

        // Write escaped headers
        $cleanHeaders = array_map([self::class, 'escapeCell'], $headers);
        fputcsv($fp, $cleanHeaders);

        // Write rows
        foreach ($rows as $row) {
            $cleanRow = array_map([self::class, 'escapeCell'], array_values($row));
            fputcsv($fp, $cleanRow);
        }

        rewind($fp);
        $csv = stream_get_contents($fp);
        fclose($fp);

        return $csv ?: '';
    }
}
