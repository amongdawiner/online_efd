<?php

namespace OnlineEfd\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EfdReceipt extends Model
{
    public const PENDING = 'pending';
    public const POSTED = 'posted';
    public const FAILED = 'failed';

    protected $table = 'efd_receipts';

    protected $guarded = [];

    protected $hidden = ['request_xml', 'response_xml'];

    protected $casts = [
        'items' => 'array',
        'vat_totals' => 'array',
        'total_excl' => 'decimal:2',
        'total_incl' => 'decimal:2',
        'is_test' => 'boolean',
        'posted_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(EfdProfile::class, 'efd_profile_id');
    }

    /** The host app's sale/invoice this receipt was issued for. */
    public function receivable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }
}
