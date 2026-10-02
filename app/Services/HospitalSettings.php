<?php

namespace App\Services;

use App\Models\Hospital;
use App\Models\HospitalIntegrationCredential;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use RuntimeException;

class HospitalSettings
{
    private const COLUMN_MAP = [
        'deposit_amount' => 'deposit_amount',
        'slot_duration' => 'slot_duration_minutes',
        'slot_duration_minutes' => 'slot_duration_minutes',
        'auto_confirm_paid_appointments' => 'auto_confirm_paid_appointments',
        'business_hours' => 'business_hours',
        'notification_preferences' => 'notification_preferences',
    ];

    private const PROVIDERS = [
        HospitalIntegrationCredential::PROVIDER_MPESA,
        HospitalIntegrationCredential::PROVIDER_WHATSAPP,
        HospitalIntegrationCredential::PROVIDER_SMS,
        HospitalIntegrationCredential::PROVIDER_EMAIL,
    ];

    private ?Hospital $hospital = null;

    public function for(Hospital $hospital): static
    {
        $this->hospital = $hospital;

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $hospital = $this->freshHospital();

        if (isset(self::COLUMN_MAP[$key])) {
            $value = $hospital->getAttribute(self::COLUMN_MAP[$key]);
            if ($value !== null) {
                return $value;
            }
        }

        foreach (['notification_preferences', 'business_hours'] as $jsonColumn) {
            if ($key === $jsonColumn) {
                $value = $hospital->getAttribute($jsonColumn);
            } elseif (str_starts_with($key, $jsonColumn.'.')) {
                $value = data_get($hospital->getAttribute($jsonColumn), substr($key, strlen($jsonColumn) + 1));
            } else {
                continue;
            }

            if ($value !== null) {
                return $value;
            }
        }

        $value = config('pearlie.'.$key);
        if ($value !== null) {
            return $value;
        }

        $value = config('mpesa.'.$key);

        return $value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->boundHospital();

        if (! isset(self::COLUMN_MAP[$key])) {
            throw new InvalidArgumentException("The [{$key}] setting cannot be stored as a hospital column.");
        }

        $this->freshHospital()->update([
            self::COLUMN_MAP[$key] => $value,
        ]);
    }

    public function has(string $key): bool
    {
        $missing = new \stdClass;

        return $this->get($key, $missing) !== $missing;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function credential(string $provider): ?array
    {
        $credential = $this->credentialRelation()
            ->where('provider', $provider)
            ->first();

        return $credential?->credentials;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function setCredential(string $provider, array $credentials): void
    {
        $this->boundHospital();

        if (! in_array($provider, self::PROVIDERS, true)) {
            throw new InvalidArgumentException("The [{$provider}] integration provider is not supported.");
        }

        Model::withoutEvents(function () use ($provider, $credentials): void {
            $this->credentialRelation()->updateOrCreate(
                ['provider' => $provider],
                [
                    'credentials' => $credentials,
                    'is_active' => true,
                ],
            );
        });
    }

    public function deleteCredential(string $provider): void
    {
        $this->credentialRelation()
            ->where('provider', $provider)
            ->delete();
    }

    private function credentialRelation(): HasMany
    {
        return $this->boundHospital()
            ->integrationCredentials()
            ->withoutGlobalScope('hospital');
    }

    private function freshHospital(): Hospital
    {
        $hospital = $this->boundHospital();

        return Hospital::query()->findOrFail($hospital->getKey());
    }

    private function boundHospital(): Hospital
    {
        if (! $this->hospital) {
            throw new RuntimeException('No hospital bound. Call for() first.');
        }

        return $this->hospital;
    }
}
