@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Roles</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <h3>Users</h3>
            <table class="table">
                <thead><tr><th>Name</th><th>Email</th><th>Roles</th></tr></thead>
                <tbody>
                @foreach($users as $u)
                    <tr>
                        <td>{{ $u->name }}</td>
                        <td>{{ $u->email }}</td>
                        <td>{{ implode(', ', $u->getRoleNames()->toArray()) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="col-md-6">
            <h3>Assign Role</h3>
            <form method="POST" action="{{ route('admin.roles.assign') }}">
                @csrf
                <div class="form-group">
                    <label>User</label>
                    <select name="user_id" class="form-control">
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-primary">Assign</button>
            </form>

            <hr />

            <h3>Remove Role</h3>

            <hr />

            <h3>Create Invite</h3>
            <form method="POST" action="{{ route('admin.invites.store') }}">
                @csrf
                <div class="form-group">
                    <label>Invite Email (required)</label>
                    <input required type="email" name="email" class="form-control" placeholder="user@example.com" />
                </div>
                <button class="btn btn-secondary">Create & Email Invite</button>
            </form>
            <form method="POST" action="{{ route('admin.roles.remove') }}">
                @csrf
                <div class="form-group">
                    <label>User</label>
                    <select name="user_id" class="form-control">
                        @foreach($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-danger">Remove</button>
            </form>
        </div>
    </div>
</div>
@endsection
