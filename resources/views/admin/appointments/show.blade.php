@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Appointment #{{ $appt->id }}</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="card mb-3">
        <div class="card-body">
            <p><strong>Name:</strong> {{ $appt->name }}</p>
            <p><strong>Phone:</strong> {{ $appt->phone }}</p>
            <p><strong>Preferred Date:</strong> {{ $appt->preferred_date }}</p>
            <p><strong>Reason:</strong> {{ $appt->reason }}</p>
            <p><strong>Status:</strong> {{ $appt->status }}</p>

            <form method="POST" action="{{ route('admin.appointments.updateStatus', $appt->id) }}">
                @csrf
                <div class="form-group">
                    <label for="status">Update status</label>
                    <select name="status" id="status" class="form-control">
                        <option value="pending" {{ $appt->status === 'pending' ? 'selected' : '' }}>Pending</option>
                        <option value="confirmed" {{ $appt->status === 'confirmed' ? 'selected' : '' }}>Confirmed</option>
                        <option value="cancelled" {{ $appt->status === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        <option value="completed" {{ $appt->status === 'completed' ? 'selected' : '' }}>Completed</option>
                    </select>
                </div>
                <button class="btn btn-primary mt-2">Update</button>
            </form>

            <form method="POST" action="{{ route('admin.appointments.confirm', $appt->id) }}" style="display:inline">
                @csrf
                <button class="btn btn-success mt-2">Confirm Appointment & Notify</button>
            </form>
        </div>
    </div>

    <a href="{{ route('admin.appointments.index') }}" class="btn btn-link">Back to list</a>
</div>
@endsection
