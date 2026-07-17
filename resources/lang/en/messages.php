<?php

return [
    'name'        => 'SMTP2GO',
    'description' => 'Send via SMTP2GO (smtp2go.com) — REST API with auto-registered webhook',
    'intro'       => '<a href="https://smtp2go.com" target="_blank">SMTP2GO</a> is a reliable global SMTP relay + REST API. Generate a full-scope API key (begins with "api-") in Settings → API Keys.',

    'fields.api_key'                 => 'API Key',
    'fields.api_key_help'            => 'Your SMTP2GO API key (begins with "api-"). Generate one at https://app.smtp2go.com → Settings → API Keys, scope=full.',
    'fields.webhook_url'             => 'Webhook URL',
    'fields.webhook_url_help'        => 'The platform auto-registers this URL with SMTP2GO on first send. Bounce + spam events flow back via this URL.',
    'fields.webhook_url_after_save'  => '— Save the server first; the webhook URL will appear here after the row is created.',
    'fields.webhook_status_active'   => 'Webhook registered with SMTP2GO (id #:id)',
    'fields.webhook_status_pending'  => 'Will be auto-registered on first send',
];
