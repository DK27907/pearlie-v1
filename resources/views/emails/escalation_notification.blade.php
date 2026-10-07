<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>MediDesk AI escalation notification</title>
</head>
<body>
    <h2>New MediDesk AI escalation (#{{ $escalation->id }})</h2>
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
    <p><a href="{{ $meta['url'] ?? route('admin.escalations.show', $escalation->id) }}">Open escalation #{{ $escalation->id }}</a></p>

    <p>— MediDesk AI Health Assistant</p>
</body>
</html>