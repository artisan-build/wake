<?php

declare(strict_types=1);

use ArtisanBuild\WakeContracts\IngestProtocol;
use ArtisanBuild\WakeServer\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

Route::post(IngestProtocol::PATH, IngestController::class)->name('wake.ingest');
