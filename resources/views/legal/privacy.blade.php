@extends('layouts.legal')

@section('title', 'Privacy Policy')

@section('content')
    <h1>Privacy Policy</h1>
    <p><strong>Last updated:</strong> November 20, 2026</p>

    <p>MediDesk Assistant is a healthcare communication platform operated by AxiomForge Digital Solutions.</p>

    <h2>What data we collect</h2>
    <p>We collect patient names, phone numbers, appointment details, conversation messages, doctor and hospital staff names, and device and browser information when you use the web chat.</p>

    <h2>How we use your data</h2>
    <p>We use this information to book appointments, send appointment reminders, respond to patient queries, and provide hospital administrators and doctors with appointment dashboards.</p>

    <h2>Data sharing</h2>
    <p>Your data is shared with the hospital you are booking with. We do not sell data to third parties. We use Meta (WhatsApp Cloud API), Safaricom (M-Pesa), and Groq (AI) as service providers under strict data-processing agreements.</p>

    <h2>Data retention</h2>
    <p>Appointment and conversation records are retained for the lifetime of the tenant relationship. Patients may request deletion at any time.</p>

    <h2>How to request deletion</h2>
    <p>Email <a href="mailto:support@medidesk.co.ke?subject=Data%20Deletion%20Request">support@medidesk.co.ke</a> with the subject line “Data Deletion Request” and describe the data you want deleted. We will confirm receipt within 72 hours and complete deletion within 30 days, subject to records we are required by law to retain.</p>

    <h2>Contact</h2>
    <p>AxiomForge Digital Solutions · <a href="mailto:support@medidesk.co.ke">support@medidesk.co.ke</a></p>
@endsection

@section('legal-links')
    <a href="/terms">Terms of Service</a>
    <a href="/data-deletion">Data Deletion Instructions</a>
@endsection
