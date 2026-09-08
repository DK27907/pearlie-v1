<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Invite;
use Illuminate\Support\Str;

class InviteController extends Controller
{
    public function index()
    {
        $invites = Invite::orderBy('created_at', 'desc')->paginate(20);
        return view('admin.invites.index', compact('invites'));
    }

    public function store(Request $request)
    {
        $request->validate(['email' => 'required|email', 'expires_in_days' => 'nullable|integer|min:1|max:365']);

        $token = Str::random(32);
        $inviteData = [
            'email' => $request->input('email'),
            'token' => $token,
            'created_by' => $request->user()->id,
        ];

        if ($days = $request->input('expires_in_days')) {
            $inviteData['expires_at'] = now()->addDays((int)$days);
        }

        $invite = Invite::create($inviteData);

        // Send invite email and do not expose token in the UI
        try {
            \Illuminate\Support\Facades\Mail::to($invite->email)->queue(new \App\Mail\InviteMail($token));
            return redirect()->back()->with('status', 'Invite created and emailed.');
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send invite email: ' . $e->getMessage());
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
