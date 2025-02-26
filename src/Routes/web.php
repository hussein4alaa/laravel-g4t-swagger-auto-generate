<?php

use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Controllers\IssueController;
use G4T\Swagger\Middleware\BasicAuthentication;
use G4T\Swagger\Swagger;
use Illuminate\Support\Facades\Route;

$url = config("swagger.url");
$issues_url = config("swagger.issues_url");
Route::middleware(BasicAuthentication::class)->group(function () use ($url, $issues_url) {
    Route::get("/$url", [DocumentationController::class, "showViewDocumentation"]);
    Route::get("/$url/json", [DocumentationController::class, "showJsonDocumentation"])->name("swagger.json");
    Route::get("/$issues_url", [IssueController::class, "index"]);
});
Route::get("/swagger/documentation/testing", [DocumentationController::class, "testing"]);
