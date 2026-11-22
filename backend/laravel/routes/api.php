<?php

use App\Http\Controllers\ReplicationInboxController;
use Illuminate\Support\Facades\Route;

Route::post('/replication/events', [ReplicationInboxController::class, 'store'])
    ->middleware('throttle:120,1');
