<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your {{ $hospital->name }} admin account is ready</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="padding:32px 12px;background:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" style="max-width:600px;background:#fff;border-radius:16px">
                    <tr>
                        <td style="padding:32px">
                            <p style="font-size:12px;font-weight:bold;letter-spacing:2px;color:#4f46e5">MEDIDESK ASSISTANT</p>
                            <h1 style="font-size:24px">Your {{ $hospital->name }} admin account is ready</h1>
                            <p>Hello {{ $admin->name }},</p>
                            <p>Your administrator account for {{ $hospital->name }} is ready.</p>
                            <p>Email: <strong>{{ $admin->email }}</strong></p>
                            <p>Login URL: <a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>
                            <p>Initial password: <code>{{ $initialPassword }}</code></p>
                            <p><strong>Change this password immediately on first login — do not share this email.</strong></p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
