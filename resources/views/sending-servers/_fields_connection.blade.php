{{-- SMTP2GO — Connection tab fields --}}

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('smtp2go::messages.fields.api_key') }} <span class="mc-form-required">*</span></label>
    <div style="position:relative;">
        <input type="password"
               name="smtp2go_api_key"
               value="{{ old('smtp2go_api_key', $server->getConfig('smtp2go_api_key')) }}"
               placeholder="api-XXXX..."
               class="mc-form-input @error('smtp2go_api_key') mc-form-input-error @enderror"
               id="smtp2go-api-key-input"
               {{ isset($disabled) && $disabled ? 'disabled' : '' }}>
        <button type="button" class="mc-password-toggle"
                onclick="togglePassword('smtp2go-api-key-input', this)"
                style="position:absolute;right:var(--space-2);top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--color-text-muted);padding:var(--space-1);">
            <span class="material-symbols-rounded" style="font-size:18px;">visibility</span>
        </button>
    </div>
    @error('smtp2go_api_key') <div class="mc-form-error">{{ $message }}</div> @enderror
    <div class="mc-form-help">{{ trans('smtp2go::messages.fields.api_key_help') }}</div>
</div>

<div class="mc-form-group">
    <label class="mc-form-label">{{ trans('smtp2go::messages.fields.webhook_url') }}</label>
    <input type="text"
           value="{{ $server->id ? $server->driver()->webhookUrl() : trans('smtp2go::messages.fields.webhook_url_after_save') }}"
           class="mc-form-input"
           readonly>
    <div class="mc-form-help">
        @php $webhookId = $server->id ? $server->getConfig('smtp2go_webhook_id') : null; @endphp
        @if ($webhookId)
            <span style="color:var(--color-success);">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">check_circle</span>
                {{ trans('smtp2go::messages.fields.webhook_status_active', ['id' => $webhookId]) }}
            </span>
        @elseif ($server->id)
            <span style="color:var(--color-text-muted);">
                <span class="material-symbols-rounded" style="font-size:14px;vertical-align:-2px;">schedule</span>
                {{ trans('smtp2go::messages.fields.webhook_status_pending') }}
            </span>
        @else
            {{ trans('smtp2go::messages.fields.webhook_url_help') }}
        @endif
    </div>
</div>
