<?php

namespace App\Services;

class ChatLanguageDetector
{
    private const SWAHILI_WORDS = [
        'asante', 'badilisha', 'baadaye', 'bei', 'daktari', 'dawa', 'dharura',
        'gani', 'ghairi', 'habari', 'hapana', 'hii', 'hiyo', 'homa', 'hujambo',
        'jambo', 'jina', 'kesho', 'kufuta', 'kwa', 'kwanini', 'la', 'leo', 'maumivu',
        'mambo', 'marahaba', 'mgonjwa', 'miadi', 'mko', 'mnatoa', 'msaada', 'na',
        'naomba', 'nashukuru', 'nataka', 'ndiyo', 'nina', 'ninataka', 'nini', 'nita', 'nitakuja',
        'nisaidie', 'nzuri', 'poa', 'salama', 'samahani', 'sasa', 'shikamoo',
        'shukran', 'sijui',         'simu', 'tafadhali', 'tunatoa', 'tunapatikana', 'weka', 'kuweka',
        'vipi', 'wa', 'wapi', 'ya', 'yako', 'za',
    ];

    private const ENGLISH_WORDS = [
        'a', 'about', 'and', 'appointment', 'are', 'book', 'can', 'cost', 'dental',
        'doctor', 'do', 'help', 'hello', 'hey', 'hi', 'how', 'i', 'is', 'located',
        'my', 'need', 'no', 'offer', 'phone', 'please', 'price', 'services', 'thank',
        'thanks', 'the', 'what', 'when', 'where', 'which', 'you',
    ];

    private const STRONG_SWAHILI_CUES = [
        'asante', 'habari', 'hujambo', 'jambo', 'naomba', 'nataka',
        'nisaidie', 'ninataka', 'mko', 'mnatoa', 'nashukuru', 'shikamoo',
        'shukran',
    ];

    private const STRONG_ENGLISH_CUES = [
        'bye', 'goodbye', 'hello', 'hey', 'hi', 'thank', 'thanks',
    ];

    public function detectLanguage(string $message): string
    {
        [$swahiliScore, $englishScore] = $this->scores($message);

        return $swahiliScore > $englishScore ? 'sw' : 'en';
    }

    public function hasConfidentLanguageSignal(string $message): bool
    {
        [$swahiliScore, $englishScore] = $this->scores($message);
        preg_match_all('/[\pL\pN]+/u', mb_strtolower(trim($message)), $matches);
        $tokens = $matches[0];
        $swahiliIndicators = array_intersect($tokens, self::SWAHILI_WORDS);
        $englishIndicators = array_intersect($tokens, self::ENGLISH_WORDS);
        $isSingleWordCue = count($tokens) === 1
            && (in_array($tokens[0], self::STRONG_SWAHILI_CUES, true)
                || in_array($tokens[0], self::STRONG_ENGLISH_CUES, true));

        $winningSignalCount = $swahiliScore > $englishScore
            ? count($swahiliIndicators)
            : count($englishIndicators);

        return $isSingleWordCue || (count($tokens) >= 2
            && abs($swahiliScore - $englishScore) >= 2
            && $winningSignalCount >= 2);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scores(string $message): array
    {
        preg_match_all('/[\pL\pN]+/u', mb_strtolower(trim($message)), $matches);
        $tokens = $matches[0];
        $swahiliWords = array_intersect($tokens, self::SWAHILI_WORDS);
        $englishWords = array_intersect($tokens, self::ENGLISH_WORDS);
        $strongSwahiliCues = array_intersect($tokens, self::STRONG_SWAHILI_CUES);

        return [
            count($swahiliWords) * 3 + count($strongSwahiliCues) * 6,
            count($englishWords) * 2,
        ];
    }
}
