<?php

use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\ChallengeController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\CredentialEnrollmentController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\CredentialRemovalController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\EnrollmentController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\OtherSessionsController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\RecoveryCodeRegenerationController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\RecoveryCodesController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SecurityController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SessionRevocationController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignInController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SignOutController;
use ClaudioDekker\Keystone\Tests\Fixtures\Http\Controllers\SudoController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->group(function () {
    Route::get('login', [SignInController::class, 'show'])->name('login');
    Route::post('login/{type}', [SignInController::class, 'store'])->name('login.submit');
    Route::get('login/challenge', [ChallengeController::class, 'show'])->name('login.challenge');
    Route::post('login/challenge/{type}', [ChallengeController::class, 'store'])->name('login.challenge.submit');
    Route::delete('login/challenge', [ChallengeController::class, 'destroy'])->name('login.challenge.cancel');
    Route::get('login/enrollment', [EnrollmentController::class, 'show'])->name('login.enrollment');
    Route::get('login/enrollment/{type}', [EnrollmentController::class, 'create'])->name('login.enrollment.start');
    Route::post('login/enrollment/{type}', [EnrollmentController::class, 'store'])->name('login.enrollment.submit');
    Route::delete('login/enrollment', [EnrollmentController::class, 'destroy'])->name('login.enrollment.cancel');
    Route::get('login/recovery-codes', [RecoveryCodesController::class, 'show'])->name('login.recovery-codes');
    Route::post('login/recovery-codes/confirm', [RecoveryCodesController::class, 'store'])->name('login.recovery-codes.submit');
    Route::post('logout', SignOutController::class)->name('logout');
    Route::get('sudo', [SudoController::class, 'show'])->name('sudo');
    Route::post('sudo/{type}', [SudoController::class, 'store'])->name('sudo.submit');
    Route::delete('sudo', [SudoController::class, 'destroy'])->name('sudo.end');
    Route::get('settings/security', [SecurityController::class, 'show'])->name('security');
    Route::get('settings/security/enroll/{type}', [CredentialEnrollmentController::class, 'create'])->name('security.enroll');
    Route::post('settings/security/enroll/{type}', [CredentialEnrollmentController::class, 'store'])->name('security.enroll.submit');
    Route::delete('settings/security/enroll/{type}', [CredentialEnrollmentController::class, 'destroy'])->name('security.enroll.cancel');
    Route::get('settings/security/credentials/{credential}/remove', [CredentialRemovalController::class, 'show'])->name('security.credentials.remove');
    Route::delete('settings/security/credentials/{credential}', [CredentialRemovalController::class, 'destroy'])->name('security.credentials.remove.submit');
    Route::get('settings/security/recovery-codes/regenerate', [RecoveryCodeRegenerationController::class, 'create'])->name('security.recovery-codes.regenerate');
    Route::post('settings/security/recovery-codes/regenerate', [RecoveryCodeRegenerationController::class, 'store'])->name('security.recovery-codes.regenerate.confirm');
    Route::delete('settings/security/recovery-codes/regenerate', [RecoveryCodeRegenerationController::class, 'destroy'])->name('security.recovery-codes.regenerate.cancel');
    Route::get('settings/security/sessions/others/revoke', [OtherSessionsController::class, 'show'])->name('security.sessions.others.revoke');
    Route::delete('settings/security/sessions/others', [OtherSessionsController::class, 'destroy'])->name('security.sessions.others.revoke.submit');
    Route::get('settings/security/sessions/{session}/revoke', [SessionRevocationController::class, 'show'])->name('security.sessions.revoke');
    Route::delete('settings/security/sessions/{session}', [SessionRevocationController::class, 'destroy'])->name('security.sessions.revoke.submit');
});

Route::get('.well-known/change-password', fn () => to_route('security'))->name('well-known.change-password');
