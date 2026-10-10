<?php

namespace OnlineEfd\Data;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use OnlineEfd\Enums\CustomerIdType;
use OnlineEfd\Enums\PaymentType;
use OnlineEfd\Enums\TaxCode;

final class ReceiptData
{
    /** @param  list<ReceiptItem>  $items */
    public function __construct(
        public readonly array $items,
        public readonly PaymentType $paymentType = PaymentType::Cash,
        public readonly ?Customer $customer = null,
        public readonly ?CarbonInterface $issuedAt = null,
    ) {
    }

    /** Accepts a ReceiptData, a decoded JSON array, or a JSON string. */
    public static function from(self|array|string $input): self
    {
        if ($input instanceof self) {
            return $input;
        }

        if (is_string($input)) {
            $input = json_decode($input, true);
            if (! is_array($input)) {
                throw new InvalidArgumentException('Receipt JSON is not valid.');
            }
        }

        return self::fromArray($input);
    }

    /**
     * Expected shape (all keys snake_case, unit_price is tax-inclusive):
     * {
     *   "items": [{"description": "Ticket", "quantity": 2, "unit_price": 59000, "tax_code": 1, "id": "T1"}],
     *   "payment_type": "CASH",                       // CASH|CHEQUE|CCARD|EMONEY|INVOICE
     *   "customer": {"name": "..", "phone": "..", "id_type": 5, "id": ".."},
     *   "issued_at": "2026-10-10 14:30:00"            // optional
     * }
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ($data['items'] ?? [] as $i => $item) {
            foreach (['description', 'quantity', 'unit_price'] as $key) {
                if (! isset($item[$key]) || $item[$key] === '') {
                    throw new InvalidArgumentException("items.{$i}.{$key} is required.");
                }
            }

            $items[] = new ReceiptItem(
                (string) $item['description'],
                $item['quantity'],
                $item['unit_price'],
                self::enum(TaxCode::class, $item['tax_code'] ?? TaxCode::Standard->value, "items.{$i}.tax_code"),
                isset($item['id']) ? (string) $item['id'] : null,
            );
        }

        $customer = null;
        if (! empty($data['customer'])) {
            $c = $data['customer'];
            $customer = new Customer(
                $c['name'] ?? null,
                $c['phone'] ?? null,
                self::enum(CustomerIdType::class, $c['id_type'] ?? CustomerIdType::None->value, 'customer.id_type'),
                $c['id'] ?? null,
            );
        }

        return new self(
            $items,
            self::enum(PaymentType::class, $data['payment_type'] ?? PaymentType::Cash->value, 'payment_type'),
            $customer,
            isset($data['issued_at']) ? Carbon::parse($data['issued_at'], config('efd.timezone', 'Africa/Dar_es_Salaam')) : null,
        );
    }

    /** @template T of \BackedEnum  @param class-string<T> $enum  @return T */
    private static function enum(string $enum, mixed $value, string $field): \BackedEnum
    {
        try {
            $case = $enum::tryFrom($value); // numeric strings are coerced for int-backed enums
        } catch (\TypeError) {
            $case = null;
        }

        return $case ?? throw new InvalidArgumentException("Invalid value '{$value}' for {$field}.");
    }
}
