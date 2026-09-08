<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Conversation extends Model
{
    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'confidence_score',
        'channel',
        'escalated',
    ];

    protected $casts = [
        'confidence_score' => 'float',
        'escalated' => 'boolean',
    ];
}