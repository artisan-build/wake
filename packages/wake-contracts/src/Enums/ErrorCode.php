<?php

declare(strict_types=1);

namespace ArtisanBuild\WakeContracts\Enums;

enum ErrorCode: string
{
    case Unauthorized = 'unauthorized';
    case Forbidden = 'forbidden';
    case PayloadTooLarge = 'payload_too_large';
    case ValidationFailed = 'validation_failed';
    case UnsupportedEnvelopeVersion = 'unsupported_envelope_version';
    case RateLimited = 'rate_limited';
}
