<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RestrictMpesaCallbackToSafaricom
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('mpesa.enforce_safaricom_ip_allowlist', true)) {
            return $next($request);
        }

        if (in_array(
            app()->environment(),
            config('mpesa.allowlist_bypass_environments', []),
            true,
        )) {
            Log::debug('Safaricom callback IP allowlist bypassed for environment.', [
                'environment' => app()->environment(),
                'path' => $request->path(),
            ]);

            return $next($request);
        }

        $ip = $request->ip();
        foreach (config('mpesa.safaricom_ip_allowlist', []) as $range) {
            if (is_string($ip) && is_string($range) && $this->matchesCidr($ip, $range)) {
                return $next($request);
            }
        }

        $checkoutRequestId = $request->input('Body.stkCallback.CheckoutRequestID');
        Log::warning('Rejected M-Pesa callback from an untrusted source IP.', [
            'ip' => $ip,
            'path' => $request->path(),
            'user_agent' => $request->userAgent(),
            'checkout_request_id' => is_string($checkoutRequestId) ? $checkoutRequestId : null,
        ]);

        return response()->json([
            'ResultCode' => 1,
            'ResultDesc' => 'Unauthorized source',
        ], Response::HTTP_FORBIDDEN);
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);
        $network = inet_pton($parts[0]);
        $address = inet_pton($ip);

        if ($network === false || $address === false || strlen($network) !== strlen($address)) {
            return false;
        }

        $prefixLength = isset($parts[1]) ? filter_var($parts[1], FILTER_VALIDATE_INT) : strlen($network) * 8;
        if ($prefixLength === false || $prefixLength < 0 || $prefixLength > strlen($network) * 8) {
            return false;
        }

        $wholeBytes = intdiv($prefixLength, 8);
        $remainingBits = $prefixLength % 8;

        if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
