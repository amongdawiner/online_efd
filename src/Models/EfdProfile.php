<?php

namespace OnlineEfd\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Support\Certificate;

/**
 * A tenant's TRA VFD configuration (certificate, credentials, counters).
 * Secrets are encrypted at rest and hidden from serialization.
 */
class EfdProfile extends Model
{
    protected $table = 'efd_profiles';

    protected $guarded = [];

    protected $hidden = [
        'certificate',
        'certificate_password',
        'username',
        'password',
        'access_token',
    ];

    protected $casts = [
        'certificate' => 'encrypted',
        'certificate_password' => 'encrypted',
        'username' => 'encrypted',
        'password' => 'encrypted',
        'access_token' => 'encrypted',
        'tax_rates' => 'array',
        'registration_info' => 'array',
        'certificate_expires_at' => 'datetime',
        'registered_at' => 'datetime',
        'token_expires_at' => 'datetime',
        'dc_date' => 'date',
        'gross' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    private ?Certificate $certificateCache = null;

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(EfdReceipt::class);
    }

    public function zReports(): HasMany
    {
        return $this->hasMany(EfdZReport::class);
    }

    public function hasCertificate(): bool
    {
        return filled($this->certificate) && filled($this->certificate_password);
    }

    public function isRegistered(): bool
    {
        return filled($this->regid) && $this->registered_at !== null;
    }

    public function hasCredentials(): bool
    {
        return filled($this->username) && filled($this->password);
    }

    /** Validates the .pfx + password by opening it, then stores both encrypted. */
    public function setCertificate(string $pfxBinary, string $password): Certificate
    {
        $certificate = Certificate::fromPkcs12($pfxBinary, $password);

        $this->forceFill([
            'certificate' => base64_encode($pfxBinary),
            'certificate_password' => $password,
            'certificate_serial' => $certificate->serialHex,
            'certificate_expires_at' => $certificate->expiresAt,
        ]);

        return $this->certificateCache = $certificate;
    }

    public function certificate(): Certificate
    {
        if (! $this->hasCertificate()) {
            throw EfdException::notConfigured('no certificate uploaded');
        }

        return $this->certificateCache ??= Certificate::fromPkcs12(
            base64_decode($this->certificate, true) ?: '',
            $this->certificate_password,
        );
    }

    /** @return array<string, int|float> VAT percentage per rate letter A..E */
    public function taxRates(): array
    {
        return array_replace(config('efd.tax_rates'), $this->tax_rates ?? []);
    }

    /** Throws unless the profile can talk to TRA as a registered taxpayer. */
    public function assertReady(): void
    {
        if (! $this->is_active) {
            throw EfdException::notConfigured('profile is disabled');
        }

        $this->certificate();

        if (! $this->isRegistered()) {
            throw EfdException::notRegistered();
        }

        if (! $this->hasCredentials()) {
            throw EfdException::notConfigured('no TRA username/password');
        }
    }

    /**
     * Atomically reserve the next global & daily counters. The daily counter
     * restarts at 1 each day in the configured timezone.
     *
     * @return array{0: int, 1: int} [gc, dc]
     */
    public function allocateCounters(): array
    {
        return DB::transaction(function () {
            $locked = static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail();

            $today = now(config('efd.timezone'))->toDateString();
            $gc = (int) $locked->gc + 1;
            $dc = $locked->dc_date?->toDateString() === $today ? (int) $locked->dc + 1 : 1;

            $locked->forceFill(['gc' => $gc, 'dc' => $dc, 'dc_date' => $today])->save();
            $this->forceFill(['gc' => $gc, 'dc' => $dc, 'dc_date' => $today])->syncOriginal();

            return [$gc, $dc];
        });
    }
}
