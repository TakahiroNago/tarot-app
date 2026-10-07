<!-- routes/web.php -->

<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\Admin\UserController as AdminUserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ReadingHistoryController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TarotController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/tarot', [TarotController::class, 'index'])->name('tarot');

Route::post('/tarot/draw', [TarotController::class, 'draw'])->name('tarot.draw');

Route::view('/plans', 'plans')->name('plans');

Route::view('/terms', 'legal.terms')->name('legal.terms');
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/specified-commercial-transactions', 'legal.commercial-transactions')
    ->name('legal.commercial-transactions');
Route::view('/refund-credit-policy', 'legal.refund-credit-policy')
    ->name('legal.refund-credit-policy');
Route::get('/contact', [ContactController::class, 'create'])->name('contact.create');
Route::post('/contact', [ContactController::class, 'store'])->middleware('throttle:5,1')->name('contact.store');

Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware('active')->group(function () {
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])->middleware(['auth', 'verified'])->name('billing.checkout');
    Route::post('/billing/portal', [BillingController::class, 'portal'])->middleware(['auth', 'verified'])->name('billing.portal');
    Route::get('/readings', [ReadingHistoryController::class, 'index'])->middleware('verified')->name('readings.index');
    Route::get('/readings/{reading}', [ReadingHistoryController::class, 'show'])->middleware('verified')->name('readings.show');
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
});

Route::prefix('admin')->name('admin.')->middleware(['auth', 'active', 'verified', 'admin'])->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('users.show');
    Route::get('/users/{user}/edit', [AdminUserController::class, 'edit'])->name('users.edit');
    Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/suspend', [AdminUserController::class, 'suspend'])->name('users.suspend');
    Route::post('/users/{user}/password-reset', [AdminUserController::class, 'sendPasswordReset'])->name('users.password-reset');
});
