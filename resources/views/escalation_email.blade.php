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

    @if($escalation->ai_response)
        <h3>AI response</h3>
        <p>{{ $escalation->ai_response }}</p>
    @endif

    <p>Please follow up with the patient as soon as possible. You can view escalations in the admin dashboard (if available) or contact the patient through the session context.</p>

    <p>— Pearlie Assistant</p>
</body>
</html>