<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediDesk Assistant Doctor Dashboard invitation</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 12px;background-color:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;overflow:hidden;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:28px 32px;background-color:#0a2f44;color:#ffffff">
                            <div style="font-size:12px;font-weight:700;letter-spacing:2px;color:#a5f3fc">{{ mb_strtoupper(pearlie_config('hospital.name')) }}</div>
                            <h1 style="margin:14px 0 0;font-size:24px;line-height:1.3">MediDesk Assistant Doctor Dashboard invitation</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px">
                            <p style="margin:0 0 16px;font-size:16px">Hello {{ $doctor->name }},</p>
                            <p style="font-size:15px;line-height:1.6;color:#334155">You have been invited to access your hospital’s MediDesk Assistant doctor workspace. Set a password to activate your account and manage your schedule and appointments.</p>
                            <p style="margin:28px 0">
                                <a href="{{ $setupUrl }}" style="display:inline-block;padding:14px 22px;border-radius:10px;background-color:#0e7490;color:#ffffff;text-decoration:none;font-weight:bold">Set Up My Account</a>
                            </p>
                            <p style="font-size:14px;line-height:1.6;color:#475569">This invitation expires on {{ $expiresAt->timezone(config('app.timezone'))->format('F j, Y \a\t g:i A T') }}. The link can only be used once.</p>
                            <p style="font-size:13px;line-height:1.6;color:#64748b">If the button does not work, copy and paste this link into your browser:<br><a href="{{ $setupUrl }}" style="color:#0e7490;word-break:break-all">{{ $setupUrl }}</a></p>
                            <p style="margin:24px 0 0;font-size:14px;color:#475569">{{ pearlie_config('hospital.name') }}</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="border-top:1px solid #e2e8f0;padding:18px 32px;color:#64748b;font-size:12px">MediDesk Assistant · AxiomForge Digital Solutions</td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
