<h1>New MediDesk AI demo request</h1>

<p><strong>Name:</strong> {{ $request['name'] }}</p>
<p><strong>Work email:</strong> {{ $request['email'] }}</p>
<p><strong>Hospital or organization:</strong> {{ $request['organization'] }}</p>
<p><strong>Phone:</strong> {{ $request['phone'] ?: 'Not provided' }}</p>
<p><strong>Message:</strong></p>
<p>{{ $request['message'] ?: 'No additional message provided.' }}</p>
