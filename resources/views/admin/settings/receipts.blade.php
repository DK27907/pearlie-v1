<section role="tabpanel" class="space-y-6 p-5 sm:p-7">
    <div>
        <h2 class="text-lg font-bold text-slate-900">Receipt settings</h2>
        <p class="mt-1 text-sm text-slate-600">Configure branded receipts for online and in-person payments.</p>
    </div>
    <form method="POST" action="{{ route('admin.integration-settings.receipts.update') }}" enctype="multipart/form-data" class="space-y-6">
        @csrf
        @method('PUT')
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="block text-sm font-semibold text-slate-700">Receipt prefix
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="receipt_prefix" maxlength="30" required value="{{ old('receipt_prefix', $hospital->receipt_prefix ?: 'RCPT') }}">
            </label>
            <label class="block text-sm font-semibold text-slate-700">Receipt title
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="receipt_title" maxlength="120" required value="{{ old('receipt_title', $hospital->receipt_title ?: 'Payment Receipt') }}">
            </label>
        </div>

        <fieldset class="grid gap-5 rounded-xl border border-slate-200 p-4 sm:grid-cols-2">
            <legend class="px-2 text-sm font-bold text-slate-900">Letterhead and footer</legend>
            <label class="block text-sm font-semibold text-slate-700">Letterhead image
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="file" name="letterhead" accept="image/png,image/jpeg">
                <span class="mt-1 block text-xs font-normal text-slate-500">PNG or JPG, up to 2 MB.</span>
            </label>
            @if ($hospital->receipt_letterhead_path)
                <div class="space-y-2">
                    <img src="{{ route('admin.integration-settings.receipts.asset', 'letterhead') }}" alt="Current receipt letterhead" class="max-h-24 max-w-full rounded border border-slate-200">
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_letterhead" value="1"> Remove letterhead</label>
                </div>
            @endif
            <label class="block text-sm font-semibold text-slate-700">Footer image
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="file" name="footer_image" accept="image/png,image/jpeg">
                <span class="mt-1 block text-xs font-normal text-slate-500">PNG or JPG, up to 1 MB. If uploaded, it replaces footer text.</span>
            </label>
            @if ($hospital->receipt_footer_path)
                <div class="space-y-2">
                    <img src="{{ route('admin.integration-settings.receipts.asset', 'footer') }}" alt="Current receipt footer" class="max-h-20 max-w-full rounded border border-slate-200">
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_footer_image" value="1"> Remove footer image</label>
                </div>
            @endif
            <label class="block text-sm font-semibold text-slate-700 sm:col-span-2">Footer text
                <textarea class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="receipt_footer_text" rows="3" maxlength="4000">{{ old('receipt_footer_text', $hospital->receipt_footer_text) }}</textarea>
            </label>
            <label class="block text-sm font-semibold text-slate-700">Optional signature image
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" type="file" name="signature_image" accept="image/png,image/jpeg">
                <span class="mt-1 block text-xs font-normal text-slate-500">PNG or JPG, up to 1 MB.</span>
            </label>
            @if ($hospital->receipt_signature_path)
                <div class="space-y-2">
                    <img src="{{ route('admin.integration-settings.receipts.asset', 'signature') }}" alt="Current receipt signature" class="max-h-16 max-w-full rounded border border-slate-200">
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="checkbox" name="remove_signature_image" value="1"> Remove signature image</label>
                </div>
            @endif
        </fieldset>

        <fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
            <legend class="px-2 text-sm font-bold text-slate-900">Tax information</legend>
            <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" name="receipt_show_kra_pin" value="1" @checked(old('receipt_show_kra_pin', $hospital->receipt_show_kra_pin))>
                Show KRA PIN on receipts
            </label>
            <label class="block max-w-md text-sm font-semibold text-slate-700">Receipt KRA PIN
                <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="receipt_kra_pin" maxlength="30" value="{{ old('receipt_kra_pin', $hospital->receipt_kra_pin) }}">
            </label>
        </fieldset>

        <fieldset class="space-y-4 rounded-xl border border-slate-200 p-4">
            <legend class="px-2 text-sm font-bold text-slate-900">eTIMS Integration</legend>
            <p class="text-sm text-slate-600">This scaffold does not call the KRA API. Activating eTIMS requires a KRA eTIMS account. Contact support.</p>
            <label class="flex items-center gap-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" name="etims_enabled" value="1" @checked(old('etims_enabled', $hospital->etims_enabled))>
                Enable eTIMS
            </label>
            <div class="grid gap-5 sm:grid-cols-3">
                <label class="block text-sm font-semibold text-slate-700">eTIMS KRA PIN
                    <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="etims_kra_pin" maxlength="30" value="{{ old('etims_kra_pin', $hospital->etims_kra_pin) }}">
                </label>
                <label class="block text-sm font-semibold text-slate-700">Environment
                    <select class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="etims_environment">
                        <option value="">Select environment</option>
                        <option value="sandbox" @selected(old('etims_environment', $hospital->etims_environment) === 'sandbox')>Sandbox</option>
                        <option value="production" @selected(old('etims_environment', $hospital->etims_environment) === 'production')>Production</option>
                    </select>
                </label>
                <label class="block text-sm font-semibold text-slate-700">Device ID
                    <input class="mt-1 block w-full rounded-lg border-slate-300 text-sm" name="etims_device_id" maxlength="120" value="{{ old('etims_device_id', $hospital->etims_device_id) }}">
                </label>
            </div>
        </fieldset>

        <button type="submit" class="rounded-lg bg-sky-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-900">Save receipt settings</button>
    </form>
</section>
