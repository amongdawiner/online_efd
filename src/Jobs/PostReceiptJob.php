<?php

namespace OnlineEfd\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use OnlineEfd\Models\EfdReceipt;
use OnlineEfd\Services\ReceiptService;

class PostReceiptJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public readonly int $receiptId)
    {
        $this->tries = (int) config('efd.queue.tries', 5);
        $this->onConnection(config('efd.queue.connection'));
        $this->onQueue(config('efd.queue.queue'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return (array) config('efd.queue.backoff', [30, 120, 600]);
    }

    public function handle(ReceiptService $receipts): void
    {
        $receipt = EfdReceipt::find($this->receiptId);

        if ($receipt && ! $receipt->isPosted()) {
            $receipts->post($receipt); // throws on failure -> queue retries with backoff
        }
    }
}
