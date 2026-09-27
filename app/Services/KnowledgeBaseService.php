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

        $queryWords = preg_split('/[^\pL\pN]+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = [];

        foreach (KnowledgeBase::query()->get() as $entry) {
            $score = $this->score($normalizedQuery, $queryWords, $entry);
            if ($score > 0) {
                $matches[] = ['score' => $score, 'entry' => $entry];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        return $matches[0]['entry']->answer ?? null;
    }

    public function getRelevantContext(string $query): string
    {
        $normalizedQuery = $this->normalize($query);
        $queryWords = preg_split('/[^\pL\pN]+/u', $normalizedQuery, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = [];

        foreach (KnowledgeBase::query()->get() as $entry) {
            $score = $this->score($normalizedQuery, $queryWords, $entry);
            if ($score > 0) {
                $matches[] = ['score' => $score, 'context' => $entry->category.': '.$entry->answer];
            }
        }

        usort($matches, static fn (array $left, array $right): int => $right['score'] <=> $left['score']);

        if ($matches === []) {
            return sprintf(
                '%s is located at %s. For help, call %s.',
                pearlie_config('hospital.name'),
                pearlie_config('hospital.location'),
                pearlie_config('hospital.appointment_phone'),
            );
        }

        return implode("\n\n", array_column(array_slice($matches, 0, 5), 'context'));
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
                $score += 10 + mb_strlen($keyword);
                continue;
            }

            foreach ($queryWords as $queryWord) {
                if ($queryWord === $keyword) {
                    $score += 5;
                } elseif (mb_strlen($keyword) >= 5
                    && (str_contains($queryWord, $keyword) || str_contains($keyword, $queryWord))
                ) {
                    $score += 2;
                }
            }
        }

        $question = $this->normalize((string) $entry->question);
        if ($question !== '' && $this->containsPhrase($query, $question)) {
            $score += 8;
        }

        return $score;
    }

    private function containsPhrase(string $text, string $phrase): bool
    {
        return (bool) preg_match(
            '/(?<![\pL\pN])'.preg_quote($phrase, '/').'(?![\pL\pN])/u',
            $text,
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
