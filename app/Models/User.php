<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Traits\BelongsToHospital;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'hospital_id',
    'name',
    'email',
    'password',
    'role',
    'is_active',
    'is_admin',
    'is_super_admin',
    'is_doctor',
    'specialization',
    'phone',
    'bio',
    'consultation_fee',
    'licence_number',
    'must_change_password',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = [
        'is_active' => true,
    ];

    /** @use HasFactory<UserFactory> */
    use BelongsToHospital, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'is_doctor' => 'boolean',
            'is_super_admin' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'consultation_fee' => 'decimal:2',
        ];
    }

    use HasRoles;

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class, 'doctor_id');
    }

    public function unavailableDates(): HasMany
    {
        return $this->hasMany(DoctorUnavailableDate::class, 'doctor_id');
    }

    public function doctorAppointments(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class, 'doctor_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(AppointmentRequest::class, 'patient_id');
    }

    public function doctorInvites(): HasMany
    {
        return $this->hasMany(DoctorInvite::class, 'doctor_id');
    }

    public function isDoctor(): bool
    {
        return (bool) $this->is_doctor || $this->role === 'doctor';
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin || in_array($this->role, ['hospital_admin', 'super_admin'], true)
            || $this->hasRole('admin');
    }

    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin || $this->role === 'super_admin';
    }

    public function syncRolesFromFlags(): void
    {
        $roles = [];

        if ($this->is_super_admin) {
            $roles[] = 'super_admin';
        }
        if ($this->is_admin && ! $this->is_super_admin) {
            $roles[] = 'hospital_admin';
        }
        if ($this->is_doctor) {
            $roles[] = 'doctor';
        }
        if ($roles === []) {
            $roles[] = 'patient';
        }

        $roleRecords = array_map(
            static fn (string $role): Role => Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]),
            $roles,
        );

        $this->syncRoles($roleRecords);
    }
}
