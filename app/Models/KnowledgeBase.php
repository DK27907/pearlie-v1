<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeBase extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'category',
        'subcategory',
        'keywords',
        'question',
        'answer',
        'source',
        'last_updated',
        'approved_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'keywords' => 'array',
        'last_updated' => 'date',
    ];

    /**
     * Get the category label with an emoji.
     */
    public function getCategoryLabelAttribute(): string
    {
        $labels = [
            'location' => '📍 Location & Contact',
            'services' => '🏥 Services Offered',
            'appointments' => '📅 Appointments',
            'hours' => '🕐 Operating Hours',
            'doctors' => '👨‍⚕️ Medical Staff',
            'payment' => '💰 Payment & Insurance',
            'emergency' => '🚨 Emergency Services',
            'visiting' => '🏨 Visiting Hours',
            'amenities' => '🏨 Amenities',
            'resources' => '📋 Patient Resources',
            'greeting' => '👋 Greetings',
            'general' => '💡 General Information',
        ];

        return $labels[$this->category] ?? ucfirst($this->category);
    }

    /**
     * Get the keywords as a readable string.
     */
    public function getKeywordsStringAttribute(): string
    {
        if (is_array($this->keywords)) {
            return implode(', ', $this->keywords);
        }

        return $this->keywords ?? '';
    }

    /**
     * Get the answer with source attribution.
     */
    public function getFormattedAnswerAttribute(): string
    {
        $answer = $this->answer;

        if ($this->source) {
            $answer .= "\n\n_Source: " . $this->source;
        }

        if ($this->last_updated) {
            $answer .= " (Last updated: " . $this->last_updated->format('M d, Y') . ")_";
        }

        return $answer;
    }

    /**
     * Scope a query to only include entries of a given category.
     */
    public function scopeCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Scope a query to search by keywords.
     */
    public function scopeSearchByKeywords($query, $searchTerm)
    {
        $words = explode(' ', strtolower($searchTerm));

        return $query->where(function ($q) use ($words) {
            foreach ($words as $word) {
                $q->orWhere('keywords', 'LIKE', '%' . $word . '%');
            }
        });
    }

    /**
     * Check if the entry matches a given search query.
     */
    public function matchesSearch(string $query): bool
    {
        $query = strtolower($query);
        $keywords = is_array($this->keywords)
            ? implode(' ', $this->keywords)
            : $this->keywords;

        $searchableText = strtolower(
            $this->question . ' ' .
            $keywords . ' ' .
            $this->answer . ' ' .
            $this->category . ' ' .
            $this->subcategory
        );

        $words = explode(' ', $query);

        foreach ($words as $word) {
            if (str_contains($searchableText, $word)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get a formatted string for display.
     */
    public function toDisplayString(): string
    {
        return $this->answer;
    }

    /**
     * Get the most recent entries.
     */
    public static function getRecent($limit = 10)
    {
        return self::orderBy('last_updated', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get all categories with count.
     */
    public static function getCategoriesWithCount(): array
    {
        return self::select('category')
            ->selectRaw('count(*) as total')
            ->groupBy('category')
            ->pluck('total', 'category')
            ->toArray();
    }
}