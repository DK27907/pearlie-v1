<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Escalation extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'user_message',
        'ai_response',
        'status',
        'assigned_to',
    ];
}
