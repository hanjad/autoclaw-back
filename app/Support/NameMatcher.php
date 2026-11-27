<?php

namespace App\Support;

use Illuminate\Support\Str;

// Rough "is this the same person/business?" check between two names (order and punctuation ignored).
// It only flags for the human reviewer; it never approves anything on its own.
class NameMatcher
{
    public static function matches(?string $a, ?string $b): bool
    {
        $ta = self::tokens((string) $a);
        $tb = self::tokens((string) $b);

        if (!$ta || !$tb) {
            return false;
        }

        $overlap = count(array_intersect($ta, $tb));

        return $overlap >= min(2, count($ta), count($tb));
    }

    private static function tokens(string $value): array
    {
        $generic = [
            'LTD', 'LIMITED', 'NIG', 'NIGERIA', 'NIGERIAN', 'ENTERPRISES', 'ENTERPRISE', 'AND', 'CO',
            'COMPANY', 'PLC', 'INC', 'THE', 'OF', 'VENTURES', 'GLOBAL', 'SERVICES', 'AUTO', 'PARTS',
            'MOTORS', 'STORES', 'STORE', 'TRADING',
        ];

        $value = Str::upper(Str::ascii($value));
        $value = preg_replace('/[^A-Z0-9 ]+/', ' ', $value);

        $tokens = array_filter(
            preg_split('/\s+/', trim($value)),
            fn ($t) => strlen($t) > 1 && !in_array($t, $generic, true)
        );

        return array_values(array_unique($tokens));
    }
}
