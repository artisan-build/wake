<?php

declare(strict_types=1);

namespace App\View\Components;

use ArtisanBuild\BuiltForCloud\View\Layout;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\View;

final class WakeLayout extends Layout
{
    public function render(): ViewContract
    {
        return View::make('layouts.wake');
    }
}
