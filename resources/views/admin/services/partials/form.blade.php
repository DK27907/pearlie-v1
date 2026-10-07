<form method="POST" action="{{ $action }}" class="space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif

    <div>
        <label for="name" class="block text-sm font-medium text-slate-700">Service name</label>
        <input id="name" name="name" type="text" required maxlength="120" value="{{ old('name', $service->name) }}" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
        @error('name')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
    </div>

    <div>
        <label for="description" class="block text-sm font-medium text-slate-700">Description</label>
        <textarea id="description" name="description" rows="4" maxlength="2000" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">{{ old('description', $service->description) }}</textarea>
        @error('description')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
    </div>

    <div class="grid gap-5 sm:grid-cols-2">
        <div>
            <label for="price" class="block text-sm font-medium text-slate-700">Price (KSh)</label>
            <input id="price" name="price" type="number" required min="0" max="1000000" step="0.01" value="{{ old('price', $service->price) }}" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
            @error('price')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="duration_minutes" class="block text-sm font-medium text-slate-700">Duration (minutes)</label>
            <input id="duration_minutes" name="duration_minutes" type="number" required min="5" max="480" step="1" value="{{ old('duration_minutes', $service->duration_minutes) }}" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
            @error('duration_minutes')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="category" class="block text-sm font-medium text-slate-700">Category</label>
            <input id="category" name="category" type="text" maxlength="60" value="{{ old('category', $service->category) }}" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
            @error('category')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="requires_specialty" class="block text-sm font-medium text-slate-700">Required doctor specialty</label>
            <input id="requires_specialty" name="requires_specialty" type="text" maxlength="60" value="{{ old('requires_specialty', $service->requires_specialty) }}" class="mt-1 block w-full rounded-lg border-slate-300 shadow-sm focus:border-cyan-600 focus:ring-cyan-600">
            @error('requires_specialty')<p class="mt-1 text-sm text-rose-700">{{ $message }}</p>@enderror
        </div>
    </div>

    <label class="flex items-center gap-3 border-t border-slate-100 pt-5 text-sm font-medium text-slate-700">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" name="is_active" value="1" @checked((bool) old('is_active', $service->exists ? $service->is_active : true)) class="rounded border-slate-300 text-cyan-700 focus:ring-cyan-600">
        Available for patient bookings
    </label>
    @error('is_active')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror

    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            Please correct the highlighted fields.
        </div>
    @endif

    <div class="flex items-center justify-end gap-3 border-t border-slate-100 pt-5">
        <a href="{{ route('admin.services.index') }}" class="rounded-lg px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-100">Cancel</a>
        <button type="submit" class="rounded-lg bg-cyan-700 px-4 py-2 text-sm font-semibold text-white hover:bg-cyan-800">Save service</button>
    </div>
</form>
