<?php

namespace App\Support;

use Illuminate\Support\Str;

final class FuzzyMatcher
{
    public static function similarityPercentage(string $left, string $right): float
    {
        $left = Str::ascii(Str::lower(trim($left)));
        $right = Str::ascii(Str::lower(trim($right)));

        if ($left === '' || $right === '' || strlen($left) > 255 || strlen($right) > 255) {
            return 0;
        }

        $distance = levenshtein($left, $right);
        if ($distance > 1 && self::isSingleAdjacentTransposition($left, $right)) {
            $distance = 1;
        }

        return (1 - ($distance / max(strlen($left), strlen($right)))) * 100;
    }

    private static function isSingleAdjacentTransposition(string $left, string $right): bool
    {
        if (strlen($left) !== strlen($right)) {
            return false;
        }

        for ($index = 0; $index < strlen($left) - 1; $index++) {
            $transposed = $left;
            $transposed[$index] = $left[$index + 1];
            $transposed[$index + 1] = $left[$index];

            if ($transposed === $right) {
                return true;
            }
        }

        return false;
    }
}
