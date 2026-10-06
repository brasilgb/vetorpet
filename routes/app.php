<?php

use App\Http\Controllers\AuxiliaryAppController;
use App\Http\Controllers\CampaignController;
use App\Http\Controllers\CommercialConditionController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OtherSettingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PerformanceReportController;
use App\Http\Controllers\PestControl\CatalogController as PestControlCatalogController;
use App\Http\Controllers\PestControl\ControlPointController as PestControlControlPointController;
use App\Http\Controllers\PestControl\DashboardController as PestControlDashboardController;
use App\Http\Controllers\PestControl\EstablishmentController as PestControlEstablishmentController;
use App\Http\Controllers\PestControl\LookupController as PestControlLookupController;
use App\Http\Controllers\PestControl\OperatorController as PestControlOperatorController;
use App\Http\Controllers\PestControl\PestSpeciesController as PestControlPestSpeciesController;
use App\Http\Controllers\PestControl\ProductController as PestControlProductController;
use App\Http\Controllers\PestControl\VisitController as PestControlVisitController;
use App\Http\Controllers\PestControl\VisitInspectionController as PestControlVisitInspectionController;
use App\Http\Controllers\PestControl\VisitMediaController as PestControlVisitMediaController;
use App\Http\Controllers\PestControl\VisitSignatureController as PestControlVisitSignatureController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductRegionPriceController;
use App\Http\Controllers\RegionController;
use App\Http\Controllers\SalesIntelligenceController;
use App\Http\Controllers\SampleDataController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TenantFeedbackEntryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VisitController;
use App\Models\TenantModule;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
Route::get('/dashboard/export', [DashboardController::class, 'export'])->name('dashboard.export');
Route::get('/reports/sales', [PerformanceReportController::class, 'sales'])->name('reports.sales');
Route::get('/reports/sellers', [PerformanceReportController::class, 'sellers'])->name('reports.sellers');
Route::get('/reports/expenses', [PerformanceReportController::class, 'expenses'])->name('reports.expenses');
Route::get('/reports/expenses/pdf', [PerformanceReportController::class, 'expenseReport'])->name('reports.expenses.pdf');
Route::get('/subscription', [SubscriptionController::class, 'index'])->name('subscription.index');
Route::patch('/subscription', [SubscriptionController::class, 'update'])->name('subscription.update');
Route::post('/subscription/pix', [PaymentController::class, 'generatePix'])->name('subscription.pix');
Route::get('/subscription/payments/{paymentId}', [PaymentController::class, 'status'])->name('subscription.payment-status');
Route::get('/company', [CompanyController::class, 'index'])->name('company.index');
Route::patch('/company', [CompanyController::class, 'update'])->name('company.update');
Route::get('/other-settings', [OtherSettingController::class, 'index'])->name('other-settings.index');
Route::patch('/other-settings/admin-flex', [OtherSettingController::class, 'updateAdminFlex'])->name('other-settings.admin-flex.update');
Route::get('/auxiliary-apps', [AuxiliaryAppController::class, 'index'])->name('auxiliary-apps.index');
Route::post('/sample-data', [SampleDataController::class, 'store'])->name('sample-data.store');
Route::delete('/sample-data', [SampleDataController::class, 'destroy'])->name('sample-data.destroy');
Route::get('/feedback', [TenantFeedbackEntryController::class, 'index'])->name('feedback.index');
Route::post('/feedback', [TenantFeedbackEntryController::class, 'store'])->name('feedback.store');
Route::resource('/customers', CustomerController::class);
Route::resource('/regions', RegionController::class);
Route::resource('/visits', VisitController::class);
Route::resource('/expenses', ExpenseController::class);
Route::patch('/visits/{visit}/check-in', [VisitController::class, 'checkIn'])->name('visits.check-in');
Route::patch('/visits/{visit}/check-out', [VisitController::class, 'checkOut'])->name('visits.check-out');
Route::get('/intelligence', [SalesIntelligenceController::class, 'index'])->name('intelligence.index');
Route::resource('/campaigns', CampaignController::class);
Route::get('/commissions', [CommercialConditionController::class, 'commissions'])->name('commissions.index');
Route::get('/commissions/report', [CommercialConditionController::class, 'commissionReport'])->name('commissions.report');
Route::resource('/commercial-conditions', CommercialConditionController::class);
Route::get('/orders/{order}/print', [OrderController::class, 'printOrder'])->name('orders.print');
Route::resource('/orders', OrderController::class);
Route::get('/report', [OrderController::class, 'orderReport'])->name('orders.report');
Route::resource('/products', ProductController::class);
Route::patch('/products/{product}/adjust-stock', [ProductController::class, 'adjustStock'])->name('products.adjust-stock');
Route::post('/products/{product}/region-prices', [ProductRegionPriceController::class, 'store'])->name('products.region-prices.store');
Route::patch('/products/{product}/region-prices/{regionPrice}', [ProductRegionPriceController::class, 'update'])->name('products.region-prices.update');
Route::delete('/products/{product}/region-prices/{regionPrice}', [ProductRegionPriceController::class, 'destroy'])->name('products.region-prices.destroy');
Route::get('/settings', fn () => redirect()->route('app.other-settings.index'))->name('settings.index');
Route::resource('/users', UserController::class);
Route::get('/refproducts/{reference}', [ProductController::class, 'getProductsReference']);
Route::patch('/statusorder/{order}', [OrderController::class, 'setValueStatusOrder']);
Route::patch('/cancelorder/{order}', [OrderController::class, 'cancelOrder']);

