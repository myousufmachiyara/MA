<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;

use App\Http\Controllers\{
    DashboardController,
    SubHeadOfAccController,
    COAController,
    SaleOrderController,
    DispatchTripController,
    SaleInvoiceController,
    SettlementController,
    PurchaseOrderController,
    PurchaseInvoiceController,
    PurchaseReturnController,
    ProductController,
    UserController,
    MobileUserController,
    RoleController,
    AttributeController,
    ProductCategoryController,
    VoucherController,
    LocationController,
    StockTransferController,
    StockAdjustmentController,
    StockMovementController,
    InventoryReportController,
    PurchaseReportController,
    SalesReportController,
    AccountsReportController,
    SaleReturnController,
    PermissionController,
    ProductSubcategoryController,
};

Auth::routes();

Route::middleware(['auth'])->group(function () {

    // ─────────────────────────────────────────────────────────────
    // Dashboard
    // ─────────────────────────────────────────────────────────────
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    // ─────────────────────────────────────────────────────────────
    // Users (self-service + admin actions not covered by generic CRUD)
    // ─────────────────────────────────────────────────────────────
    Route::put('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
    Route::put('/users/{id}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggleActive');
    Route::post('/change-my-password', [UserController::class, 'changeMyPassword'])->name('users.changeMyPassword');

    Route::put('/mobile-users/{id}/toggle-active', [MobileUserController::class, 'toggleActive'])->name('mobile_users.toggleActive');
    Route::put('/mobile-users/{id}/reset-device', [MobileUserController::class, 'resetDevice'])->name('mobile_users.resetDevice');
    Route::get('/mobile-users/{id}/activity', [MobileUserController::class, 'activity'])->name('mobile_users.activity');

    // ─────────────────────────────────────────────────────────────
    // Chart of Accounts
    // ─────────────────────────────────────────────────────────────
    Route::put('/coa/{id}/toggle-active', [COAController::class, 'toggleActive'])->name('coa.toggleActive');

    // ─────────────────────────────────────────────────────────────
    // Product Helpers (AJAX)
    // ─────────────────────────────────────────────────────────────
    Route::get('/products/details', [ProductController::class, 'details'])->name('products.receiving');
    Route::get('/product/{product}/variations', [ProductController::class, 'getVariations'])->name('product.variations');
    Route::get('/get-subcategories/{category_id}', [ProductCategoryController::class, 'getSubcategories'])->name('products.getSubcategories');
    Route::get('/get-location-stock', [ProductController::class, 'getLocationStock']);

    // Legacy — remove this line if PurchaseInvoiceController::getProductInvoices()
    // doesn't actually exist in your current controller; it wasn't in the version shared with me.
    Route::get('/product/{product}/invoices', [PurchaseInvoiceController::class, 'getProductInvoices']);

    // ─────────────────────────────────────────────────────────────
    // Purchase Orders — not full CRUD (no show/print methods)
    // ─────────────────────────────────────────────────────────────
    Route::prefix('purchase_orders')->name('purchase_orders.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('check.permission:purchase_orders.index')->name('index');
        Route::get('/create', [PurchaseOrderController::class, 'create'])->middleware('check.permission:purchase_orders.create')->name('create');
        Route::post('/', [PurchaseOrderController::class, 'store'])->middleware('check.permission:purchase_orders.create')->name('store');
        Route::get('/{id}/edit', [PurchaseOrderController::class, 'edit'])->middleware('check.permission:purchase_orders.edit')->name('edit');
        Route::put('/{id}', [PurchaseOrderController::class, 'update'])->middleware('check.permission:purchase_orders.edit')->name('update');
        Route::delete('/{id}', [PurchaseOrderController::class, 'destroy'])->middleware('check.permission:purchase_orders.delete')->name('destroy');
        Route::put('/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('check.permission:purchase_orders.edit')->name('cancel');
        Route::get('/{id}/items', [PurchaseOrderController::class, 'getItems'])->middleware('check.permission:purchase_orders.index')->name('items');
    });

    // ─────────────────────────────────────────────────────────────
    // Purchase Invoices — not full CRUD (no show; has restore)
    // ─────────────────────────────────────────────────────────────
    Route::prefix('purchase_invoices')->name('purchase_invoices.')->group(function () {
        Route::get('/', [PurchaseInvoiceController::class, 'index'])->middleware('check.permission:purchase_invoices.index')->name('index');
        Route::get('/create', [PurchaseInvoiceController::class, 'create'])->middleware('check.permission:purchase_invoices.create')->name('create');
        Route::post('/', [PurchaseInvoiceController::class, 'store'])->middleware('check.permission:purchase_invoices.create')->name('store');
        Route::get('/{id}/edit', [PurchaseInvoiceController::class, 'edit'])->middleware('check.permission:purchase_invoices.edit')->name('edit');
        Route::put('/{id}', [PurchaseInvoiceController::class, 'update'])->middleware('check.permission:purchase_invoices.edit')->name('update');
        Route::delete('/{id}', [PurchaseInvoiceController::class, 'destroy'])->middleware('check.permission:purchase_invoices.delete')->name('destroy');
        Route::put('/{id}/restore', [PurchaseInvoiceController::class, 'restore'])->middleware('check.permission:purchase_invoices.edit')->name('restore');
        Route::get('/{id}/print', [PurchaseInvoiceController::class, 'print'])->middleware('check.permission:purchase_invoices.print')->name('print');
    });

    // ─────────────────────────────────────────────────────────────
    // Sale Orders (web oversight of mobile-booked orders) — no create/store/destroy
    // ─────────────────────────────────────────────────────────────
    Route::prefix('sale_orders')->name('sale_orders.')->group(function () {
        Route::get('/', [SaleOrderController::class, 'index'])->middleware('check.permission:sale_orders.index')->name('index');
        Route::get('/{id}/edit', [SaleOrderController::class, 'edit'])->middleware('check.permission:sale_orders.edit')->name('edit');
        Route::put('/{id}', [SaleOrderController::class, 'update'])->middleware('check.permission:sale_orders.edit')->name('update');
        Route::put('/{id}/cancel', [SaleOrderController::class, 'cancel'])->middleware('check.permission:sale_orders.edit')->name('cancel');
    });

    // ─────────────────────────────────────────────────────────────
    // Dispatch Trips — custom action set
    // ─────────────────────────────────────────────────────────────
    Route::prefix('dispatch_trips')->name('dispatch_trips.')->group(function () {
        Route::get('/', [DispatchTripController::class, 'index'])->middleware('check.permission:dispatch_trips.index')->name('index');
        Route::get('/create', [DispatchTripController::class, 'create'])->middleware('check.permission:dispatch_trips.create')->name('create');
        Route::post('/', [DispatchTripController::class, 'store'])->middleware('check.permission:dispatch_trips.create')->name('store');
        Route::get('/{id}', [DispatchTripController::class, 'show'])->middleware('check.permission:dispatch_trips.index')->name('show');
        Route::post('/{id}/add-orders', [DispatchTripController::class, 'addOrders'])->middleware('check.permission:dispatch_trips.edit')->name('addOrders');
        Route::delete('/{id}/orders/{orderId}', [DispatchTripController::class, 'removeOrder'])->middleware('check.permission:dispatch_trips.edit')->name('removeOrder');
        Route::post('/{id}/dispatch', [DispatchTripController::class, 'dispatch'])->middleware('check.permission:dispatch_trips.edit')->name('dispatch');
        Route::put('/{id}/cancel', [DispatchTripController::class, 'cancel'])->middleware('check.permission:dispatch_trips.edit')->name('cancel');

        // Settlement create/store hang off a trip ID, not a settlement ID
        Route::get('/{id}/settle', [SettlementController::class, 'create'])->middleware('check.permission:settlements.create')->name('settle');
        Route::post('/{id}/settle', [SettlementController::class, 'store'])->middleware('check.permission:settlements.create')->name('settle.store');
    });

    // ─────────────────────────────────────────────────────────────
    // Settlements
    // ─────────────────────────────────────────────────────────────
    Route::prefix('settlements')->name('settlements.')->group(function () {
        Route::get('/', [SettlementController::class, 'index'])->middleware('check.permission:settlements.index')->name('index');
        Route::get('/{id}', [SettlementController::class, 'show'])->middleware('check.permission:settlements.index')->name('show');
        Route::put('/{id}/clear', [SettlementController::class, 'clearToOffice'])->middleware('check.permission:settlements.edit')->name('clear');
    });

    // ─────────────────────────────────────────────────────────────
    // Sale Invoices — read-only, only ever generated via Dispatch Trip
    // ─────────────────────────────────────────────────────────────
    Route::prefix('sale_invoices')->name('sale_invoices.')->group(function () {
        Route::get('/', [SaleInvoiceController::class, 'index'])->middleware('check.permission:sale_invoices.index')->name('index');
        Route::get('/create', [SaleInvoiceController::class, 'create'])->middleware('check.permission:sale_invoices.create')->name('create');
        Route::post('/', [SaleInvoiceController::class, 'store'])->middleware('check.permission:sale_invoices.create')->name('store');
        Route::get('/{id}', [SaleInvoiceController::class, 'show'])->middleware('check.permission:sale_invoices.index')->name('show');
        Route::get('/{id}/edit', [SaleInvoiceController::class, 'edit'])->middleware('check.permission:sale_invoices.edit')->name('edit');
        Route::put('/{id}', [SaleInvoiceController::class, 'update'])->middleware('check.permission:sale_invoices.edit')->name('update');
        Route::delete('/{id}', [SaleInvoiceController::class, 'destroy'])->middleware('check.permission:sale_invoices.delete')->name('destroy');
        Route::get('/{id}/print', [SaleInvoiceController::class, 'print'])->middleware('check.permission:sale_invoices.print')->name('print');
    });

    // ─────────────────────────────────────────────────────────────
    // Sale Return — no edit/update; has AJAX helpers
    // ─────────────────────────────────────────────────────────────
    Route::prefix('sale_return')->name('sale_return.')->group(function () {
        Route::get('/', [SaleReturnController::class, 'index'])->middleware('check.permission:sale_return.index')->name('index');
        Route::get('/create', [SaleReturnController::class, 'create'])->middleware('check.permission:sale_return.create')->name('create');
        Route::post('/', [SaleReturnController::class, 'store'])->middleware('check.permission:sale_return.create')->name('store');
        Route::get('/{id}', [SaleReturnController::class, 'show'])->middleware('check.permission:sale_return.index')->name('show');
        Route::delete('/{id}', [SaleReturnController::class, 'destroy'])->middleware('check.permission:sale_return.delete')->name('destroy');
    });
    Route::get('/sale-returns/search-invoices', [SaleReturnController::class, 'searchInvoices'])->name('sale_returns.searchInvoices');
    Route::get('/sale-returns/invoice/{id}/items', [SaleReturnController::class, 'getInvoiceItems'])->name('sale_returns.invoiceItems');

    // ─────────────────────────────────────────────────────────────
    // Stock Management — Locations, Transfers, Adjustments, Movement ledger
    // ─────────────────────────────────────────────────────────────
    Route::prefix('locations')->name('locations.')->group(function () {
        Route::get('/', [LocationController::class, 'index'])->middleware('check.permission:locations.index')->name('index');
        Route::post('/', [LocationController::class, 'store'])->middleware('check.permission:locations.create')->name('store');
        Route::get('/{id}/edit', [LocationController::class, 'edit'])->middleware('check.permission:locations.edit')->name('edit');
        Route::put('/{id}', [LocationController::class, 'update'])->middleware('check.permission:locations.edit')->name('update');
        Route::put('/{id}/toggle-active', [LocationController::class, 'toggleActive'])->middleware('check.permission:locations.edit')->name('toggleActive');
        Route::delete('/{id}', [LocationController::class, 'destroy'])->middleware('check.permission:locations.delete')->name('destroy');
    });

    Route::prefix('stock_transfer')->name('stock_transfer.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->middleware('check.permission:stock_transfer.index')->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->middleware('check.permission:stock_transfer.create')->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->middleware('check.permission:stock_transfer.create')->name('store');
        Route::get('/{id}', [StockTransferController::class, 'show'])->middleware('check.permission:stock_transfer.index')->name('show');
    });

    Route::prefix('stock_adjustments')->name('stock_adjustments.')->group(function () {
        Route::get('/', [StockAdjustmentController::class, 'index'])->middleware('check.permission:stock_adjustments.index')->name('index');
        Route::get('/create', [StockAdjustmentController::class, 'create'])->middleware('check.permission:stock_adjustments.create')->name('create');
        Route::post('/', [StockAdjustmentController::class, 'store'])->middleware('check.permission:stock_adjustments.create')->name('store');
        Route::get('/{id}', [StockAdjustmentController::class, 'show'])->middleware('check.permission:stock_adjustments.index')->name('show');
        Route::delete('/{id}', [StockAdjustmentController::class, 'destroy'])->middleware('check.permission:stock_adjustments.delete')->name('destroy');
    });

    Route::prefix('stock-movements')->name('stock_movements.')->group(function () {
        Route::get('/', [StockMovementController::class, 'index'])->middleware('check.permission:stock_movements.index')->name('index');
        Route::get('/{itemId}', [StockMovementController::class, 'show'])->middleware('check.permission:stock_movements.index')->name('show');
    });

    // ─────────────────────────────────────────────────────────────
    // Generic CRUD modules — controllers here genuinely support the
    // full index/create/store/show/edit/update/destroy/print set
    // ─────────────────────────────────────────────────────────────
    $modules = [
        // User Management
        'roles' => ['controller' => RoleController::class, 'permission' => 'user_roles'],
        'permissions' => ['controller' => PermissionController::class, 'permission' => 'role_permissions'],
        'users' => ['controller' => UserController::class, 'permission' => 'users'],
        'mobile_users' => ['controller' => MobileUserController::class, 'permission' => 'mobile_users'],

        // Accounts
        'coa' => ['controller' => COAController::class, 'permission' => 'coa'],
        'shoa' => ['controller' => SubHeadOfAccController::class, 'permission' => 'shoa'],

        // Products
        'products' => ['controller' => ProductController::class, 'permission' => 'products'],
        'product_categories' => ['controller' => ProductCategoryController::class, 'permission' => 'product_categories'],
        'product_subcategories' => ['controller' => ProductSubcategoryController::class, 'permission' => 'product_subcategories'],
        'attributes' => ['controller' => AttributeController::class, 'permission' => 'attributes'],

        // Purchase Return — assumed full CRUD (unchanged from your original app)
        'purchase_return' => ['controller' => PurchaseReturnController::class, 'permission' => 'purchase_return'],

        // Vouchers
        'vouchers' => ['controller' => VoucherController::class, 'permission' => 'vouchers'],
    ];

    foreach ($modules as $uri => $config) {
        $controller = $config['controller'];
        $permission = $config['permission'];

        $param = $uri === 'roles' ? '{role}' : '{id}';

        if ($uri === 'vouchers') {
            Route::prefix("$uri/{type}")->group(function () use ($controller, $permission) {
                Route::get('/', [$controller, 'index'])->middleware("check.permission:$permission.index")->name("vouchers.index");
                Route::get('/create', [$controller, 'create'])->middleware("check.permission:$permission.create")->name("vouchers.create");
                Route::post('/', [$controller, 'store'])->middleware("check.permission:$permission.create")->name("vouchers.store");
                Route::get('/{id}', [$controller, 'show'])->middleware("check.permission:$permission.index")->name("vouchers.show");
                Route::get('/{id}/edit', [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("vouchers.edit");
                Route::put('/{id}', [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("vouchers.update");
                Route::delete('/{id}', [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("vouchers.destroy");
                Route::get('/{id}/print', [$controller, 'print'])->middleware("check.permission:$permission.print")->name('vouchers.print');
            });

            continue;
        }

        Route::get("$uri", [$controller, 'index'])->middleware("check.permission:$permission.index")->name("$uri.index");
        Route::get("$uri/create", [$controller, 'create'])->middleware("check.permission:$permission.create")->name("$uri.create");
        Route::post("$uri", [$controller, 'store'])->middleware("check.permission:$permission.create")->name("$uri.store");

        Route::get("$uri/$param", [$controller, 'show'])->middleware("check.permission:$permission.index")->name("$uri.show");
        Route::get("$uri/$param/edit", [$controller, 'edit'])->middleware("check.permission:$permission.edit")->name("$uri.edit");
        Route::put("$uri/$param", [$controller, 'update'])->middleware("check.permission:$permission.edit")->name("$uri.update");
        Route::delete("$uri/$param", [$controller, 'destroy'])->middleware("check.permission:$permission.delete")->name("$uri.destroy");
        Route::get("$uri/$param/print", [$controller, 'print'])->middleware("check.permission:$permission.print")->name("$uri.print");
    }

    // ─────────────────────────────────────────────────────────────
    // Reports (readonly)
    // ─────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('inventory', [InventoryReportController::class, 'inventoryReports'])->name('inventory');
        Route::get('inventory/movement', [InventoryReportController::class, 'stockMovement'])->name('inventory.movement');
        Route::get('inventory/item-ledger', [InventoryReportController::class, 'itemLedger'])->name('inventory.itemLedger');
        Route::get('inventory/item-ledger/{itemId}', [InventoryReportController::class, 'itemLedgerDetail'])->name('inventory.itemLedgerDetail');
        Route::get('inventory/by-location', [InventoryReportController::class, 'stockByLocation'])->name('inventory.byLocation');

        Route::get('purchase', [PurchaseReportController::class, 'purchaseReports'])->name('purchase');
        Route::get('sale', [SalesReportController::class, 'saleReports'])->name('sale');
        Route::get('accounts', [AccountsReportController::class, 'accounts'])->name('accounts');
    });
});