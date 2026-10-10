<?php

namespace OnlineEfd\Events;

use Illuminate\Foundation\Events\Dispatchable;
use OnlineEfd\Models\EfdReceipt;

class ReceiptPosted
{
    use Dispatchable;

    public function __construct(public readonly EfdReceipt $receipt)
    {
    }
}
