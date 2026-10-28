<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 12px;background-color:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:28px 32px;background-color:#0a2f44;color:#ffffff">
                            <p style="margin:0;color:#a5f3fc;font-size:12px;font-weight:700;letter-spacing:2px">MEDIDESK ASSISTANT</p>
                            <h1 style="margin:14px 0 0;font-size:24px;line-height:1.3">You’re invited to {{ $hospitalName }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px">
                            <p style="margin:0 0 16px;font-size:16px">Hello,</p>
                            <p style="margin:0 0 20px;color:#334155;font-size:15px;line-height:1.6">You have been invited to join {{ $hospitalName }} on MediDesk Assistant. Use the following invite token when registering (or click the link to register):</p>
                            <p style="margin:0 0 20px;border:1px solid #bae6fd;border-radius:10px;background-color:#f0f9ff;padding:14px;color:#0c4a6e;font-size:18px;font-weight:700;letter-spacing:1px">{{ $token }}</p>
                            <p style="margin:0 0 20px;font-size:14px">Register here: <a href="{{ $registrationUrl }}" style="color:#0e7490;word-break:break-all">{{ $registrationUrl }}</a></p>
                            <p style="margin:0;color:#64748b;font-size:14px;line-height:1.6">If you did not expect this invitation, ignore this message.</p>
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
