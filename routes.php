<?php

use Illuminate\Support\Facades\Route;

/*
 * SMTP2GO plugin routes.
 *
 * Admin/customer CRUD pages are handled centrally by the main app's
 * sending-server controllers via DriverRegistry. Webhook intake
 * (POST /webhook/{driverType}/{serverUid}) is also central —
 * SendingServerWebhookController → DriverRegistry::resolve() →
 * Smtp2goDriver::verifyWebhook + parseWebhook.
 *
 * The only thing this plugin owns is its picker icon. Serving it ourselves
 * keeps the plugin self-contained.
 */

Route::get('plugins/acelle/smtp2go/icon.svg', function () {
    $path = storage_path('app/plugins/acelle/smtp2go/icon.svg');
    abort_unless(file_exists($path), 404);
    return response()->file($path, [
        'Content-Type'  => 'image/svg+xml',
        'Cache-Control' => 'public, max-age=31536000',
    ]);
})->name('plugin.acelle.smtp2go.icon');
