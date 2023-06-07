<?php

use G4T\Swagger\Controllers\DocumentationController;
use G4T\Swagger\Swagger;
use Illuminate\Support\Facades\Route;

$url = config("swagger.url");
    Route::get("/$url", [DocumentationController::class, "showViewDocumentation"]);
    Route::get("/$url/json", [DocumentationController::class, "showJsonDocumentation"])->name("swagger.json");

