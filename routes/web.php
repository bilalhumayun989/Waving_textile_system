<?php

use App\Http\Controllers\WorkspaceController;
use App\Http\Controllers\AdminManagementController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/login', fn () => Inertia::render('Login', ['demo' => config('app.demo', false)]))->name('login');
    Route::post('/login', [WorkspaceController::class, 'login'])->middleware('throttle:5,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [WorkspaceController::class, 'logout'])->name('logout');
    Route::post('/profile', [WorkspaceController::class, 'profile'])->name('profile');
    Route::get('/admin-management', [AdminManagementController::class, 'index'])->name('admin-management.index');
    Route::post('/admin-management/users', [AdminManagementController::class, 'create'])->name('admin-management.create');
    Route::put('/admin-management/users/{user}/modules', [AdminManagementController::class, 'updateModules'])->name('admin-management.modules');
    Route::post('/actions/{action}', [WorkspaceController::class, 'store'])->name('actions.store');
    Route::get('/', [WorkspaceController::class, 'index'])->name('dashboard');
    Route::get('/{page}/{id?}', [WorkspaceController::class, 'index'])->where('id', '[0-9]+')->name('workspace');
});
