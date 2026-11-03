<?php

namespace App\Services;

use InvalidArgumentException;

final class UciSerializer
{
    public static function quote(string|int $value): string
    {
        $value = (string) $value;
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('UCI values cannot contain control characters.');
        }

        return "'".str_replace("'", "'\\''", $value)."'";
    }

    public static function section(string $type, ?string $name = null): string
    {
        self::identifier($type);

        return 'config '.$type.($name === null ? '' : ' '.self::quote($name));
    }

    public static function entry(string $kind, string $key, string|int $value): string
    {
        if (! in_array($kind, ['option', 'list'], true)) {
            throw new InvalidArgumentException('Unsupported UCI entry.');
        }
        self::identifier($key);

        return '    '.$kind.' '.$key.' '.self::quote($value);
    }

    private static function identifier(string $value): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
            throw new InvalidArgumentException('Invalid UCI identifier.');
        }
    }
}
