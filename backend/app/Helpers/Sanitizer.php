<?php

declare(strict_types=1);

namespace App\Helpers;

class Sanitizer
{
    public static function string(?string $input, int $maxLength = 255): string
    {
        if ($input === null) return '';
        $clean = trim($input);
        return mb_substr($clean, 0, $maxLength);
    }

    public static function int(mixed $input, int $default = 0): int
    {
        return filter_var($input, FILTER_VALIDATE_INT) !== false ? (int)$input : $default;
    }

    public static function float(mixed $input, float $default = 0.0): float
    {
        return filter_var($input, FILTER_VALIDATE_FLOAT) !== false ? (float)$input : $default;
    }

    public static function email(?string $input): ?string
    {
        if (!$input) return null;
        $clean = filter_var(trim($input), FILTER_VALIDATE_EMAIL);
        return $clean !== false ? strtolower($clean) : null;
    }

    public static function date(?string $input): ?string
    {
        if (!$input) return null;
        $d = \DateTime::createFromFormat('Y-m-d', trim($input));
        return $d && $d->format('Y-m-d') === trim($input) ? trim($input) : null;
    }
}
