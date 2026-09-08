<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Pearlie Escalation Notification</title>
</head>
<body>
    <h2>New Pearlie Escalation (#{{ $escalation->id }})</h2>
    <p><strong>Session ID:</strong> {{ $escalation->session_id }}</p>
    <p><strong>Submitted:</strong> {{ $escalation->created_at }}</p>

    <h3>User message</h3>
    <p>{{ $escalation->user_message }}</p>

    @if(!empty($meta['user_name']) || !empty($meta['user_phone']))
        <h3>User details</h3>
        <p><strong>Name:</strong> {{ $meta['user_name'] ?? 'N/A' }}</p>
        <p><strong>Phone:</strong> {{ $meta['user_phone'] ?? 'N/A' }}</p>
    @endif

    @if(isset($meta['confidence_score']))
        <p><strong>AI confidence:</strong> {{ number_format($meta['confidence_score'], 2) }}</p>
    @endif

    <p>Please follow up with the patient as soon as possible.</p>

    <p>— Pearlie Assistant</p>
</body>
</html>