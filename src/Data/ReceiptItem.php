<?php

namespace OnlineEfd\Data;

use OnlineEfd\Enums\TaxCode;

/** One receipt line. $unitPrice is tax-inclusive. */
final class ReceiptItem
{
    public function __construct(
        public readonly string $description,
        public readonly float|int|string $quantity,
        public readonly float|int|string $unitPrice,
        public readonly TaxCode $taxCode = TaxCode::Standard,
        public readonly ?string $id = null,
    ) {
    }
}
