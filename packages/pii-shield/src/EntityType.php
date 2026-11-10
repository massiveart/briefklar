<?php

declare(strict_types=1);

namespace Briefklar\PiiShield;

enum EntityType: string
{
    case Person = 'PERSON';
    case Address = 'ADDRESS';
    case AtSvnr = 'SVNR';
    case Iban = 'IBAN';
    case TaxNumber = 'TAX_NUMBER';
    case FileNumber = 'FILE_NUMBER';
    case Email = 'EMAIL';
    case Phone = 'PHONE';
    case BirthDate = 'BIRTH_DATE';
    case LicensePlate = 'LICENSE_PLATE';

    // Mainly found by model-based detection (any country, any format).
    case IdNumber = 'ID_NUMBER';
    case CreditCard = 'CREDIT_CARD';
    case IpAddress = 'IP_ADDRESS';
    case Health = 'HEALTH';
    case Other = 'OTHER';
}
