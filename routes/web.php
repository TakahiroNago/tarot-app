<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\ShippingSettingsController as AdminShippingSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\EcommerceStripeWebhookController;
use App\Http\Controllers\ReadingHistoryController;
use App\Http\Controllers\SharedReadingController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\ProductController as ShopProductController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TarotController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/tarot', [TarotController::class, 'index'])->name('tarot');

Route::middleware('ecommerce')->group(function () {
    Route::get('/shop/products', [ShopProductController::class, 'index'])->name('shop.products.index');
    Route::get('/shop/products/{product:slug}', [ShopProductController::class, 'show'])->name('shop.products.show');
    Route::middleware('active')->group(function () {
        Route::get('/cart', [CartController::class, 'index'])->name('shop.cart.index');
        Route::post('/cart/items', [CartController::class, 'store'])->name('shop.cart.store');
        Route::patch('/cart/items/{item}', [CartController::class, 'update'])->name('shop.cart.update');
        Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->name('shop.cart.destroy');
        Route::get('/checkout', [CheckoutController::class, 'index'])->name('shop.checkout.index');
        Route::get('/checkout/confirm', [CheckoutController::class, 'showConfirm']);
        Route::post('/checkout/confirm', [CheckoutController::class, 'confirm'])
            ->middleware('throttle:30,1')
            ->name('shop.checkout.confirm');
        Route::post('/checkout/edit', [CheckoutController::class, 'edit'])->name('shop.checkout.edit');
        Route::post('/checkout', [CheckoutController::class, 'store'])
            ->middleware('throttle:5,1')
            ->name('shop.checkout.store');
        Route::post('/checkout/pending/resume', [CheckoutController::class, 'resume'])
            ->middleware('throttle:5,1')
            ->name('shop.checkout.pending.resume');
        Route::post('/checkout/pending/cancel', [CheckoutController::class, 'cancelPending'])
            ->middleware('throttle:5,1')
            ->name('shop.checkout.pending.cancel');
    });
    Route::get('/checkout/cancelled', [CheckoutController::class, 'cancel'])->name('shop.checkout.cancel');
});

// Pages showing an existing order stay open while the shop is closed, so a customer can still see
// what they paid for. Each one shows only an order the visitor can prove is theirs.
Route::get('/checkout/complete/{orderNumber}', [CheckoutController::class, 'success'])
    ->middleware(['sensitive', 'throttle:30,1'])
    ->where('orderNumber', '[A-Z0-9-]{8,32}')
    ->name('shop.checkout.success');
Route::get('/orders/guest/{orderNumber}', [OrderController::class, 'guestShow'])
    ->middleware(['sensitive', 'signed:relative', 'throttle:30,1'])
    ->where('orderNumber', '[A-Z0-9-]{8,32}')
    ->name('shop.orders.guest-show');

Route::post('/tarot/draw', [TarotController::class, 'draw'])->name('tarot.draw');
Route::post('/tarot/daily', [TarotController::class, 'daily'])->middleware('auth')->name('tarot.daily');

Route::get('/shared-readings/{token}', SharedReadingController::class)
    ->where('token', '[a-f0-9]{64}')
    ->name('readings.share');

Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/specified-commercial-transactions', 'legal.commercial-transactions')
    ->name('legal.commercial-transactions');
Route::get('/contact', [ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');
Route::post('/stripe/ecommerce/webhook', EcommerceStripeWebhookController::class)
    ->name('stripe.ecommerce.webhook');

Route::middleware('active')->group(function () {
    Route::get('/readings', [ReadingHistoryController::class, 'index'])->name('readings.index');
    Route::get('/readings/{reading}', [ReadingHistoryController::class, 'show'])->name('readings.show');
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/email/verify', function (Request $request) {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        return view('auth.verify-email');
    })->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route('home')->with('status', 'email-verified');
    })->middleware(['signed', 'throttle:6,1'])->name('verification.verify');

    Route::post('/email/verification-notification', function (Request $request) {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->route('home');
        }

        $request->user()->sendEmailVerificationNotification();

        return back()->with('status', 'verification-link-sent');
    })->middleware('throttle:6,1')->name('verification.send');
});

Route::middleware('guest')->group(function () {
    Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/auth/google', [GoogleAuthController::class, 'redirect'])->name('login.google');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('login.google.callback');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.update');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware(['auth', 'active'])
    ->name('logout');

Route::middleware(['auth', 'active', 'verified'])->prefix('account')->name('account.')->group(function () {
    Route::get('/settings', [AccountSettingsController::class, 'edit'])->name('settings.edit');
    Route::put('/settings', [AccountSettingsController::class, 'update'])->name('settings.update');
    Route::delete('/settings', [AccountSettingsController::class, 'destroy'])->name('settings.destroy');
    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->middleware('sensitive')->name('orders.show');
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'verified', 'admin'])->group(function () {
    Route::resource('products', AdminProductController::class)->except(['show']);
    Route::delete('/products/{product}/images/{image}', [AdminProductController::class, 'destroyImage'])
        ->name('products.images.destroy');
    Route::get('/categories', [AdminCategoryController::class, 'index'])->name('categories.index');
    Route::post('/categories', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::get('/orders', [AdminOrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->name('orders.show');
    Route::patch('/orders/{order}/shipment', [AdminOrderController::class, 'updateShipment'])->name('orders.shipment.update');
    Route::post('/orders/{order}/review/approve', [AdminOrderController::class, 'approveReview'])->name('orders.review.approve');
    Route::post('/orders/{order}/refund', [AdminOrderController::class, 'refund'])->name('orders.refund');
    Route::post('/orders/{order}/items/{item}/restock', [AdminOrderController::class, 'restock'])->name('orders.items.restock');
    Route::get('/shipping', [AdminShippingSettingsController::class, 'index'])->name('shipping.index');
    Route::post('/shipping/methods', [AdminShippingSettingsController::class, 'storeMethod'])->name('shipping.methods.store');
    Route::patch('/shipping/methods/{shippingMethod}', [AdminShippingSettingsController::class, 'updateMethod'])->name('shipping.methods.update');
    Route::post('/shipping/rates', [AdminShippingSettingsController::class, 'storeRate'])->name('shipping.rates.store');
    Route::put('/shipping/rates/{shippingRate}', [AdminShippingSettingsController::class, 'updateRate'])->name('shipping.rates.update');
    Route::post('/shipping/tax-rates', [AdminShippingSettingsController::class, 'storeTaxRate'])->name('shipping.tax-rates.store');
    Route::put('/shipping/tax-rates/{taxRate}', [AdminShippingSettingsController::class, 'updateTaxRate'])->name('shipping.tax-rates.update');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
    Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->name('users.password-reset');
});
