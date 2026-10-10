<?php

namespace OnlineEfd\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfdZReport extends Model
{
    public const PENDING = 'pending';
    public const POSTED = 'posted';
    public const FAILED = 'failed';

    protected $table = 'efd_z_reports';

    protected $guarded = [];

    protected $hidden = ['request_xml', 'response_xml'];

    protected $casts = [
        'report_date' => 'date',
        'daily_total' => 'decimal:2',
        'gross' => 'decimal:2',
        'summary' => 'array',
        'posted_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EfdProfile::class, 'efd_profile_id');
    }
}
