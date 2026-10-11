<?php

declare(strict_types=1);

namespace App\Audit;

use ArtisanBuild\BuiltForCloud\Audit\AppAction;

enum WakeAction: string implements AppAction
{
    case AppLogsDeleted = 'app-logs-deleted';
}
