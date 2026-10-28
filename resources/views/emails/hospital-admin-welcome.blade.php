<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your {{ $hospital->name }} admin account is ready</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 12px;background-color:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;overflow:hidden;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:28px 32px;background-color:#0a2f44;color:#ffffff">
                            <p style="margin:0;color:#a5f3fc;font-size:12px;font-weight:700;letter-spacing:2px">MEDIDESK ASSISTANT</p>
                            <h1 style="margin:14px 0 0;font-size:24px;line-height:1.3">Your {{ $hospital->name }} admin account is ready</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.6">Hello {{ $admin->name }},</p>
                            <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.6">Your administrator account for {{ $hospital->name }} is ready.</p>
                            <p style="margin:0 0 12px;color:#334155;font-size:14px">Email: <strong>{{ $admin->email }}</strong></p>
                            <p style="margin:0 0 12px;color:#334155;font-size:14px">Login URL: <a href="{{ $loginUrl }}" style="color:#0e7490;word-break:break-all">{{ $loginUrl }}</a></p>
                            <p style="margin:0 0 20px;color:#334155;font-size:14px">Initial password: <code style="border-radius:6px;background-color:#f1f5f9;padding:4px 7px;font-family:monospace">{{ $initialPassword }}</code></p>
                            <p style="margin:0;border-left:4px solid #f59e0b;background-color:#fffbeb;padding:14px 16px;color:#78350f;font-size:14px;line-height:1.6"><strong>Change this password immediately on first login — do not share this email.</strong></p>
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
