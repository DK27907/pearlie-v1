<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('doctor_availabilities')
            ->whereNull('hospital_id')
            ->orderBy('id')
            ->chunkById(100, function ($availabilities): void {
                $hospitalIds = DB::table('users')
                    ->whereIn('id', $availabilities->pluck('doctor_id')->unique()->all())
                    ->pluck('hospital_id', 'id');

                foreach ($availabilities as $availability) {
                    $hospitalId = $hospitalIds->get($availability->doctor_id);
                    if ($hospitalId === null) {
                        continue;
                    }

                    DB::table('doctor_availabilities')
                        ->where('id', $availability->id)
                        ->whereNull('hospital_id')
                        ->update(['hospital_id' => $hospitalId]);
                }
            });
    }

    public function down(): void
    {
        throw new LogicException('Tenant ownership backfill cannot be safely reverted.');
    }
};
