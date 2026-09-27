<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanyTypeController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\MenuAllocationController;
use App\Http\Controllers\Api\UserTypeController;

// Public
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// Protected
Route::middleware('auth:sanctum')->group(function () {

    Route::prefix('auth')->group(function () {
        Route::post('logout',   [AuthController::class, 'logout']);
        Route::get ('me',       [AuthController::class, 'me']);
        Route::get ('me/menus', [AuthController::class, 'myMenus']);
    });

    Route::prefix('Menu')->group(function () {
        Route::post  ('/saveMenu',                 [MenuController::class, 'saveMenu']);
        Route::put   ('/update_menu',              [MenuController::class, 'updateMenu']);
        Route::delete('/delete_menu',              [MenuController::class, 'deleteMenu']);
        Route::get   ('/get_all_menus',            [MenuController::class, 'viewAllMenu']);
        Route::get   ('/get_menus_by_id',          [MenuController::class, 'viewMenusById']);
    });
    Route::prefix('MenuAllocation')->group(function () {
        Route::post  ('/typewise_menu_allocation', [MenuAllocationController::class, 'assignMenu']);
        Route::delete('/remove_menu_allocation',   [MenuAllocationController::class, 'unassignMenu']);
        Route::get   ('/get_all',                  [MenuAllocationController::class, 'getAll']);
    });

    Route::prefix('User')->group(function () {
        Route::get   ('/get_user_by_id', [UserController::class, 'getUserById']);
        Route::get   ('/get_my_users',   [UserController::class, 'getMyUsers']);
        Route::put   ('/update_user',    [UserController::class, 'updateUser']);
        Route::delete('/delete_user',    [UserController::class, 'deleteUser']);
    });

    Route::prefix('CompanyType')->group(function () {
        Route::get   ('/get_all',   [CompanyTypeController::class, 'getAll']);
        Route::get   ('/get_by_id', [CompanyTypeController::class, 'getById']);
        Route::post  ('/save',      [CompanyTypeController::class, 'save']);
        Route::put   ('/update',    [CompanyTypeController::class, 'update']);
        Route::delete('/delete',    [CompanyTypeController::class, 'delete']);
    });

    Route::prefix('Company')->group(function () {
        Route::get   ('/get_all',   [CompanyController::class, 'getAll']);
        Route::get   ('/get_by_id', [CompanyController::class, 'getById']);
        Route::post  ('/save',      [CompanyController::class, 'save']);
        Route::put   ('/update',    [CompanyController::class, 'update']);
        Route::delete('/delete',    [CompanyController::class, 'delete']);
    });

    Route::prefix('UserType')->group(function () {
        Route::get   ('/get_all',   [UserTypeController::class, 'getAll']);
        Route::get   ('/get_by_id', [UserTypeController::class, 'getById']);
        Route::post  ('/save',      [UserTypeController::class, 'save']);
        Route::put   ('/update',    [UserTypeController::class, 'update']);
        Route::delete('/delete',    [UserTypeController::class, 'delete']);
    });
});