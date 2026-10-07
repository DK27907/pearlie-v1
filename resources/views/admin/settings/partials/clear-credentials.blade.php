<form method="POST" action="{{ route('admin.integration-settings.credentials.clear', $provider) }}">
    @csrf
    @method('DELETE')
    <button type="submit" class="rounded-lg border border-rose-300 px-4 py-2.5 text-sm font-semibold text-rose-700 hover:bg-rose-50">Clear credentials</button>
</form>
