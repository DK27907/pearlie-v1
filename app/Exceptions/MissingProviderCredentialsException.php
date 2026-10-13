<?php

namespace App\Exceptions;

use App\Models\Hospital;
use RuntimeException;

class MissingProviderCredentialsException extends RuntimeException
{
    /**
     * @param  array<int, string>  $missingCredentials
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $hospitalSlug,
        public readonly array $missingCredentials,
    ) {
        $missing = $missingCredentials === [] ? '' : ' Missing: '.implode(', ', $missingCredentials).'.';

        parent::__construct(
            "{$provider} is not configured for hospital [{$hospitalSlug}]. An admin must enter credentials in the hospital settings."
            .$missing,
        );
    }

    /**
     * @param  array<int, string>  $missingCredentials
     */
    public static function forHospital(
        Hospital $hospital,
        string $provider,
        array $missingCredentials,
    ): self {
        return new self($provider, (string) $hospital->slug, $missingCredentials);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'hospital_slug' => $this->hospitalSlug,
            'missing_credentials' => $this->missingCredentials,
        ];
    }
}
