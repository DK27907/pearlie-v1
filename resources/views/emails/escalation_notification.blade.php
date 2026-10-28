<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>MediDesk Assistant escalation notification</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="padding:32px 12px;background-color:#f1f5f9">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:600px;border:1px solid #e2e8f0;border-radius:20px;background-color:#ffffff">
                    <tr>
                        <td style="padding:26px 32px;background-color:#0a2f44;color:#ffffff">
                            <p style="margin:0;color:#a5f3fc;font-size:12px;font-weight:700;letter-spacing:2px">MEDIDESK ASSISTANT</p>
                            <h1 style="margin:12px 0 0;font-size:22px;line-height:1.35">New escalation #{{ $escalation->id }}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:28px 32px">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;background-color:#f8fafc">
                                <tr>
                                    <td style="padding:12px 14px;color:#475569;font-size:13px;line-height:1.6"><strong>Session ID:</strong> {{ $escalation->session_id }}<br><strong>Submitted:</strong> {{ $escalation->created_at }}</td>
                                </tr>
                            </table>
                            <h2 style="margin:0 0 8px;color:#0f172a;font-size:16px">Patient message</h2>
                            <p style="margin:0 0 22px;border-left:4px solid #0891b2;background-color:#f0f9ff;padding:14px 16px;color:#334155;font-size:15px;line-height:1.65">{{ $escalation->user_message }}</p>
                            @if(!empty($meta['user_name']) || !empty($meta['user_phone']))
                                <h2 style="margin:0 0 8px;color:#0f172a;font-size:16px">Patient details</h2>
                                <p style="margin:0 0 18px;color:#475569;font-size:14px;line-height:1.7"><strong>Name:</strong> {{ $meta['user_name'] ?? 'N/A' }}<br><strong>Phone:</strong> {{ $meta['user_phone'] ?? 'N/A' }}</p>
                            @endif
                            @if(isset($meta['confidence_score']))
                                <p style="margin:0 0 18px;color:#475569;font-size:14px"><strong>AI confidence:</strong> {{ number_format($meta['confidence_score'], 2) }}</p>
                            @endif
                            <p style="margin:0 0 22px;color:#334155;font-size:14px;line-height:1.6">Please follow up with the patient as soon as possible.</p>
                            <p style="margin:0 0 20px"><a href="{{ $meta['url'] ?? route('admin.escalations.show', $escalation->id) }}" style="display:inline-block;border-radius:10px;background-color:#0e7490;padding:13px 20px;color:#ffffff;font-size:14px;font-weight:700;text-decoration:none">Open escalation #{{ $escalation->id }}</a></p>
                            <p style="margin:0;color:#64748b;font-size:13px">MediDesk Assistant Health Assistant</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>