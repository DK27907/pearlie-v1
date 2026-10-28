<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New MediDesk Assistant demo request</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 12px;background-color:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:26px 32px;background-color:#0a2f44;color:#ffffff">
                            <p style="margin:0;color:#a5f3fc;font-size:12px;font-weight:700;letter-spacing:2px">MEDIDESK ASSISTANT</p>
                            <h1 style="margin:12px 0 0;font-size:24px;line-height:1.3">New demo request</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
                                <tr><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#64748b;font-size:13px">Name</td><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#0f172a;font-size:14px;font-weight:700">{{ $request['name'] }}</td></tr>
                                <tr><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#64748b;font-size:13px">Work email</td><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#0f172a;font-size:14px">{{ $request['email'] }}</td></tr>
                                <tr><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#64748b;font-size:13px">Hospital or organization</td><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#0f172a;font-size:14px">{{ $request['organization'] }}</td></tr>
                                <tr><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#64748b;font-size:13px">Phone</td><td style="border-bottom:1px solid #e2e8f0;padding:12px 0;color:#0f172a;font-size:14px">{{ $request['phone'] ?: 'Not provided' }}</td></tr>
                            </table>
                            <h2 style="margin:24px 0 8px;font-size:15px">Message</h2>
                            <p style="margin:0;border-radius:10px;background-color:#f8fafc;padding:14px;color:#334155;font-size:14px;line-height:1.6">{{ $request['message'] ?: 'No additional message provided.' }}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
