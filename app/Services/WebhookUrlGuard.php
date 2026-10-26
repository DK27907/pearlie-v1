<?php

namespace App\Services;

use InvalidArgumentException;

class WebhookUrlGuard
{
    /**
     * @return array<int, string>
     */
    public function resolvePublicAddresses(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? mb_strtolower(rtrim((string) ($parts['host'] ?? ''), '.')) : '';
        if (! is_array($parts)
            || mb_strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! str_contains($host, '.')
            || preg_match('/\.(?:localhost|local|internal|test|invalid|example)$/i', $host) === 1
            || filter_var($host, FILTER_VALIDATE_IP) !== false
        ) {
            throw new InvalidArgumentException('The webhook URL must use a public HTTPS hostname on port 443.');
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            throw new InvalidArgumentException('The webhook hostname did not resolve to a public address.');
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (! is_string($address)) {
                continue;
            }

            if (filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
                throw new InvalidArgumentException('The webhook hostname resolves to a private or reserved address.');
            }

            $addresses[] = $address;
        }

        if ($addresses === []) {
            throw new InvalidArgumentException('The webhook hostname did not resolve to a public address.');
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param  array<int, string>  $addresses
     * @return array<int, string>
     */
    public function curlResolveEntries(string $url, array $addresses): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $formattedAddresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
            $addresses,
        );

        return [$host.':443:'.implode(',', $formattedAddresses)];
    }
}
