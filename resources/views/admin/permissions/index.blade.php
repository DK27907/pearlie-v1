@extends('layouts.app')

@section('content')
<div class="container">
    <h1>Permissions</h1>

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    <div class="row">
        <div class="col-md-6">
            <h3>Existing Permissions</h3>
            <ul>
                @foreach($permissions as $p)
                    <li>{{ $p->name }} <form style="display:inline" method="POST" action="{{ route('admin.permissions.destroy', $p->name) }}">@csrf @method('DELETE')<button class="btn btn-sm btn-link text-danger">Delete</button></form></li>
                @endforeach
            </ul>
        </div>

        <div class="col-md-6">
            <h3>Create Permission</h3>
            <form method="POST" action="{{ route('admin.permissions.store') }}">
                @csrf
                <div class="form-group">
                    <label>Name</label>
                    <input name="name" class="form-control" required />
                </div>
                <button class="btn btn-primary">Create</button>
            </form>

            <hr />

            <h3>Assign Permission to Role</h3>
            <form method="POST" action="{{ route('admin.permissions.assign') }}">
                @csrf
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Permission</label>
                    <select name="permission" class="form-control">
                        @foreach($permissions as $p)
                            <option value="{{ $p->name }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-secondary">Assign</button>
            </form>

            <hr />

            <h3>Revoke Permission from Role</h3>
            <form method="POST" action="{{ route('admin.permissions.revoke') }}">
                @csrf
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" class="form-control">
                        @foreach($roles as $r)
                            <option value="{{ $r->name }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="form-group">
                    <label>Permission</label>
                    <select name="permission" class="form-control">
                        @foreach($permissions as $p)
                            <option value="{{ $p->name }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button class="btn btn-danger">Revoke</button>
            </form>
        </div>
    </div>
</div>
@endsection
