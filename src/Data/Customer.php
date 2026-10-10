<?php

namespace OnlineEfd\Data;

use OnlineEfd\Enums\CustomerIdType;

final class Customer
{
    public function __construct(
        public readonly ?string $name = null,
        public readonly ?string $phone = null,
        public readonly CustomerIdType $idType = CustomerIdType::None,
        public readonly ?string $id = null,
    ) {
    }
}
