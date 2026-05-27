<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'queue_reference',
    'customer_name',
    'customer_email',
    'customer_phone',
    'system_type',
    'brand_preference',
    'request_type',
    'priority',
    'priority_score',
    'status',
    'preferred_date',
    'preferred_time_slot',
    'address',
    'city',
    'state',
    'zip_code',
    'latitude',
    'longitude',
    'waze_link',
    'description',
    'assigned_technician',
    'completed_at',
])]
class ServiceRequest extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'preferred_date' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
