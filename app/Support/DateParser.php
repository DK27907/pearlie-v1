<?php

namespace App\Support;

use Carbon\CarbonImmutable;

class DateParser
{
    private const SWAHILI_WEEKDAYS = [
        'jumatatu' => 'monday',
        'jumanne' => 'tuesday',
        'jumatano' => 'wednesday',
        'alhamisi' => 'thursday',
        'ijumaa' => 'friday',
        'jumamosi' => 'saturday',
        'jumapili' => 'sunday',
    ];

    private const SWAHILI_MONTHS = [
        'januari' => 'January',
        'februari' => 'February',
        'machi' => 'March',
        'aprili' => 'April',
        'mei' => 'May',
        'juni' => 'June',
        'julai' => 'July',
        'agosti' => 'August',
        'septemba' => 'September',
        'oktoba' => 'October',
        'novemba' => 'November',
        'desemba' => 'December',
    ];

    public static function parse(string $input): ?CarbonImmutable
    {
        $input = mb_strtolower(trim($input));

        if (preg_match('/\b(today|leo)\b/u', $input)) {
            return CarbonImmutable::today();
        }

        if (preg_match('/\b(tomorrow|kesho)\b/u', $input)) {
            return CarbonImmutable::tomorrow();
        }

        if (preg_match('/\b(next week|wiki ijayo)\b/u', $input)) {
            return CarbonImmutable::today()->addWeek();
        }

        $weekdays = self::SWAHILI_WEEKDAYS + array_fill_keys([
            'monday',
            'tuesday',
            'wednesday',
            'thursday',
            'friday',
            'saturday',
            'sunday',
        ], '');

        foreach ($weekdays as $day => $englishDay) {
            $weekday = $englishDay !== '' ? $englishDay : $day;

            if (preg_match('/\b(?:next\s+)?'.preg_quote($day, '/').'\b/u', $input)) {
                return CarbonImmutable::today()->next($weekday);
            }
        }

        $cleaned = preg_replace('/(\d+)(st|nd|rd|th)\b/i', '$1', $input) ?? $input;
        $cleaned = preg_replace('/\b(ya|wa|mwaka)\b/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace_callback(
            '/\b('.implode('|', array_keys(self::SWAHILI_MONTHS)).')\b/u',
            static fn (array $matches): string => self::SWAHILI_MONTHS[$matches[1]],
            $cleaned,
        ) ?? $cleaned;
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);

        if (preg_match('/\b(\d{4})-(\d{2})-(\d{2})\b/', $cleaned, $matches)) {
            return self::createDate('!Y-m-d', $matches[0]);
        }

        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{4})\b/', $cleaned, $matches)) {
            return self::createDate('!d/m/Y', $matches[0]);
        }

        if (preg_match('/\b(\d{1,2})-(\d{1,2})-(\d{4})\b/', $cleaned, $matches)) {
            return self::createDate('!d-m-Y', $matches[0]);
        }

        if (preg_match('/\b(\d{1,2})[\s-]+([a-z]+)[,\s-]+(\d{4})\b/i', $cleaned, $matches)) {
            $month = $matches[2];
            $format = strlen($month) <= 3 ? '!j M Y' : '!j F Y';

            return self::createDate($format, $matches[0]);
        }

        if (preg_match('/\b([a-z]+)\s+(\d{1,2}),?\s+(\d{4})\b/i', $cleaned, $matches)) {
            $month = $matches[1];
            $format = strlen($month) <= 3 ? '!M j Y' : '!F j Y';
            $dateValue = str_replace(',', '', $matches[0]);

            return self::createDate($format, $dateValue);
        }

        return null;
    }

    private static function createDate(string $format, string $value): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat($format, $value);
        $errors = CarbonImmutable::getLastErrors();

        if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date;
    }
}
