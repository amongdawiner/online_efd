<?php

namespace OnlineEfd\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \OnlineEfd\EfdClient for(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \OnlineEfd\EfdClient forProfile(\OnlineEfd\Models\EfdProfile $profile)
 * @method static \OnlineEfd\Models\EfdProfile|null profile(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \OnlineEfd\Models\EfdProfile profileOrNew(\Illuminate\Database\Eloquent\Model $owner)
 * @method static \OnlineEfd\Models\EfdProfile configure(\Illuminate\Database\Eloquent\Model $owner, array $settings)
 * @method static array status(\Illuminate\Database\Eloquent\Model $owner)
 * @method static void removeCertificate(\Illuminate\Database\Eloquent\Model $owner)
 * @method static bool isReady(\Illuminate\Database\Eloquent\Model $owner)
 *
 * @see \OnlineEfd\EfdManager
 */
class Efd extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \OnlineEfd\EfdManager::class;
    }
}
