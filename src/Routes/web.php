<?php

use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Controllers\IssueController;
use G4T\Swagger\Middleware\BasicAuthentication;
use Illuminate\Support\Facades\Route;

$url = trim((string) config('swagger.url'), '/');
$issues_url = trim((string) config('swagger.issues_url'), '/');
$middleware = array_merge((array) config('swagger.middleware', []), [BasicAuthentication::class]);

Route::middleware($middleware)->group(function () use ($url, $issues_url) {
    Route::get("/$url", [DocumentationController::class, 'showViewDocumentation']);
    Route::get("/$url/json", [DocumentationController::class, 'showJsonDocumentation'])->name('swagger.json');
    Route::get("/$issues_url", [IssueController::class, 'index']);
});
