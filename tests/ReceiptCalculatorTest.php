<?php

namespace OnlineEfd\Tests;

use InvalidArgumentException;
use OnlineEfd\Data\ReceiptItem;
use OnlineEfd\Enums\TaxCode;
use OnlineEfd\Services\ReceiptCalculator;
use PHPUnit\Framework\TestCase;

class ReceiptCalculatorTest extends TestCase
{
    private array $rates = ['A' => 18, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];

    public function test_splits_inclusive_amount_into_net_and_vat(): void
    {
        $r = (new ReceiptCalculator)->calculate([new ReceiptItem('Ticket', 1, 118)], $this->rates);

        $this->assertSame('118.00', $r['total_incl']);
        $this->assertSame('100.00', $r['total_excl']);
        $this->assertSame(['net' => '100.00', 'tax' => '18.00'], $r['vat']['A']);
    }

    public function test_lines_always_add_up_exactly(): void
    {
        $items = [new ReceiptItem('A', 3, 33.33), new ReceiptItem('B', 1, 0.07)];
        $r = (new ReceiptCalculator)->calculate($items, $this->rates);

        $net = (float) $r['vat']['A']['net'];
        $tax = (float) $r['vat']['A']['tax'];
        $this->assertEqualsWithDelta((float) $r['total_incl'], $net + $tax, 0.0001);
    }

    public function test_exempt_items_carry_no_vat(): void
    {
        $r = (new ReceiptCalculator)->calculate([new ReceiptItem('Fee', 2, 50, TaxCode::Exempt)], $this->rates);

        $this->assertSame(['net' => '100.00', 'tax' => '0.00'], $r['vat']['E']);
        $this->assertSame('0.00', $r['vat']['A']['net']);
        $this->assertSame(5, $r['lines'][0]['taxcode']);
    }

    public function test_rejects_empty_receipts_and_zero_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReceiptCalculator)->calculate([], $this->rates);
    }

    public function test_rejects_zero_amount_items(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ReceiptCalculator)->calculate([new ReceiptItem('Free', 1, 0)], $this->rates);
    }
}
