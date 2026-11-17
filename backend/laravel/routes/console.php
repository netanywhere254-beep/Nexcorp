<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('nexora:about', function () {
    $this->comment('Nexora primary Laravel application foundation.');
})->purpose('Display basic Nexora application information.');
