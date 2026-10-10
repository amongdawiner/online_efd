<?php

namespace OnlineEfd\Console;

use Illuminate\Console\Command;
use OnlineEfd\Facades\Efd;
use OnlineEfd\Models\EfdReceipt;
use Throwable;

class RetryReceiptsCommand extends Command
{
    protected $signature = 'efd:retry
                            {--limit=50 : Maximum receipts to retry in one run}
                            {--minutes=2 : Only receipts last touched at least this many minutes ago}';

    protected $description = 'Re-send real (non-test) receipts TRA has not accepted yet, with their original numbers';

    public function handle(): int
    {
        $failed = 0;

        EfdReceipt::query()
            ->with('profile')
            ->where('is_test', false)
            ->where('status', '!=', EfdReceipt::POSTED)
            ->where('updated_at', '<=', now()->subMinutes((int) $this->option('minutes')))
            ->whereHas('profile', fn ($q) => $q->where('is_active', true)->whereNotNull('registered_at'))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get()
            ->each(function (EfdReceipt $receipt) use (&$failed) {
                try {
                    Efd::forProfile($receipt->profile)->retry($receipt);
                    $this->info("Receipt #{$receipt->id}: posted");
                } catch (Throwable $e) {
                    $failed++;
                    $this->error("Receipt #{$receipt->id}: {$e->getMessage()}");
                }
            });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
