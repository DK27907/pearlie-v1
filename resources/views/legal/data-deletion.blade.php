@extends('layouts.legal')

@section('title', 'Data Deletion Instructions')

@section('content')
    <h1>Data Deletion Instructions</h1>
    <p><strong>Last updated:</strong> November 20, 2026</p>

    <h2>How to request deletion</h2>
    <p>Email <a href="mailto:support@medidesk.co.ke?subject=Data%20Deletion%20Request">support@medidesk.co.ke</a> with the subject “Data Deletion Request”. Include:</p>
    <ul>
        <li>The phone number or email address associated with your account</li>
        <li>The name of the hospital where your appointment was booked</li>
        <li>What you want deleted: all data or specific records</li>
    </ul>

    <h2>What happens next</h2>
    <p>We will confirm receipt within 72 hours and complete deletion within 30 days. We retain only records required by law, including applicable tax or medical-record retention requirements, and delete everything else.</p>

    <h2>For WhatsApp users</h2>
    <p>Reply “STOP” to any message to opt out of reminders. To delete WhatsApp message history held by us, send the email request above.</p>

    <h2>Contact</h2>
    <p>AxiomForge Digital Solutions · <a href="mailto:support@medidesk.co.ke">support@medidesk.co.ke</a></p>
@endsection

@section('legal-links')
    <a href="/privacy">Privacy Policy</a>
    <a href="/terms">Terms of Service</a>
@endsection
