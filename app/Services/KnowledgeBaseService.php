<?php

namespace App\Services;

use App\Models\KnowledgeBase;

class KnowledgeBaseService
{
    public function search(string $query): ?string
    {
        $query = strtolower(trim($query));
        $queryWords = array_map('trim', explode(' ', $query));
        
        // Remove short words
        $queryWords = array_filter($queryWords, function($word) {
            return strlen($word) >= 4;
        });
        
        $entries = KnowledgeBase::all();
        $bestMatch = null;
        $bestMatchCount = 0;
        
        foreach ($entries as $entry) {
            $keywords = is_array($entry->keywords) 
                ? $entry->keywords 
                : json_decode($entry->keywords, true) ?? [];
            
            $keywords = array_map('strtolower', $keywords);
            $keywords = array_filter($keywords, function($w) {
                return strlen($w) >= 4;
            });
            
            $matchCount = 0;
            foreach ($queryWords as $queryWord) {
                foreach ($keywords as $keyword) {
                    if ($queryWord === $keyword) {
                        $matchCount += 2;
                        break;
                    }
                    if (str_contains($queryWord, $keyword) || str_contains($keyword, $queryWord)) {
                        $matchCount += 1;
                        break;
                    }
                }
            }
            
            if (str_contains($query, strtolower($entry->question))) {
                $matchCount += 1;
            }
            
            if ($matchCount > $bestMatchCount) {
                $bestMatchCount = $matchCount;
                $bestMatch = $entry;
            }
        }
        
        return ($bestMatchCount > 0) ? $bestMatch->answer : null;
    }

    public function getRelevantContext(string $query): string
    {
        $entries = KnowledgeBase::all();
        $context = [];
        $queryWords = array_filter(array_map('trim', explode(' ', strtolower($query))), function($w) {
            return strlen($w) >= 4;
        });
        
        foreach ($entries as $entry) {
            $keywords = is_array($entry->keywords) 
                ? $entry->keywords 
                : json_decode($entry->keywords, true) ?? [];
            
            $keywords = array_map('strtolower', $keywords);
            $keywords = array_filter($keywords, function($w) {
                return strlen($w) >= 4;
            });
            
            foreach ($queryWords as $queryWord) {
                foreach ($keywords as $keyword) {
                    if ($queryWord === $keyword || 
                        str_contains($queryWord, $keyword) || 
                        str_contains($keyword, $queryWord)) {
                        $context[] = $entry->category . ': ' . $entry->answer;
                        break 3;
                    }
                }
            }
        }
        
        if (empty($context)) {
            return "Pearl Hospital is located at Vin Plaza, Nyahururu. They offer emergency, outpatient, inpatient, and specialist services. They can be reached at 0700000000.";
        }
        
        return implode("\n\n", $context);
    }
}