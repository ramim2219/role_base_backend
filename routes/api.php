<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

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
        Route::post  ('/typewise_menu_allocation', [MenuController::class, 'assignMenu']);
        Route::delete('/remove_menu_allocation',   [MenuController::class, 'unassignMenu']);
    });

    Route::prefix('User')->group(function () {
        Route::get   ('/get_user_by_id', [UserController::class, 'getUserById']);
        Route::get   ('/get_my_users',   [UserController::class, 'getMyUsers']);
        Route::put   ('/update_user',    [UserController::class, 'updateUser']);
        Route::delete('/delete_user',    [UserController::class, 'deleteUser']);
    });
});