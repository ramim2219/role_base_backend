<?php

use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\MenuController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CompanyTypeController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\MenuAllocationController;
use App\Http\Controllers\Api\UserTypeController;
use App\Http\Controllers\Api\UserDetailController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\AttributeController;
use App\Http\Controllers\Api\AttributeValueController;

// Public
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
});

// routes/api.php — outside auth:sanctum
Route::get('public/user-types', [UserTypeController::class, 'getAll']);

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
        Route::get   ('/get_assigned_menus',       [MenuAllocationController::class, 'getAssignedMenus']);
        Route::get('/get_my_menus', [MenuAllocationController::class, 'getMyMenus']);
        Route::get('/get_assignable_menus', [MenuAllocationController::class, 'getAssignableMenus']);
    });

    Route::prefix('User')->group(function () {
        Route::get   ('/get_user_by_id',          [UserController::class, 'getUserById']);
        Route::get   ('/get_my_users',            [UserController::class, 'getMyUsers']);
        Route::post  ('/save_user',               [UserController::class, 'saveUser']);
        Route::put   ('/update_user',             [UserController::class, 'updateUser']);
        Route::delete('/delete_user',             [UserController::class, 'deleteUser']);
        Route::get   ('/get_users_by_my_types',   [UserController::class, 'getUsersByMyUserTypes']);
        Route::get   ('/get_grouped_by_my_types', [UserController::class, 'getGroupedByMyUserTypes']);
    });

    Route::prefix('CompanyType')->group(function () {
        Route::get   ('/get_all',   [CompanyTypeController::class, 'getAll']);
        Route::get   ('/get_by_id', [CompanyTypeController::class, 'getById']);
        Route::post  ('/save',      [CompanyTypeController::class, 'save']);
        Route::put   ('/update',    [CompanyTypeController::class, 'update']);
        Route::delete('/delete',    [CompanyTypeController::class, 'delete']);
        Route::get('/get_company_type_by_createdby', [CompanyTypeController::class, 'getByCreatedBy']);
    });

    Route::prefix('Company')->group(function () {
        Route::get   ('/get_all',   [CompanyController::class, 'getAll']);
        Route::get   ('/get_by_id', [CompanyController::class, 'getById']);
        Route::post  ('/save',      [CompanyController::class, 'save']);
        Route::put   ('/update',    [CompanyController::class, 'update']);
        Route::delete('/delete',    [CompanyController::class, 'delete']);
        Route::get('/get_company_by_createdby', [CompanyController::class, 'getByCreatedBy']);
    });
    Route::prefix('UserType')->group(function () {
        Route::get   ('/get_all',   [UserTypeController::class, 'getAll']);
        Route::get   ('/get_by_id', [UserTypeController::class, 'getById']);
        Route::post  ('/save',      [UserTypeController::class, 'save']);
        Route::put   ('/update',    [UserTypeController::class, 'update']);
        Route::delete('/delete',    [UserTypeController::class, 'delete']);
        Route::get   ('/get_user_type_by_createdby',  [UserTypeController::class, 'getByCreatedBy']);
    });

    Route::prefix('UserDetail')->group(function () {
        Route::get   ('/get_userDetails_by_userid',         [UserDetailController::class, 'getByUserId']);
        Route::get   ('/get_userDetails_by_userid_creator', [UserDetailController::class, 'getByUserIdCreator']);
        Route::get   ('/get_all_userDetails',               [UserDetailController::class, 'getAll']);
        Route::match (['put', 'post'], '/update_userDetails', [UserDetailController::class, 'update']);
        Route::delete('/delete_userDetails',                [UserDetailController::class, 'delete']);
        Route::post  ('/save_userDetails',                  [UserDetailController::class, 'save']);
    });

    Route::prefix('Category')->group(function () {
        Route::get   ('/get_all',         [CategoryController::class, 'getAll']);
        Route::get   ('/get_by_company',  [CategoryController::class, 'getByCompany']);
        Route::get   ('/get_by_id',       [CategoryController::class, 'getById']);
        Route::post  ('/save',            [CategoryController::class, 'save']);
        Route::match (['put', 'post'], '/update', [CategoryController::class, 'update']);
        Route::delete('/delete',          [CategoryController::class, 'delete']);
    });

    Route::prefix('Brand')->group(function () {
        Route::get   ('/get_all',         [BrandController::class, 'getAll']);
        Route::get   ('/get_by_category', [BrandController::class, 'getByCategory']);
        Route::get   ('/get_by_company',  [BrandController::class, 'getByCompany']);
        Route::get   ('/get_by_id',       [BrandController::class, 'getById']);
        Route::post  ('/save',            [BrandController::class, 'save']);
        Route::match (['put', 'post'], '/update', [BrandController::class, 'update']);
        Route::delete('/delete',          [BrandController::class, 'delete']);
    });

    Route::prefix('Unit')->group(function () {
        Route::get   ('/get_all',         [UnitController::class, 'getAll']);
        Route::get   ('/get_by_id',       [UnitController::class, 'getById']);
        Route::post  ('/save',            [UnitController::class, 'save']);
        Route::match (['put', 'post'], '/update', [UnitController::class, 'update']);
        Route::delete('/delete',          [UnitController::class, 'delete']);
    });
    Route::prefix('Attribute')->group(function () {
        Route::get   ('/get_all',    [AttributeController::class, 'getAll']);
        Route::get   ('/get_by_id',  [AttributeController::class, 'getById']);
        Route::post  ('/save',       [AttributeController::class, 'save']);
        Route::put   ('/update',     [AttributeController::class, 'update']);
        Route::delete('/delete',     [AttributeController::class, 'delete']);
    });

    Route::prefix('AttributeValue')->group(function () {
        Route::get   ('/get_by_attribute', [AttributeValueController::class, 'getByAttribute']);
        Route::post  ('/save',             [AttributeValueController::class, 'save']);
        Route::put   ('/update',           [AttributeValueController::class, 'update']);
        Route::delete('/delete',           [AttributeValueController::class, 'delete']);
    });
});