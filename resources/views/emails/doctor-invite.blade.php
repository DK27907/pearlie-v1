<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediDesk AI Doctor Dashboard invitation</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px;background:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-radius:16px;overflow:hidden">
                    <tr>
                        <td style="padding:28px 32px;background:#082f49;color:#fff">
                            <div style="font-size:13px;font-weight:bold;letter-spacing:2px;color:#67e8f9">{{ mb_strtoupper(pearlie_config('hospital.name')) }}</div>
                            <h1 style="margin:12px 0 0;font-size:24px">MediDesk AI Doctor Dashboard invitation</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px">
                            <p style="font-size:16px">Hello {{ $doctor->name }},</p>
                            <p style="font-size:15px;line-height:1.6;color:#334155">You have been invited to access your hospital’s MediDesk AI doctor workspace. Set a password to activate your account and manage your schedule and appointments.</p>
                            <p style="margin:28px 0">
                                <a href="{{ $setupUrl }}" style="display:inline-block;padding:14px 22px;border-radius:8px;background:#0e7490;color:#fff;text-decoration:none;font-weight:bold">Set Up My Account</a>
                            </p>
                            <p style="font-size:14px;line-height:1.6;color:#475569">This invitation expires on {{ $expiresAt->timezone(config('app.timezone'))->format('F j, Y \a\t g:i A T') }}. The link can only be used once.</p>
                            <p style="font-size:13px;line-height:1.6;color:#64748b">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $setupUrl }}" style="color:#0e7490;word-break:break-all">{{ $setupUrl }}</a></p>
                            <p style="font-size:14px;color:#475569">{{ pearlie_config('hospital.name') }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
