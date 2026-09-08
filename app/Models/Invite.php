<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Invite extends Model
{
    use HasFactory;

    protected $fillable = ['email', 'token', 'created_by', 'used_at', 'expires_at', 'used_by'];

    protected $casts = [
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function scopeValid($query)
    {
        return $query->whereNull('used_at')
            ->whereNull('used_by')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function markUsed($userId = null): void
    {
        $this->used_at = now();
        if ($userId) $this->used_by = $userId;
        $this->save();
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }
}
