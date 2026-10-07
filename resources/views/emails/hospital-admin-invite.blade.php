<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:32px;color:#0f172a">
    <p style="font-size:12px;font-weight:700;letter-spacing:2px;color:#4f46e5">MEDIDESK AI</p>
    <h1 style="font-size:26px">Welcome to MediDesk AI</h1>
    <p>You have been invited to set up the hospital administrator account for <strong>{{ $hospital->name }}</strong>.</p>
    <p style="margin:28px 0"><a href="{{ $inviteUrl }}" style="background:#4338ca;color:#fff;padding:14px 20px;border-radius:8px;text-decoration:none;font-weight:bold">Set up administrator account</a></p>
    <p>This invitation expires in seven days and can only be used once.</p>
    <p style="font-size:12px;color:#64748b;word-break:break-all">{{ $inviteUrl }}</p>
</div>
