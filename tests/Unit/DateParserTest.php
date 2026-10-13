<?php

namespace Tests\Unit;

use App\Support\DateParser;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DateParserTest extends TestCase
{
    public function test_parses_supported_calendar_date_formats(): void
    {
        foreach ([
            '11th October 2026',
            '11 Oct 2026',
            'October 11th, 2026',
            '11 Oktoba 2026',
            '11/10/2026',
            '2026-10-11',
        ] as $input) {
            $this->assertSame('2026-10-11', DateParser::parse($input)?->toDateString());
        }
    }

    public function test_rejects_unrecognized_date_text(): void
    {
        $this->assertNull(DateParser::parse('sometime nextish'));
        $this->assertNull(DateParser::parse('31st February 2026'));
    }

    public function test_parses_supported_relative_dates_and_swahili_weekdays(): void
    {
        foreach (['today', 'leo', 'tomorrow', 'kesho', 'next week', 'wiki ijayo', 'Monday', 'Jumatatu'] as $input) {
            $this->assertNotNull(DateParser::parse($input), $input);
        }
    }

    public function test_parses_a_weekday_and_month_day_without_a_year(): void
    {
        CarbonImmutable::setTestNow('2025-10-01');

        try {
            $this->assertSame('2025-11-07', DateParser::parse('Friday, November 7')?->toDateString());
            $this->assertNull(DateParser::parse('Monday, November 7'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
