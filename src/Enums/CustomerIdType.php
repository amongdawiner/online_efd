<?php

namespace OnlineEfd\Enums;

enum CustomerIdType: int
{
    case Tin = 1;
    case DrivingLicense = 2;
    case VoterNumber = 3;
    case Passport = 4;
    case NationalId = 5;
    case None = 6;
}
