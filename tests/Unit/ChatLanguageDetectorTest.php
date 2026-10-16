<?php

namespace Tests\Unit;

use App\Services\ChatLanguageDetector;
use PHPUnit\Framework\TestCase;

class ChatLanguageDetectorTest extends TestCase
{
    private ChatLanguageDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->detector = new ChatLanguageDetector;
    }

    public function test_detects_swahili_from_common_messages(): void
    {
        $this->assertSame('sw', $this->detector->detectLanguage('Habari yako leo'));
        $this->assertSame('sw', $this->detector->detectLanguage('Nataka kuweka miadi'));
        $this->assertSame('sw', $this->detector->detectLanguage('Bei ya kuchunguza meno ni ngapi'));
        $this->assertSame('sw', $this->detector->detectLanguage('Nina maumivu makali'));
        $this->assertSame('sw', $this->detector->detectLanguage('Nisaidie'));
    }

    public function test_detects_english_from_common_messages(): void
    {
        $this->assertSame('en', $this->detector->detectLanguage('Hello, I need help'));
        $this->assertSame('en', $this->detector->detectLanguage('What services do you offer?'));
        $this->assertSame('en', $this->detector->detectLanguage('Book a dental appointment'));
    }

    public function test_empty_message_defaults_to_english(): void
    {
        $this->assertSame('en', $this->detector->detectLanguage(''));
        $this->assertSame('en', $this->detector->detectLanguage('   '));
    }

    public function test_swahili_evidence_outweighs_english_in_mixed_message(): void
    {
        $this->assertSame('sw', $this->detector->detectLanguage('Nataka appointment ya kesho'));
    }

    public function test_only_confident_language_evidence_is_reported(): void
    {
        $this->assertTrue($this->detector->hasConfidentLanguageSignal('Habari yako'));
        $this->assertTrue($this->detector->hasConfidentLanguageSignal('What services do you offer'));
        $this->assertFalse($this->detector->hasConfidentLanguageSignal('Dental'));
    }
}
