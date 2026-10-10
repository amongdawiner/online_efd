<?php

namespace OnlineEfd;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use OnlineEfd\Data\ReceiptData;
use OnlineEfd\Jobs\PostReceiptJob;
use OnlineEfd\Models\EfdProfile;
use OnlineEfd\Models\EfdReceipt;
use OnlineEfd\Models\EfdZReport;
use OnlineEfd\Services\ReceiptService;
use OnlineEfd\Services\RegistrationService;
use OnlineEfd\Services\ZReportService;

/** Everything you can do for one owner's EFD profile. Obtain via Efd::for($owner). */
class EfdClient
{
    public function __construct(
        private readonly EfdProfile $profile,
        private readonly RegistrationService $registration,
        private readonly ReceiptService $receipts,
        private readonly ZReportService $zReports,
    ) {
    }

    public function profile(): EfdProfile
    {
        return $this->profile;
    }

    public function register(): EfdProfile
    {
        return $this->registration->register($this->profile);
    }

    public function testConnection(): void
    {
        $this->registration->testConnection($this->profile);
    }

    /**
     * Issue and post a receipt synchronously. $data may be a ReceiptData, a decoded JSON array or a JSON string.
     *
     * @throws \OnlineEfd\Exceptions\EfdException  the receipt is kept as "failed" and can be retried with retry()
     */
    public function issueReceipt(ReceiptData|array|string $data, ?Model $receivable = null): EfdReceipt
    {
        return $this->receipts->issue($this->profile, ReceiptData::from($data), $receivable);
    }

    /**
     * Send a test receipt (no sale attached). TRA receives it like a real one and it uses the
     * next counters, but it is kept out of Z-reports. Failed tests are not retried by sweeps.
     */
    public function issueTestReceipt(ReceiptData|array|string $data): EfdReceipt
    {
        return $this->receipts->issueTest($this->profile, ReceiptData::from($data));
    }

    /** Reserve the receipt now and post it from the queue (retries with backoff). */
    public function issueReceiptLater(ReceiptData|array|string $data, ?Model $receivable = null): EfdReceipt
    {
        $receipt = $this->receipts->create($this->profile, ReceiptData::from($data), $receivable);

        if (! $receipt->isPosted()) {
            PostReceiptJob::dispatch($receipt->getKey());
        }

        return $receipt;
    }

    public function retry(EfdReceipt $receipt): EfdReceipt
    {
        return $this->receipts->post($receipt);
    }

    public function postZReport(?CarbonInterface $date = null): EfdZReport
    {
        return $this->zReports->post($this->profile, $date);
    }
}
