<?php

namespace Acelle\Plugin\Smtp2go;

use App\Library\Facades\Hook;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider as Base;

class ServiceProvider extends Base
{
    public const PLUGIN_NAME = 'acelle/smtp2go';

    public function register(): void
    {
        // Translation hook in register() so AppServiceProvider's loop sees it
        // during boot. Do NOT also call $this->loadTranslationsFrom() in boot
        // — would override AppServiceProvider's namespace hint, making
        // dump-clones zombie files.
        Hook::add('add_translation_file', fn () => [
            'id'                      => '#acelle/smtp2go_translation_file',
            'plugin_name'             => self::PLUGIN_NAME,
            'file_title'              => 'Translation for acelle/smtp2go plugin',
            'translation_folder'      => storage_path('app/data/plugins/acelle/smtp2go/lang/'),
            'translation_prefix'      => 'smtp2go',
            'file_name'               => 'messages.php',
            'master_translation_file' => realpath(__DIR__ . '/../resources/lang/en/messages.php'),
        ]);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'smtp2go');
        $this->loadRoutesFrom(__DIR__ . '/../routes.php');

        Hook::add('register_sending_server_driver', fn () => [
            'type'         => Smtp2goDriver::TYPE,
            'driver'       => Smtp2goDriver::class,
            'config_keys'  => ['smtp2go_api_key', 'smtp2go_webhook_id', 'smtp2go_webhook_user', 'smtp2go_webhook_pass'],
            // Credentials — encrypted at rest by SendingServer::setConfig().
            // The webhook id and basic-auth USER are identifiers, not secrets.
            'secret_config_keys' => ['smtp2go_api_key', 'smtp2go_webhook_pass'],
            'name'         => 'SMTP2GO',
            'description'  => 'Send via SMTP2GO (smtp2go.com) — REST API with auto-registered webhook',
            'icon_url'     => route('plugin.acelle.smtp2go.icon'),
        ]);

        Hook::set('icon_url_' . self::PLUGIN_NAME, fn () => route('plugin.acelle.smtp2go.icon'));

        Hook::on('delete_plugin_' . self::PLUGIN_NAME, function () {
            foreach (\App\Model\SendingServer::where('type', Smtp2goDriver::TYPE)->get() as $s) {
                try {
                    $s->driver()->teardownWebhook();
                } catch (\Throwable $e) {
                    Log::warning('SMTP2GO plugin delete: webhook teardown failed', [
                        'server_id' => $s->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
            \App\Model\SendingServer::where('type', Smtp2goDriver::TYPE)->forceDelete();
        });
    }
}
