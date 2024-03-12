<?php

use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Controllers\IssueController;
use G4T\Swagger\Swagger;
use Illuminate\Support\Facades\Route;

$url = config("swagger.url");
$issues_url = config("swagger.issues_url");
    Route::get("/$url", [DocumentationController::class, "showViewDocumentation"]);
    Route::get("/$url/json", [DocumentationController::class, "showJsonDocumentation"])->name("swagger.json");
    Route::get("/$issues_url", [IssueController::class, "index"]);
