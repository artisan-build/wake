<?php

declare(strict_types=1);

namespace App\Authorization;

enum WakeCredentialAbility: string
{
    case Read = 'wake.read';
}
