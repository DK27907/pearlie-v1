<?php

namespace App\Services;

use App\Exceptions\MissingProviderCredentialsException;
use App\Models\Hospital;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Log;

class ProviderCredentialResolver
{
    public function __construct(
        private readonly HospitalSettings $hospitalSettings,
    ) {}

    /**
     * @param  array<string, mixed>  $fallbackConfig
     * @param  array<string, string>  $legacyAttributeMap
     * @param  array<int, string>  $requiredCredentials
     * @return array<string, mixed>
     */
    public function resolve(
        Hospital $hospital,
        string $provider,
        array $fallbackConfig,
        array $legacyAttributeMap,
        array $requiredCredentials,
    ): array {
        try {
            $tenantCredentials = $this->hospitalSettings
                ->for($hospital)
                ->credential(mb_strtolower(str_replace('-', '', $provider))) ?? [];

            foreach ($legacyAttributeMap as $key => $attribute) {
                if (! filled($tenantCredentials[$key] ?? null)
                    && filled($hospital->getAttribute($attribute))
                ) {
                    $tenantCredentials[$key] = $hospital->getAttribute($attribute);
                }
            }
        } catch (DecryptException $exception) {
            Log::error('Stored provider credentials could not be decrypted.', [
                'provider' => $provider,
                'hospital_slug' => $hospital->slug,
                'exception_type' => $exception::class,
            ]);

            throw MissingProviderCredentialsException::forHospital(
                $hospital,
                $provider,
                $requiredCredentials,
            );
        }

        $missingCredentials = array_values(array_filter(
            $requiredCredentials,
            fn (string $key): bool => blank($tenantCredentials[$key] ?? null),
        ));

        if ($missingCredentials !== [] && ! config('integrations.allow_global_fallback', false)) {
            throw MissingProviderCredentialsException::forHospital(
                $hospital,
                $provider,
                $missingCredentials,
            );
        }

        return array_replace($fallbackConfig, $tenantCredentials);
    }
}
