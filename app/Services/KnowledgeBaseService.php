<?php

namespace App\Services;

use App\Models\KnowledgeBase;
use Illuminate\Support\Str;

class KnowledgeBaseService
{
    public function search(string $query): ?string
    {
        $normalizedQuery = $this->normalize($query);
        if ($normalizedQuery === '') {
            return null;
        }

        if ($this->isSwahiliEmergencyIntent($normalizedQuery)) {
            $emergencyPhone = hospital()?->emergency_phone
                ?? pearlie_config('hospital.emergency_phone');

            return $emergencyPhone
                ? 'Kwa dharura, piga '.$emergencyPhone.' sasa. Ninakuunganisha na mhudumu wa afya.'
                : 'Kwa dharura, tafadhali wasiliana na timu ya dharura ya hospitali sasa. Ninakuunganisha na mhudumu wa afya.';
        }

        $queryWords = preg_split('/[^\pL\pN]+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $entries = KnowledgeBase::query()->get();

        $greetingMatch = $this->bestGreetingMatch($normalizedQuery, $queryWords, $entries);
        if ($greetingMatch !== null) {
            return $greetingMatch->answer;
        }

        $emergencyMatch = $this->bestEmergencyMatch($normalizedQuery, $queryWords, $entries);
        if ($emergencyMatch !== null) {
            return $emergencyMatch->answer;
        }

        if ($this->isEmergencyIntent($normalizedQuery)) {
            $hospital = hospital();
            $emergencyPhone = $hospital
                ? $hospital->emergency_phone
                : pearlie_config('hospital.emergency_phone');

            return $emergencyPhone
                ? 'For an emergency, call '.$emergencyPhone.' now.'
                : 'For an emergency, contact the hospital emergency team immediately.';
        }

        $serviceMatch = $this->bestServiceMatch($normalizedQuery, $queryWords, $entries);
        if ($serviceMatch !== null) {
            return $serviceMatch->answer;
        }

        $bestMatch = $this->rankMatches($normalizedQuery, $queryWords, $entries)[0]['entry'] ?? null;

        return $bestMatch?->answer;
    }

    public function getRelevantContext(string $query): string
    {
        $normalizedQuery = $this->normalize($query);
        $queryWords = preg_split('/[^\pL\pN]+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = $this->rankMatches($normalizedQuery, $queryWords, KnowledgeBase::query()->get());

        if ($matches === []) {
            $hospital = hospital();
            $details = $hospital
                ? array_filter([$hospital->name, $hospital->address, $hospital->phone])
                : array_filter([
                    pearlie_config('hospital.name', 'our hospital'),
                    pearlie_config('hospital.location'),
                    pearlie_config('hospital.appointment_phone'),
                ]);

            return implode("\n", $details);
        }

        return implode("\n\n", array_map(
            fn (array $match): string => $match['entry']->category.': '.$match['entry']->answer,
            array_slice($matches, 0, 5),
        ));
    }

    public function getServiceContext(): string
    {
        $services = KnowledgeBase::query()
            ->where('category', 'services')
            ->whereNotIn('subcategory', ['services_overview', 'general', 'complete-service-list-swahili'])
            ->orderBy('subcategory')
            ->get(['subcategory', 'answer']);

        if ($services->isEmpty()) {
            return 'No service information has been configured in this hospital knowledge base.';
        }

        return $services
            ->map(fn (KnowledgeBase $service): string => '- '.$service->subcategory.': '.$service->answer)
            ->implode("\n");
    }

    private function rankMatches(string $query, array $queryWords, $entries): array
    {
        $matches = [];

        foreach ($entries as $entry) {
            $score = $this->score($query, $queryWords, $entry);
            if ($score > 0) {
                $matches[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $matches;
    }

    private function bestGreetingMatch(string $query, array $queryWords, $entries): ?KnowledgeBase
    {
        if ($this->isEmergencyIntent($query)
            || $this->isGeneralServicesQuery($query)
            || $this->hasSpecificServiceIntent($query, $entries)
        ) {
            return null;
        }

        $greetingEntries = $entries->filter(fn (KnowledgeBase $entry): bool => $this->isGreetingEntry($entry));

        if ($greetingEntries->isEmpty()) {
            return null;
        }

        $matchingEntries = $greetingEntries->filter(function (KnowledgeBase $entry) use ($query, $queryWords): bool {
            return $this->greetingKeywordStrength($entry, $query, $queryWords) > 0;
        });

        if ($matchingEntries->isEmpty()) {
            return null;
        }

        return $this->pickBestEntry($query, $queryWords, $matchingEntries->all());
    }

    private function bestEmergencyMatch(string $query, array $queryWords, $entries): ?KnowledgeBase
    {
        if (! $this->isEmergencyIntent($query)) {
            return null;
        }

        $emergencyEntries = $entries->filter(fn (KnowledgeBase $entry): bool => $this->isEmergencyEntry($entry));

        return $emergencyEntries->isEmpty()
            ? null
            : $this->pickBestEntry($query, $queryWords, $emergencyEntries->all());
    }

    private function bestServiceMatch(string $query, array $queryWords, $entries): ?KnowledgeBase
    {
        $serviceEntries = $entries->filter(fn (KnowledgeBase $entry): bool => $this->isServiceEntry($entry));

        if ($serviceEntries->isEmpty()) {
            return null;
        }

        $specificEntries = $serviceEntries->filter(function (KnowledgeBase $entry) use ($query): bool {
            return ! $this->isServiceOverviewEntry($entry)
                && $this->serviceKeywordStrength($entry, $query) > 0;
        });

        if ($specificEntries->isNotEmpty()) {
            return $this->pickBestEntry($query, $queryWords, $specificEntries->all());
        }

        if ($this->isGeneralServicesQuery($query)) {
            $overviewEntries = $serviceEntries->filter(fn (KnowledgeBase $entry): bool => $this->isServiceOverviewEntry($entry));
            if ($overviewEntries->isNotEmpty()) {
                return $this->pickBestEntry($query, $queryWords, $overviewEntries->all());
            }
        }

        return null;
    }

    private function pickBestEntry(string $query, array $queryWords, array $entries): KnowledgeBase
    {
        $matches = [];

        foreach ($entries as $entry) {
            $score = $this->score($query, $queryWords, $entry);
            if ($score > 0) {
                $matches[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $matches[0]['entry'];
    }

    private function score(string $query, array $queryWords, KnowledgeBase $entry): int
    {
        $keywords = is_array($entry->keywords)
            ? $entry->keywords
            : (json_decode((string) $entry->keywords, true) ?: []);
        $score = 0;

        foreach ($keywords as $keyword) {
            $keyword = $this->normalize((string) $keyword);
            if ($keyword === '') {
                continue;
            }

            if ($this->containsPhrase($query, $keyword)) {
                $score += 100 + (mb_strlen($keyword) * 10);

                continue;
            }

            foreach ($queryWords as $queryWord) {
                if ($queryWord === $keyword) {
                    $score += 60 + mb_strlen($keyword);
                }
            }
        }

        $question = $this->normalize((string) $entry->question);
        if ($question !== '' && $this->containsPhrase($query, $question)) {
            $score += 60;
        }

        if ($this->isServiceOverviewEntry($entry) && $this->isGeneralServicesQuery($query)) {
            $score += 250;
        }

        if ($this->isServiceEntry($entry) && $this->isSpecificServiceQuery($query, $entry)) {
            $score += 300;
        }

        if ($this->isServiceOverviewEntry($entry) && $this->looksSwahili($query) && $this->looksSwahili((string) $entry->question)) {
            $score += 200;
        }

        if ($this->isServiceOverviewEntry($entry) && $this->looksEnglish($query) && $this->looksEnglish((string) $entry->question)) {
            $score += 200;
        }

        return $score;
    }

    private function isGreetingEntry(KnowledgeBase $entry): bool
    {
        return mb_strtolower((string) $entry->category) === 'greeting';
    }

    private function greetingKeywordStrength(KnowledgeBase $entry, string $query, array $queryWords): int
    {
        $keywords = is_array($entry->keywords)
            ? $entry->keywords
            : (json_decode((string) $entry->keywords, true) ?: []);
        $strength = 0;

        foreach ($keywords as $keyword) {
            $keyword = $this->normalize((string) $keyword);
            if ($keyword === '') {
                continue;
            }

            if ($this->containsPhrase($query, $keyword)) {
                $strength += 1000 + (mb_strlen($keyword) * 20);
            }

            foreach ($queryWords as $queryWord) {
                if ($queryWord === $keyword) {
                    $strength += 800 + mb_strlen($keyword);
                }
            }
        }

        if ($this->isGreetingEntry($entry) && $this->looksSwahili($query) && $this->looksSwahili((string) $entry->question)) {
            $strength += 500;
        }

        return $strength;
    }

    private function isServiceEntry(KnowledgeBase $entry): bool
    {
        return mb_strtolower((string) $entry->category) === 'services';
    }

    private function isEmergencyEntry(KnowledgeBase $entry): bool
    {
        $subcategory = mb_strtolower((string) ($entry->subcategory ?? ''));
        $keywords = is_array($entry->keywords)
            ? $entry->keywords
            : (json_decode((string) $entry->keywords, true) ?: []);

        return mb_strtolower((string) $entry->category) === 'emergency'
            || str_contains($subcategory, 'emergency')
            || str_contains($subcategory, 'dharura')
            || collect($keywords)->contains(fn (mixed $keyword): bool => (bool) preg_match(
                '/\b(emergency|dharura|urgent|ambulance)\b/i',
                (string) $keyword,
            ));
    }

    private function isEmergencyIntent(string $query): bool
    {
        return (bool) preg_match(
            '/\b(emergency|dharura|haraka|urgent(?:ly)?|ambulance|accident|ajali|bleeding|damu nyingi|chest pain|maumivu ya kifua|can[\'’]?t breathe|shida kupumua|kiharusi|nimeumia vibaya)\b/i',
            $query,
        );
    }

    private function isSwahiliEmergencyIntent(string $query): bool
    {
        return (bool) preg_match(
            '/\b(maumivu ya kifua|shida kupumua|damu nyingi|kiharusi|dharura|ajali|nimeumia vibaya)\b/i',
            $query,
        );
    }

    private function isServiceOverviewEntry(KnowledgeBase $entry): bool
    {
        $subcategory = mb_strtolower((string) ($entry->subcategory ?? ''));
        $question = mb_strtolower((string) ($entry->question ?? ''));
        $keywords = is_array($entry->keywords)
            ? $entry->keywords
            : (json_decode((string) $entry->keywords, true) ?: []);

        foreach ($keywords as $keyword) {
            $normalized = $this->normalize((string) $keyword);
            if ($normalized === '') {
                continue;
            }

            if (str_contains($normalized, 'service') || str_contains($normalized, 'huduma') || str_contains($normalized, 'offer') || str_contains($normalized, 'zipo')) {
                return true;
            }
        }

        return $subcategory === 'services_overview'
            || $subcategory === 'overview'
            || str_contains($question, 'what services do you offer')
            || str_contains($question, 'what services are available')
            || str_contains($question, 'hospitali inatoa huduma gani')
            || str_contains($question, 'huduma');
    }

    private function isGeneralServicesQuery(string $query): bool
    {
        $normalized = mb_strtolower($query);

        return (bool) preg_match(
            '/\b(?:what|show|list|tell|give)\b.*\b(?:services?|huduma)\b|\b(?:services?|huduma)\b.*\b(?:offer|offered|available|list|zipo|ni zipi)\b/i',
            $normalized,
        );
    }

    private function isSpecificServiceQuery(string $query, KnowledgeBase $entry): bool
    {
        return $this->serviceKeywordStrength($entry, $query) > 0;
    }

    private function hasSpecificServiceIntent(string $query, $entries): bool
    {
        return $entries->contains(function (KnowledgeBase $entry) use ($query): bool {
            return $this->isServiceEntry($entry)
                && ! $this->isServiceOverviewEntry($entry)
                && $this->serviceKeywordStrength($entry, $query) > 0;
        });
    }

    private function serviceKeywordStrength(KnowledgeBase $entry, string $query): int
    {
        $keywords = is_array($entry->keywords)
            ? $entry->keywords
            : (json_decode((string) $entry->keywords, true) ?: []);
        $strength = 0;

        foreach ($keywords as $keyword) {
            $keyword = $this->normalize((string) $keyword);
            if ($keyword === '') {
                continue;
            }

            if ($this->containsPhrase($query, $keyword)) {
                $strength += 100 + (mb_strlen($keyword) * 10);
            }
        }

        return $strength;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        return (bool) preg_match(
            '/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u',
            $text,
        );
    }

    private function looksSwahili(string $value): bool
    {
        return (bool) preg_match(
            '/\b(habari|huduma|miadi|daktari|wapi|dharura|jina|simu|namba|tafadhali|asante|karibu|kwaheri|sasa|vipi|sijambo|hujambo|jambo)\b/i',
            $value,
        );
    }

    private function looksEnglish(string $value): bool
    {
        return (bool) preg_match(
            '/\b(services|service|appointment|doctor|hospital|help|book|contact|appointment|phone|email|emergency)\b/i',
            $value,
        );
    }

    private function normalize(string $value): string
    {
        return trim((string) preg_replace(
            '/\s+/u',
            ' ',
            Str::ascii(Str::lower($value)),
        ));
    }
}
