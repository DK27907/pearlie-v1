<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hospital;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptSettingsController extends Controller
{
    public function show(): View
    {
        return view('admin.settings.receipts', [
            'hospital' => $this->currentHospital(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'receipt_prefix' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'receipt_title' => ['required', 'string', 'max:120'],
            'receipt_footer_text' => ['nullable', 'string', 'max:4000'],
            'receipt_show_kra_pin' => ['sometimes', 'boolean'],
            'receipt_kra_pin' => ['nullable', 'string', 'max:30'],
            'etims_enabled' => ['sometimes', 'boolean'],
            'etims_kra_pin' => ['nullable', 'required_if:etims_enabled,1', 'string', 'max:30'],
            'etims_environment' => ['nullable', 'required_if:etims_enabled,1', 'in:sandbox,production'],
            'etims_device_id' => ['nullable', 'required_if:etims_enabled,1', 'string', 'max:120'],
            'letterhead' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
            'footer_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:1024'],
            'signature_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:1024'],
            'remove_letterhead' => ['sometimes', 'boolean'],
            'remove_footer_image' => ['sometimes', 'boolean'],
            'remove_signature_image' => ['sometimes', 'boolean'],
        ]);

        $hospital = $this->currentHospital();
        $etimsEnabled = (bool) ($validated['etims_enabled'] ?? false);
        $updates = [
            'receipt_prefix' => $validated['receipt_prefix'],
            'receipt_title' => $validated['receipt_title'],
            'receipt_footer_text' => $validated['receipt_footer_text'] ?? null,
            'receipt_show_kra_pin' => (bool) ($validated['receipt_show_kra_pin'] ?? false),
            'receipt_kra_pin' => $validated['receipt_kra_pin'] ?? null,
            'etims_enabled' => $etimsEnabled,
            'etims_kra_pin' => $etimsEnabled ? ($validated['etims_kra_pin'] ?? null) : null,
            'etims_environment' => $etimsEnabled ? ($validated['etims_environment'] ?? null) : null,
            'etims_device_id' => $etimsEnabled ? ($validated['etims_device_id'] ?? null) : null,
        ];
        $replacedPaths = [];

        foreach ([
            'letterhead' => 'receipt_letterhead_path',
            'footer_image' => 'receipt_footer_path',
            'signature_image' => 'receipt_signature_path',
        ] as $input => $column) {
            $removeKey = 'remove_'.$input;
            if ($request->boolean($removeKey)) {
                $replacedPaths[] = $hospital->{$column};
                $updates[$column] = null;
            }

            if ($request->hasFile($input)) {
                $replacedPaths[] = $hospital->{$column};
                $updates[$column] = $request->file($input)->store(
                    sprintf('hospital-receipts/%d', $hospital->id),
                );
            }
        }

        $hospital->fill($updates)->save();

        foreach (array_filter(array_unique($replacedPaths)) as $path) {
            if (! Storage::delete($path)) {
                Log::warning('A replaced receipt image could not be removed from storage.', [
                    'hospital_id' => $hospital->id,
                    'path' => $path,
                ]);
            }
        }

        return redirect()
            ->route('admin.integration-settings.edit', ['tab' => 'receipts'])
            ->with('status', 'Receipt settings saved.');
    }

    public function asset(string $kind): StreamedResponse
    {
        $column = match ($kind) {
            'letterhead' => 'receipt_letterhead_path',
            'footer' => 'receipt_footer_path',
            'signature' => 'receipt_signature_path',
            default => abort(404),
        };
        $path = $this->currentHospital()->getAttribute($column);
        abort_unless(is_string($path) && Storage::exists($path), 404);

        return Storage::response($path, headers: ['Cache-Control' => 'private, no-store']);
    }

    private function currentHospital(): Hospital
    {
        $hospital = hospital();
        abort_unless($hospital instanceof Hospital, 403, 'No hospital is bound to this request.');

        return $hospital;
    }
}
