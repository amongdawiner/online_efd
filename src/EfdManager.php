<?php

namespace OnlineEfd;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use OnlineEfd\Exceptions\EfdException;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Services\ReceiptService;
use OnlineEfd\Services\RegistrationService;
use OnlineEfd\Services\ZReportService;

class EfdManager
{
    public function __construct(private readonly Container $app)
    {
    }

    /** The owner's profile, or null if they have not set one up. */
    public function profile(Model $owner): ?EfdProfile
    {
        return EfdProfile::query()
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    /** The owner's profile, created empty on first use (used by the settings screen). */
    public function profileOrNew(Model $owner): EfdProfile
    {
        return $this->profile($owner) ?? new EfdProfile([
            'owner_type' => $owner->getMorphClass(),
            'owner_id' => $owner->getKey(),
            'tin' => '',
            'cert_key' => '',
        ]);
    }

    /**
     * Save the details an owner entered on the host app's settings page.
     * Only the keys you pass are changed, so partial updates are fine.
     *
     *   tin                   string
     *   cert_key              string   (EFD serial, e.g. 10TXXXXX73)
     *   certificate           the .pfx: raw bytes, a file path, or an uploaded file object
     *   certificate_password  string   (required whenever a new certificate is passed)
     *
     * The .pfx is opened with the password before anything is stored, so a wrong
     * password fails here instead of at the first receipt.
     *
     * @throws EfdException
     */
    public function configure(Model $owner, array $settings): EfdProfile
    {
        $profile = $this->profileOrNew($owner);

        foreach (['tin', 'cert_key'] as $key) {
            if (array_key_exists($key, $settings)) {
                $profile->{$key} = trim((string) $settings[$key]);
            }
        }

        if (blank($profile->tin) || blank($profile->cert_key)) {
            throw EfdException::notConfigured('tin and cert_key are required');
        }

        $password = $settings['certificate_password'] ?? null;

        if (! empty($settings['certificate'])) {
            if (blank($password)) {
                throw EfdException::certificate('certificate_password is required with a new certificate.');
            }
            $profile->setCertificate($this->readCertificate($settings['certificate']), (string) $password);
        } elseif (filled($password) && $profile->hasCertificate()) {
            // Password changed for the certificate already on file.
            $profile->setCertificate(base64_decode($profile->certificate, true) ?: '', (string) $password);
        }

        if ($profile->exists && $profile->isDirty(['tin', 'cert_key'])) {
            $profile->registered_at = null; // different taxpayer/device: must register again
        }

        $profile->save();

        return $profile;
    }

    /**
     * Safe, JSON-ready summary for a settings page. Never contains secrets.
     *
     * @return array<string, mixed>
     */
    public function status(Model $owner): array
    {
        $p = $this->profile($owner);

        return [
            'configured' => $p !== null,
            'tin' => $p?->tin,
            'cert_key' => $p?->cert_key,
            'has_certificate' => (bool) $p?->hasCertificate(),
            'certificate_serial' => $p?->certificate_serial,
            'certificate_expires_at' => $p?->certificate_expires_at?->toIso8601String(),
            'registered' => (bool) $p?->isRegistered(),
            'registered_at' => $p?->registered_at?->toIso8601String(),
            'taxpayer' => $p?->isRegistered()
                ? ['name' => $p->name, 'vrn' => $p->vrn, 'regid' => $p->regid, 'tax_office' => $p->tax_office]
                : null,
            'ready' => $this->isReady($owner),
        ];
    }

    /** Remove the stored certificate (e.g. owner clicked "remove"). Receipts cannot be issued until a new one is configured. */
    public function removeCertificate(Model $owner): void
    {
        $this->profile($owner)?->forceFill([
            'certificate' => null,
            'certificate_password' => null,
            'certificate_serial' => null,
            'certificate_expires_at' => null,
        ])->save();
    }

    /** Operate on an owner's EFD profile: Efd::for($show)->issueReceipt(...). */
    public function for(Model $owner): EfdClient
    {
        $profile = $this->profile($owner) ?? throw EfdException::notConfigured('this owner has no EFD profile');

        return $this->forProfile($profile);
    }

    public function forProfile(EfdProfile $profile): EfdClient
    {
        return new EfdClient(
            $profile,
            $this->app->make(RegistrationService::class),
            $this->app->make(ReceiptService::class),
            $this->app->make(ZReportService::class),
        );
    }

    private function readCertificate(mixed $source): string
    {
        if (is_object($source) && method_exists($source, 'getRealPath')) {
            $source = $source->getRealPath(); // Illuminate\Http\UploadedFile / SplFileInfo
        }

        if (! is_string($source)) {
            throw EfdException::certificate('unsupported certificate input.');
        }

        // A path (short, no binary bytes) or the raw .pfx bytes.
        if (strlen($source) < 1024 && ! str_contains($source, "\0") && is_file($source)) {
            return (string) file_get_contents($source);
        }

        return $source;
    }

    /** True when the owner is registered and ready to issue receipts. */
    public function isReady(Model $owner): bool
    {
        $profile = $this->profile($owner);

        return $profile !== null && $profile->is_active && $profile->hasCertificate()
            && $profile->isRegistered() && $profile->hasCredentials();
    }
}
