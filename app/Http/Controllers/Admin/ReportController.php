<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AppointmentRequest;
use App\Models\MpesaPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        [$from, $to] = $this->dateRange($request);
        $appointments = $this->appointmentsBetween($from, $to);

        return view('admin.reports.index', [
            'from' => $from,
            'to' => $to,
            'appointmentCount' => (clone $appointments)->count(),
            'statusTotals' => (clone $appointments)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->orderBy('status')
                ->pluck('total', 'status'),
            'dailyAppointments' => (clone $appointments)
                ->selectRaw('preferred_date, COUNT(*) as total')
                ->groupBy('preferred_date')
                ->orderBy('preferred_date')
                ->get(),
            'revenue' => MpesaPayment::query()
                ->where('status', MpesaPayment::STATUS_COMPLETED)
                ->whereBetween('processed_at', [$from->startOfDay(), $to->endOfDay()])
                ->sum('amount'),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        [$from, $to] = $this->dateRange($request);
        $appointments = $this->appointmentsBetween($from, $to)
            ->orderBy('preferred_date')
            ->orderBy('id');

        return response()->streamDownload(function () use ($appointments): void {
            $stream = fopen('php://output', 'w');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the appointment report output stream.');
            }

            fputcsv($stream, ['ID', 'Patient', 'Phone', 'Email', 'Date', 'Service', 'Status', 'Payment status']);
            $appointments->chunk(500, function ($rows) use ($stream): void {
                foreach ($rows as $appointment) {
                    fputcsv($stream, [
                        $appointment->id,
                        $this->safeCsvValue($appointment->name),
                        $this->safeCsvValue($appointment->phone),
                        $this->safeCsvValue($appointment->email),
                        $appointment->preferred_date?->format('Y-m-d') ?? '',
                        $this->safeCsvValue($appointment->reason),
                        $this->safeCsvValue($appointment->status),
                        $this->safeCsvValue($appointment->payment_status),
                    ]);
                }
            });

            fclose($stream);
        }, 'appointment-report-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function dateRange(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($data['from'] ?? today()->subDays(29)->toDateString())->startOfDay();
        $to = CarbonImmutable::parse($data['to'] ?? today()->toDateString())->endOfDay();

        return [$from, $to];
    }

    private function appointmentsBetween(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return AppointmentRequest::query()
            ->whereBetween('preferred_date', [
                $from->startOfDay()->toDateTimeString(),
                $to->endOfDay()->toDateTimeString(),
            ]);
    }

    private function safeCsvValue(?string $value): string
    {
        $value ??= '';

        // Prevent spreadsheet formulas from executing patient-controlled text.
        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }
}
