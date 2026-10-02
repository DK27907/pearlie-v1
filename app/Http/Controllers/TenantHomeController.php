<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class TenantHomeController extends Controller
{
    public function index(): View
    {
        $hospital = hospital();
        abort_unless($hospital, 404);

        return view('tenant.home', [
            'hospital' => $hospital,
            'catalogServices' => $hospital->services()
                ->active()
                ->orderBy('name')
                ->get(),
            'doctors' => $hospital->doctors()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'about' => data_get($hospital->settings, 'about'),
            'whatsappNumber' => $this->whatsappNumber($hospital->whatsapp_number ?: $hospital->phone),
        ]);
    }

    private function whatsappNumber(?string $phone): string
    {
        $number = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($number) === 10 && str_starts_with($number, '0')) {
            return '254'.substr($number, 1);
        }

        if (strlen($number) === 9) {
            return '254'.$number;
        }

        return $number;
    }
}
