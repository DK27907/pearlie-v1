<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MpesaPayment;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(): View
    {
        return view('admin.payments.index', [
            'payments' => MpesaPayment::query()
                ->with('appointment:id,name,phone,reason')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(25),
        ]);
    }
}
