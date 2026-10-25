<?php

use G4T\Swagger\Controllers\DashboardController;
use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Controllers\IssueController;
use G4T\Swagger\Middleware\BasicAuthentication;
use G4T\Swagger\Middleware\DashboardAuthenticate;
use Illuminate\Support\Facades\Route;

$url = trim((string) config('swagger.url'), '/');
$issues_url = trim((string) config('swagger.issues_url'), '/');
$middleware = array_merge((array) config('swagger.middleware', []), [BasicAuthentication::class]);

Route::middleware($middleware)->group(function () use ($url, $issues_url) {
    Route::get("/$url", [DocumentationController::class, 'showViewDocumentation']);
    Route::get("/$url/json", [DocumentationController::class, 'showJsonDocumentation'])->name('swagger.json');
    Route::get("/$issues_url", [IssueController::class, 'index']);
});

// Settings dashboard: only registered when swagger.dashboard.username and password are set.
if (DashboardController::available()) {
    $dashboard = DashboardController::url();

    Route::middleware((array) config('swagger.dashboard.middleware', ['web']))->group(function () use ($dashboard) {
        Route::get("/$dashboard", [DashboardController::class, 'show'])->name('swagger.dashboard');
        Route::get("/$dashboard/{page}", [DashboardController::class, 'show'])
            ->where('page', implode('|', array_diff(DashboardController::PAGES, ['overview'])))
            ->name('swagger.dashboard.page');
        Route::post("/$dashboard/login", [DashboardController::class, 'login']);

        Route::middleware(DashboardAuthenticate::class)->group(function () use ($dashboard) {
            Route::post("/$dashboard/logout", [DashboardController::class, 'logout']);
            Route::get("/$dashboard/api/settings", [DashboardController::class, 'settings']);
            Route::put("/$dashboard/api/settings", [DashboardController::class, 'save']);
            Route::delete("/$dashboard/api/settings", [DashboardController::class, 'reset']);
        });
    });
}