// Controle de Pragas: toda a área abaixo exige o módulo ativo para o tenant.
// A permissão fina de cada usuário é checada dentro de cada controller
// (PestControlPermissions), já que resource routes não separam view/manage
// por verbo. Relatórios/dashboard chegam na Etapa 6.
Route::middleware('module:'.TenantModule::KEY_PEST_CONTROL)
    ->prefix('pest-control')
    ->name('pest-control.')
    ->group(function () {
        Route::get('/', [PestControlDashboardController::class, 'index'])->name('index');
        Route::get('/dashboard', [PestControlDashboardController::class, 'index'])->name('dashboard');
        Route::resource('/establishments', PestControlEstablishmentController::class);
        Route::resource('/points', PestControlControlPointController::class);
        Route::get('/catalog', [PestControlCatalogController::class, 'index'])->name('catalog.index');
        Route::post('/catalog/products', [PestControlProductController::class, 'store'])->name('catalog.products.store');
        Route::patch('/catalog/products/{product}', [PestControlProductController::class, 'update'])->name('catalog.products.update');
        Route::delete('/catalog/products/{product}', [PestControlProductController::class, 'destroy'])->name('catalog.products.destroy');
        Route::post('/catalog/species', [PestControlPestSpeciesController::class, 'store'])->name('catalog.species.store');
        Route::patch('/catalog/species/{species}', [PestControlPestSpeciesController::class, 'update'])->name('catalog.species.update');
        Route::delete('/catalog/species/{species}', [PestControlPestSpeciesController::class, 'destroy'])->name('catalog.species.destroy');
        Route::post('/catalog/lookups/{group}', [PestControlLookupController::class, 'store'])->name('catalog.lookups.store');
        Route::patch('/catalog/lookups/{lookup}', [PestControlLookupController::class, 'update'])->name('catalog.lookups.update');
        Route::delete('/catalog/lookups/{lookup}', [PestControlLookupController::class, 'destroy'])->name('catalog.lookups.destroy');
        Route::get('/operators', [PestControlOperatorController::class, 'index'])->name('operators.index');
        Route::get('/operators/create', [PestControlOperatorController::class, 'create'])->name('operators.create');
        Route::post('/operators', [PestControlOperatorController::class, 'store'])->name('operators.store');
        Route::get('/operators/{user}/edit', [PestControlOperatorController::class, 'edit'])->name('operators.edit');
        Route::put('/operators/{user}', [PestControlOperatorController::class, 'update'])->name('operators.update');

        // Visitas técnicas (Etapa 4): agenda, execução (check-in/check-out),
        // inspeção por ponto, evidências e assinatura/aceite.
        Route::resource('/visits', PestControlVisitController::class);
        Route::patch('/visits/{visit}/check-in', [PestControlVisitController::class, 'checkIn'])->name('visits.check-in');
        Route::patch('/visits/{visit}/check-out', [PestControlVisitController::class, 'checkOut'])->name('visits.check-out');
        Route::patch('/visits/{visit}/approve', [PestControlVisitController::class, 'approve'])->name('visits.approve');
        Route::patch('/visits/{visit}/cancel', [PestControlVisitController::class, 'cancel'])->name('visits.cancel');
        Route::post('/visits/{visit}/points/{point}/inspection', [PestControlVisitInspectionController::class, 'store'])->name('visits.inspections.store');
        Route::post('/visits/{visit}/signature', [PestControlVisitSignatureController::class, 'store'])->name('visits.signature.store');
        Route::post('/visits/{visit}/media', [PestControlVisitMediaController::class, 'store'])->name('visits.media.store');
        Route::delete('/visits/{visit}/media/{media}', [PestControlVisitMediaController::class, 'destroy'])->name('visits.media.destroy');
    });
