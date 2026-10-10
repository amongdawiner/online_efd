<?php

namespace OnlineEfd\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use OnlineEfd\EfdClient;
use OnlineEfd\Facades\Efd;
use OnlineEfd\Models\EfdProfile;

/**
 * Add to any model that owns an EFD configuration (a Show, Shop, Company...).
 *
 *     $show->efd()->issueReceipt($data, $sale);
 */
trait HasEfdProfile
{
    public function efdProfile(): MorphOne
    {
        return $this->morphOne(EfdProfile::class, 'owner');
    }

    public function efd(): EfdClient
    {
        return Efd::for($this);
    }

    public function hasEfd(): bool
    {
        return Efd::isReady($this);
    }
}
