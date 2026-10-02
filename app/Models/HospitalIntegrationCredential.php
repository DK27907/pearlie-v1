<?php

namespace App\Models;

use App\Traits\BelongsToHospital;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HospitalIntegrationCredential extends Model
{
    use BelongsToHospital, HasFactory;

    public const PROVIDER_MPESA = 'mpesa';

    public const PROVIDER_WHATSAPP = 'whatsapp';

    public const PROVIDER_SMS = 'sms';

    public const PROVIDER_EMAIL = 'email';

    protected $fillable = [
        'provider',
        'credentials',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'is_active' => 'boolean',
        ];
    }
}
