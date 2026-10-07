<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\InviteMail;
use App\Models\Invite;
use App\Services\HospitalMailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    public function index()
    {
        $invites = Invite::orderBy('created_at', 'desc')->paginate(20);

        return view('admin.invites.index', compact('invites'));
    }

    public function store(Request $request, HospitalMailService $mail)
    {
        $request->validate(['email' => 'required|email', 'expires_in_days' => 'nullable|integer|min:1|max:365']);

        $token = Str::random(32);
        $inviteData = [
            'email' => $request->input('email'),
            'token' => $token,
            'created_by' => $request->user()->id,
        ];

        if ($days = $request->input('expires_in_days')) {
            $inviteData['expires_at'] = now()->addDays((int) $days);
        }

        $invite = Invite::create($inviteData);

        // Send invite email and do not expose token in the UI
        try {
            $mail->queue(
                $invite->email,
                new InviteMail($token, hospital()?->slug),
            );

            return redirect()->back()->with('status', 'Invite created and emailed.');
        } catch (\Throwable $e) {
            Log::error('Failed to send invite email: '.$e->getMessage());

            return redirect()->back()->with('error', 'Invite created but failed to send email.');
        }
    }

    public function destroy(int $id)
    {
        $invite = Invite::findOrFail($id);
        $invite->delete();

        return redirect()->back()->with('status', 'Invite deleted.');
    }

    public function revoke(int $id)
    {
        $invite = Invite::findOrFail($id);
        $invite->expires_at = now();
        $invite->save();

        return redirect()->back()->with('status', 'Invite revoked (expired).');
    }
}
