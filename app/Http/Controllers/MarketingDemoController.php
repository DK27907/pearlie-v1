<?php

namespace App\Http\Controllers;

use App\Mail\MarketingDemoRequest;
use App\Services\HospitalMailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MarketingDemoController extends Controller
{
    public function store(Request $request, HospitalMailService $mail): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'organization' => ['required', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);

        $mail->send(
            config('pearlie.marketing.sales_email'),
            (new MarketingDemoRequest($validated))->replyTo($validated['email'], $validated['name']),
        );

        return redirect()->to(route('home').'#demo')->with('demo_request_sent', true);
    }
}
