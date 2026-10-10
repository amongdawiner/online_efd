<?php

namespace OnlineEfd\Services;

use InvalidArgumentException;
use OnlineEfd\Data\ReceiptItem;
use OnlineEfd\Support\Money;

/**
 * Splits tax-inclusive line amounts into net + VAT per TRA rate letter.
 * net = gross / (1 + rate); tax = gross - net (so lines always add up exactly).
 */
class ReceiptCalculator
{
    /**
     * @param  list<ReceiptItem>  $items
     * @param  array<string, int|float>  $rates  percent per letter A..E
     * @return array{lines: list<array>, vat: array<string, array{net: string, tax: string}>, total_excl: string, total_incl: string}
     */
    public function calculate(array $items, array $rates): array
    {
        if ($items === []) {
            throw new InvalidArgumentException('A receipt needs at least one item.');
        }

        $buckets = array_fill_keys(['A', 'B', 'C', 'D', 'E'], ['net' => 0, 'tax' => 0]);
        $lines = [];
        $totalExcl = 0;
        $totalIncl = 0;

        foreach (array_values($items) as $index => $item) {
            $gross = Money::cents((float) $item->quantity * (float) $item->unitPrice);
            if ($gross <= 0) {
                throw new InvalidArgumentException("Item '{$item->description}' must have a positive amount.");
            }

            $letter = $item->taxCode->vatRate();
            $percent = (float) ($rates[$letter] ?? 0);
            $net = $percent > 0 ? (int) round($gross / (1 + $percent / 100)) : $gross;
            $tax = $gross - $net;

            $buckets[$letter]['net'] += $net;
            $buckets[$letter]['tax'] += $tax;
            $totalExcl += $net;
            $totalIncl += $gross;

            $lines[] = [
                'id' => $item->id ?? (string) ($index + 1),
                'desc' => $item->description,
                'qty' => Money::quantity($item->quantity),
                'taxcode' => $item->taxCode->value,
                'amt' => Money::format($gross),
            ];
        }

        return [
            'lines' => $lines,
            'vat' => array_map(fn (array $b) => [
                'net' => Money::format($b['net']),
                'tax' => Money::format($b['tax']),
            ], $buckets),
            'total_excl' => Money::format($totalExcl),
            'total_incl' => Money::format($totalIncl),
        ];
    }
}
