<?php

namespace OnlineEfd\Enums;

enum PaymentType: string
{
    case Cash = 'CASH';
    case Cheque = 'CHEQUE';
    case CreditCard = 'CCARD';
    case EMoney = 'EMONEY';
    case Invoice = 'INVOICE';
}
