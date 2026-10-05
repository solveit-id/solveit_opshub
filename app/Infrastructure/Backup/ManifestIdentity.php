<?php

namespace App\Infrastructure\Backup;

/** MySQL JSON changes object-key order; compare typed values while preserving list order. */
class ManifestIdentity
{
    public static function equal(array $left, array $right): bool
    {
        return hash_equals(self::digest($left), self::digest($right));
    }

    private static function digest(array $value): string
    {
        $normalize = function (array $array) use (&$normalize): array {
            if (! array_is_list($array)) {
                ksort($array);
            }
            foreach ($array as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }
            unset($item);

            return $array;
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }
}
