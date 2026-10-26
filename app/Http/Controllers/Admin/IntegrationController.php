<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Services\WebhookUrlGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

class IntegrationController extends Controller
{
    private const WEBHOOK_EVENTS = [
        'appointment.created',
        'appointment.updated',
    ];

    public function index(): View
    {
        $hospital = $this->currentHospital();

        return view('admin.integrations.index', [
            'tokens' => $hospital->apiTokens()->latest('id')->get(),
            'subscriptions' => $hospital->webhookSubscriptions()
                ->with(['deliveries' => fn ($query) => $query->latest('id')->limit(3)])
                ->latest('id')
                ->get(),
            'events' => self::WEBHOOK_EVENTS,
            'createdApiToken' => session()->pull('created_api_token'),
            'createdWebhookSecret' => session()->pull('created_webhook_secret'),
        ]);
    }

    public function storeToken(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);
        $plainTextToken = 'pvl_'.bin2hex(random_bytes(32));
        $this->currentHospital()->apiTokens()->create([
            'name' => $validated['name'],
            'token_hash' => hash('sha256', $plainTextToken),
        ]);

        return to_route('admin.integrations.index')
            ->with('created_api_token', $plainTextToken);
    }

    public function destroyToken(int $tokenId): RedirectResponse
    {
        $token = $this->currentHospital()->apiTokens()
            ->whereKey($tokenId)
            ->whereNull('revoked_at')
            ->firstOrFail();
        $token->forceFill(['revoked_at' => now()])->save();

        return to_route('admin.integrations.index')->with('status', 'API token revoked.');
    }

    public function storeWebhook(Request $request, WebhookUrlGuard $urlGuard): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1', 'max:2'],
            'events.*' => ['required', 'string', 'distinct', Rule::in(self::WEBHOOK_EVENTS)],
        ]);

        try {
            $urlGuard->resolvePublicAddresses($validated['url']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'url' => $exception->getMessage(),
            ]);
        }

        $secret = 'whsec_'.bin2hex(random_bytes(32));
        $this->currentHospital()->webhookSubscriptions()->create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values($validated['events']),
            'is_active' => true,
        ]);

        return to_route('admin.integrations.index')
            ->with('created_webhook_secret', $secret);
    }

    public function destroyWebhook(int $subscriptionId): RedirectResponse
    {
        $subscription = $this->currentHospital()->webhookSubscriptions()
            ->whereKey($subscriptionId)
            ->firstOrFail();
        $subscription->forceFill(['is_active' => false])->save();

        return to_route('admin.integrations.index')->with('status', 'Webhook subscription disabled.');
    }

    private function currentHospital(): Hospital
    {
        $hospital = hospital();
        abort_unless($hospital instanceof Hospital, 404);

        return $hospital;
    }
}
