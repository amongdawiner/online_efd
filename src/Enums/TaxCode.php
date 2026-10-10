<?php

namespace OnlineEfd\Enums;

enum TaxCode: int
{
    case Standard = 1;       // VAT rate A
    case Special = 2;        // B
    case Zero = 3;           // C
    case SpecialRelief = 4;  // D
    case Exempt = 5;         // E

    public function vatRate(): string
    {
        return match ($this) {
            self::Standard => 'A',
            self::Special => 'B',
            self::Zero => 'C',
            self::SpecialRelief => 'D',
            self::Exempt => 'E',
        };
    }
}
