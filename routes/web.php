<?php

use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\Auth\AccountStatusController;
use App\Http\Controllers\Auth\DemoSignInController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\OAuthController;
use App\Http\Controllers\EventCalendarController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ImpersonationController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\MessengerAttachmentController;
use App\Http\Controllers\SocialAttachmentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('health');

Route::get('/locale/{locale}', LocaleController::class)->name('locale.switch');

// Account status — the only page for applicants and unconfirmed e-mails (ФО §6.1).
Route::middleware('auth')->group(function (): void {
    Route::get('/account', [AccountStatusController::class, 'show'])->name('account.status');
    Route::post('/account/logout', [AccountStatusController::class, 'logout'])->name('account.logout');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:3,1')
        ->name('verification.send');
    // CRM files (ТЗ §68): the services behind them decide who may download what.
    Route::get('/exports/{batch}/download', [ExportController::class, 'download'])->name('exports.download');
    Route::get('/imports/{batch}/report', [ExportController::class, 'importReport'])->name('imports.report');
    Route::get('/imports/template', [ExportController::class, 'importTemplate'])->name('imports.template');
    // Files of posts (ТЗ §68): only for those who see the post.
    Route::get('/social/attachments/{attachment}', SocialAttachmentController::class)->name('social.attachment');
    // Events (ФО §6.7): the .ics file of an event and the files attached to it — for those who see the event.
    Route::get('/events/{event}/ics', [EventCalendarController::class, 'ics'])->name('events.ics');
    Route::get('/events/attachments/{attachment}', [EventCalendarController::class, 'attachment'])->name('events.attachment');
    // Files of messages (ТЗ §68): for the members of the chat, after the antivirus check.
    Route::get('/messenger/attachments/{attachment}', MessengerAttachmentController::class)->name('messenger.attachment');
    // Confirmation of reading a critical notice (ФО §6.13).
    Route::post('/announcements/{announcement}/acknowledge', [AnnouncementController::class, 'acknowledge'])->name('announcements.acknowledge');
    // Return from an impersonation (Д-19).
    Route::post('/impersonation/leave', [ImpersonationController::class, 'leave'])->name('impersonation.leave');
});

// iCal feed for external calendars (ФО §6.7): no session, the secret token in the address is the key.
Route::get('/calendar/feed/{token}.ics', [EventCalendarController::class, 'feed'])
    ->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')->name('calendar.feed');

// Quick sign-in as a demo persona (Д-5). Never registered in production; the action checks again.
if (! app()->isProduction()) {
    Route::post('/demo/sign-in/{user}', DemoSignInController::class)->middleware('throttle:30,1')->name('demo.sign-in');
}

Route::get('/invitation/{token}', [InvitationController::class, 'show'])->middleware('throttle:30,1')->name('invitation.accept');
Route::post('/invitation/{token}', [InvitationController::class, 'accept'])->middleware('throttle:10,1')->name('invitation.store');

Route::middleware('throttle:30,1')->group(function (): void {
    Route::get('/auth/{provider}/redirect', [OAuthController::class, 'redirect'])->name('oauth.redirect');
    Route::get('/auth/{provider}/callback', [OAuthController::class, 'callback'])->name('oauth.callback');
    Route::get('/auth/two-factor', [OAuthController::class, 'twoFactorForm'])->name('oauth.two-factor');
    Route::post('/auth/two-factor', [OAuthController::class, 'twoFactor'])->middleware('throttle:5,1')->name('oauth.two-factor.verify');
});
