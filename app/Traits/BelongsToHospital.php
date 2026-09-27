<?php

namespace App\Traits;

use App\Models\Hospital;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToHospital
{
    public static function bootBelongsToHospital(): void
    {
        static::creating(function ($model): void {
            $hospital = hospital();
            if ($hospital) {
                $model->hospital_id = $hospital->id;
            } elseif (! $model->hospital_id) {
                $hospital = Hospital::withoutGlobalScopes()
                    ->where('slug', config('pearlie.default_hospital_slug', 'pearl'))
                    ->first();
                $model->hospital_id = $hospital?->id;
            }
        });

        static::addGlobalScope('hospital', function ($query): void {
            if ($hospital = hospital()) {
                $query->where(
                    $query->getModel()->qualifyColumn('hospital_id'),
                    $hospital->id,
                );
            }
        });
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }
}
