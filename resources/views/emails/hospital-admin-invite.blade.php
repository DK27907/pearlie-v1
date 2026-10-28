<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 12px">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;overflow:hidden;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:28px 32px;background-color:#0a2f44;color:#ffffff">
                            <p style="margin:0;color:#a5f3fc;font-size:12px;font-weight:700;letter-spacing:2px">MEDIDESK ASSISTANT</p>
                            <h1 style="margin:14px 0 0;font-size:26px;line-height:1.25">Welcome to MediDesk Assistant</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.65">You have been invited to set up the hospital administrator account for <strong>{{ $hospital->name }}</strong>.</p>
                            <p style="margin:28px 0">
                                <a href="{{ $inviteUrl }}" style="display:inline-block;border-radius:10px;background-color:#0e7490;padding:14px 20px;color:#ffffff;font-size:15px;font-weight:700;text-decoration:none">Set up administrator account</a>
                            </p>
                            <p style="margin:0 0 12px;color:#475569;font-size:14px;line-height:1.6">This invitation expires in seven days and can only be used once.</p>
                            <p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;word-break:break-all">{{ $inviteUrl }}</p>
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
