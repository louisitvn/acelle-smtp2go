<?php

namespace Acelle\Plugin\Smtp2go;

use Acelle\Plugin\Smtp2go\Smtp2go\Client;
use Acelle\Plugin\Smtp2go\Smtp2go\Error;
use App\Model\BounceLog;
use App\SendingServers\Capabilities\ProvidesConnectionFieldsView;
use App\SendingServers\Capabilities\ReceivesWebhooks;
use App\SendingServers\Capabilities\SupportsIdentitySync;
use App\SendingServers\Capabilities\SupportsRemoteDomainVerify;
use App\SendingServers\DomainVerification\CheckStatus;
use App\SendingServers\DomainVerification\DnsRecord;
use App\SendingServers\DomainVerification\RecordPurpose;
use App\SendingServers\DomainVerification\VerificationResult;
use App\SendingServers\Drivers\AbstractDriver;
use App\SendingServers\Drivers\SendResult;
use App\SendingServers\Drivers\TestResult;
use App\SendingServers\Identities\Identity;
use App\SendingServers\Identities\IdentityKind;
use App\SendingServers\Identities\IdentityStatus;
use App\SendingServers\Webhooks\BounceReceived;
use App\SendingServers\Webhooks\BounceType;
use App\SendingServers\Webhooks\ComplaintReceived;
use App\SendingServers\Webhooks\FeedbackType;
use App\SendingServers\Webhooks\IgnorableWebhookEvent;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * SMTP2GO driver — talks to SMTP2GO (https://smtp2go.com) via REST API v3.
 *
 * Capabilities: SendingDriver, ProvidesConnectionFieldsView, ReceivesWebhooks,
 *               SupportsIdentitySync, SupportsRemoteDomainVerify.
 *
 * Vendor config keys (registered in ServiceProvider):
 *   smtp2go_api_key       — `api-XXXX` API key (Settings → API Keys, scope=full)
 *   smtp2go_webhook_id    — auto-populated by setupBeforeSend
 *   smtp2go_webhook_user  — auto-generated Basic-auth username for webhook
 *                           verification (SMTP2GO doesn't sign HMAC; uses
 *                           HTTP Basic at register time)
 *   smtp2go_webhook_pass  — companion password
 *
 * Wire format (per developers.smtp2go.com):
 *   Auth:    header `X-Smtp2go-Api-Key: api-XXXX`
 *   Send:    POST /v3/email/send
 *              {sender, to[], subject, html_body, text_body, custom_headers[],
 *               attachments[], cc[], bcc[]}
 *              → 200 {request_id, data: {succeeded, failed, email_id}}
 *   Webhook register: POST /v3/webhook/add
 *              {url, events[], name, usernames[], auth_user, auth_pass}
 *   Webhook delete:   POST /v3/webhook/delete  {id}
 *   Webhook events:   processed, delivered, open, click, bounce, spam,
 *                     unsubscribe, resubscribe, reject
 *   Webhook payload (bounce):
 *              {event:"bounce", bounce:"hard"|"soft", time, sendtime,
 *               email_id, "message-id", rcpt, host, message,
 *               sender, from, ...}
 *   Domains: POST /v3/domain/view  {domain?}  → list or single
 *            POST /v3/domain/add   {domain}   → DNS records
 *            POST /v3/domain/verify {domain}  → re-check
 *            POST /v3/domain/remove {domain}
 *
 * runtime_message_id correlation:
 *   Send response's `data.email_id` matches webhook payload's `email_id`
 *   field. We store that.
 *
 * Webhook auth model:
 *   SMTP2GO does NOT sign webhooks. The supported verify-mechanism is HTTP
 *   Basic auth supplied at register time (`auth_user` + `auth_pass`).
 *   SMTP2GO sends `Authorization: Basic base64(user:pass)` on every POST.
 *   We generate user/pass once in setupBeforeSend and verify in verifyWebhook.
 */
class Smtp2goDriver extends AbstractDriver implements
    ProvidesConnectionFieldsView,
    ReceivesWebhooks,
    SupportsIdentitySync,
    SupportsRemoteDomainVerify
{
    public const TYPE = 'acelle-smtp2go';

    private ?Client $client = null;

    public function getServiceName(): string  { return 'SMTP2GO'; }
    public function getServiceIcon(): string  { return 'send'; }
    public function getServiceColor(): string { return 'var(--chart-1)'; }

    public function connectionFieldsView(): string
    {
        return 'smtp2go::sending-servers._fields_connection';
    }

    public function validationRules(): array
    {
        return [
            'cols' => [
                'smtp2go_api_key' => 'required|string|max:255',
            ],
            'settings' => [
                'name'               => 'required',
                'default_from_email' => 'email',
            ],
        ];
    }

    /**
     * POST /v3/email/send. Returns SendResult with email_id from data envelope.
     */
    public function send(\Symfony\Component\Mime\Email $message, array $params = []): SendResult
    {
        $payload = $this->buildPayload($message);
        $resp = $this->client()->post('email/send', $payload);

        $emailId = $resp['data']['email_id'] ?? null;
        $failed  = (int) ($resp['data']['failed'] ?? 0);

        if ($failed > 0 || !is_string($emailId) || $emailId === '') {
            // SMTP2GO returns 200 with `failed:1` for per-recipient rejection
            // (invalid address, suppression list, …). Fail loudly so the
            // SendMessage job marks the send as failed instead of silently
            // saving a half-success row.
            $errs = $resp['data']['failures'] ?? [];
            $msg  = $errs ? json_encode($errs) : 'no email_id returned';
            throw new \RuntimeException('SMTP2GO send failed: ' . $msg . ' (resp=' . json_encode($resp) . ')');
        }

        return new SendResult(runtimeMessageId: $emailId, raw: $resp);
    }

    /**
     * Probe. SMTP2GO's `/stats/email_summary` accepts an empty POST and
     * returns 200 with stats blob on valid key, 401 / data.error on bad.
     * Cheap, has no side effects.
     */
    public function test(): TestResult
    {
        try {
            $this->client()->post('stats/email_summary', []);
            return TestResult::success();
        } catch (Error $e) {
            return TestResult::failure($e->getMessage());
        }
    }

    /**
     * Idempotently register the bounce/spam webhook with SMTP2GO. Stores the
     * vendor's webhook id + the Basic-auth user/pass we generated (used to
     * verify inbound POSTs in verifyWebhook).
     *
     * Skip if URL is localhost — SMTP2GO requires public HTTPS (verified
     * in Brevo testing — vendors universally reject non-public URLs).
     */
    public function setupBeforeSend(string $fromEmailAddress): void
    {
        if ($this->config('smtp2go_webhook_id')) {
            return;
        }

        $url = $this->webhookUrl();
        if (!$this->isPublicHttpsUrl($url)) {
            Log::info('SMTP2GO webhook auto-register skipped — needs public HTTPS URL', [
                'server_id'   => $this->server->id,
                'webhook_url' => $url,
                'hint'        => 'Use a tunnel (ngrok/cloudflared) in dev, or rely on production deploy.',
            ]);
            return;
        }

        // Generate stable Basic-auth credentials (8-char user + 32-hex pass).
        $authUser = 'acelle-' . bin2hex(random_bytes(4));
        $authPass = bin2hex(random_bytes(16));

        try {
            $resp = $this->client()->post('webhook/add', [
                'url'       => $url,
                'name'      => 'Acelle bounce/spam feed',
                'events'    => ['bounce', 'spam', 'reject'],
                'auth_user' => $authUser,
                'auth_pass' => $authPass,
            ]);
        } catch (Error $e) {
            Log::warning('SMTP2GO webhook auto-register failed', [
                'server_id' => $this->server->id,
                'error'     => $e->getMessage(),
            ]);
            return;
        }

        // SMTP2GO returns the new webhook inside data envelope; the id field
        // name varies by API version — accept both `data.id` and `data.webhook_id`.
        $id = $resp['data']['id']
            ?? $resp['data']['webhook_id']
            ?? ($resp['data']['webhooks'][0]['id'] ?? null);
        if ($id === null) {
            Log::warning('SMTP2GO webhook register: response missing id', [
                'server_id' => $this->server->id,
                'response'  => $resp,
            ]);
            return;
        }

        $this->server->setConfig('smtp2go_webhook_id',   (int) $id);
        $this->server->setConfig('smtp2go_webhook_user', $authUser);
        $this->server->setConfig('smtp2go_webhook_pass', $authPass);
        $this->server->save();
    }

    public function teardownWebhook(): void
    {
        $id = $this->config('smtp2go_webhook_id');
        if (!$id) {
            return;
        }
        $this->client()->post('webhook/delete', ['id' => (int) $id]);
        $this->server->setConfig('smtp2go_webhook_id',   null);
        $this->server->setConfig('smtp2go_webhook_user', null);
        $this->server->setConfig('smtp2go_webhook_pass', null);
        $this->server->save();
    }

    public function webhookUrl(): string
    {
        return route('webhook.handle', [
            'driverType' => $this->server->type,
            'serverUid'  => $this->server->uid,
        ]);
    }

    /**
     * Verify HTTP Basic auth. SMTP2GO sends `Authorization: Basic base64(user:pass)`
     * matching what we registered. Compare with hash_equals.
     *
     * Skip-with-warning if creds not configured (parity with Brevo's pattern
     * when setupBeforeSend hasn't run).
     */
    public function verifyWebhook(Request $request): void
    {
        $expectedUser = (string) $this->config('smtp2go_webhook_user', '');
        $expectedPass = (string) $this->config('smtp2go_webhook_pass', '');
        if ($expectedUser === '' || $expectedPass === '') {
            Log::warning('SMTP2GO webhook accepted without basic-auth verification', [
                'server_id' => $this->server->id,
                'reason'    => 'webhook user/pass not configured',
            ]);
            return;
        }

        $auth = (string) $request->header('Authorization', '');
        if (!preg_match('/^Basic\s+(.+)$/i', $auth, $m)) {
            throw new \RuntimeException('SMTP2GO webhook missing Basic auth header');
        }
        $decoded = base64_decode(trim($m[1]), true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            throw new \RuntimeException('SMTP2GO webhook Basic auth header malformed');
        }
        [$user, $pass] = explode(':', $decoded, 2);

        if (!hash_equals($expectedUser, $user) || !hash_equals($expectedPass, $pass)) {
            throw new \RuntimeException('SMTP2GO webhook auth mismatch');
        }
    }

    /**
     * Bounce events from SMTP2GO. `bounce` field is "hard" | "soft" — we
     * only act on hard (soft retries are handled by the engine).
     *
     * `reject` event = SMTP2GO refused at submission (suppression list match
     * or rate-limit). Treat as hard so the engine stops retrying.
     */
    private const BOUNCE_EVENTS = ['bounce', 'reject'];

    private const COMPLAINT_EVENTS = ['spam'];

    /**
     * Engagement / informational — explicit allow-list. SMTP2GO emits
     * processed/delivered/open/click/unsubscribe/resubscribe alongside
     * bounce/spam/reject.
     */
    private const IGNORABLE_EVENTS = [
        'processed', 'delivered',
        'open', 'click',
        'unsubscribe', 'resubscribe',
    ];

    public function parseWebhook(Request $request): iterable
    {
        $rawBody = $request->getContent();
        $json = json_decode($rawBody, true);

        if (!is_array($json)) {
            Log::warning('SMTP2GO webhook: body is not JSON', [
                'server_id' => $this->server->id,
                'body'      => $rawBody,
            ]);
            return;
        }

        $event = $json['event'] ?? null;
        $emailId = (string) ($json['email_id'] ?? '');

        if (!is_string($event)) {
            Log::warning('SMTP2GO webhook: missing event field', [
                'server_id' => $this->server->id,
                'keys'      => array_keys($json),
            ]);
            yield new IgnorableWebhookEvent(reason: 'smtp2go:no-event', rawPayload: $json, server: $this->server);
            return;
        }

        // Bounce — split soft vs hard. Soft → IgnorableWebhookEvent (engine
        // retries). Only `hard` bounces and `reject` events produce BounceReceived.
        if ($event === 'bounce') {
            $bounceType = strtolower((string) ($json['bounce'] ?? ''));
            if ($bounceType === 'soft') {
                yield new IgnorableWebhookEvent(
                    reason: 'smtp2go:soft_bounce',
                    rawPayload: $json,
                    server: $this->server,
                );
                return;
            }
        }

        if (in_array($event, self::IGNORABLE_EVENTS, true)) {
            yield new IgnorableWebhookEvent(
                reason: 'smtp2go:' . $event,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if ($emailId === '') {
            Log::info('SMTP2GO webhook: actionable event without email_id', [
                'server_id' => $this->server->id,
                'event'     => $event,
            ]);
            yield new IgnorableWebhookEvent(
                reason: 'smtp2go:no-id:' . $event,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if (in_array($event, self::BOUNCE_EVENTS, true)) {
            yield new BounceReceived(
                runtimeMessageId: $emailId,
                type: BounceType::HARD,
                bounceTypeRaw: BounceLog::HARD,
                shouldBlacklist: true,
                rawForDb: $rawBody,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        if (in_array($event, self::COMPLAINT_EVENTS, true)) {
            yield new ComplaintReceived(
                runtimeMessageId: $emailId,
                type: FeedbackType::SPAM,
                feedbackTypeRaw: 'spam',
                shouldBlacklist: false,
                shouldMarkSpamReported: true,
                rawForDb: $rawBody,
                rawPayload: $json,
                server: $this->server,
            );
            return;
        }

        Log::info('SMTP2GO webhook: unknown event type', [
            'server_id' => $this->server->id,
            'event'     => $event,
        ]);
        yield new IgnorableWebhookEvent(
            reason: 'smtp2go:unknown:' . $event,
            rawPayload: $json,
            server: $this->server,
        );
    }

    // ─── SupportsIdentitySync ──────────────────────────────────────────────

    /**
     * A list that cannot be read makes the whole sync fail: nothing is
     * archived, and the admin sees SMTP2GO's answer (it used to read as "no
     * domains"). Whether domain/view is ever paged is unknown — this driver
     * has not been run against an account yet.
     */
    public function fetchIdentities(): array
    {
        $resp = $this->client()->post('domain/view', []);

        $identities = [];
        foreach (($resp['data']['domains'] ?? $resp['data'] ?? []) as $d) {
            if (!is_array($d) || empty($d['domain'])) {
                continue;
            }
            $identities[] = $this->mapDomainToIdentity($d);
        }
        return $identities;
    }

    public function fetchIdentity(IdentityKind $kind, string $value): ?Identity
    {
        if ($kind !== IdentityKind::DOMAIN) {
            // SMTP2GO does not expose single-sender identity via API — only
            // sender domains. EMAIL kind not supported.
            return null;
        }

        // A failed call propagates: read as "not found" it would archive the domain.
        $resp = $this->client()->post('domain/view', ['domain' => $value]);

        $d = $this->domainFromView($resp, $value);
        return $d === null ? null : $this->mapDomainToIdentity($d);
    }

    // ─── SupportsRemoteDomainVerify ────────────────────────────────────────

    public function registerDomain(string $domain): VerificationResult
    {
        try {
            $resp = $this->client()->post('domain/add', ['domain' => $domain]);
        } catch (Error $e) {
            // Already exists at vendor — fall through to view.
            try {
                $resp = $this->client()->post('domain/view', ['domain' => $domain]);
            } catch (Error $_) {
                throw $e;
            }
        }
        $d = $resp['data']['domain'] ?? ($resp['data'] ?? []);
        return $this->mapDomainToVerificationResult(is_array($d) ? $d : []);
    }

    public function pollDomain(string $domain): VerificationResult
    {
        try {
            $this->client()->post('domain/verify', ['domain' => $domain]);
        } catch (Error $e) {
            // Expected when DNS records aren't published yet — keep going to
            // GET the latest record-status snapshot.
            Log::info('SMTP2GO pollDomain: verify trigger returned non-2xx', [
                'server_id' => $this->server->id,
                'domain'    => $domain,
                'error'     => $e->getMessage(),
            ]);
        }

        // An error propagates: it says nothing about the domain.
        $resp = $this->client()->post('domain/view', ['domain' => $domain]);
        $d = $this->domainFromView($resp, $domain);
        if ($d === null) {
            // Not DomainNotAtVendor: this driver has never seen SMTP2GO's answer
            // for a domain it lacks, so it cannot tell "deleted there" from an
            // answer it does not read — and DomainNotAtVendor archives the domain.
            throw new \UnexpectedValueException("SMTP2GO's domain/view answer holds no domain {$domain}: " . json_encode($resp));
        }
        return $this->mapDomainToVerificationResult($d);
    }

    /**
     * The domain's blob in a domain/view answer: `data.domain` for a single
     * lookup, or its entry in the `data.domains` list SMTP2GO sometimes
     * answers with instead. Null when neither holds it.
     *
     * @param  array<string, mixed>  $resp
     * @return array<string, mixed>|null
     */
    private function domainFromView(array $resp, string $domain): ?array
    {
        $single = $resp['data']['domain'] ?? null;
        if (is_array($single)) {
            return $single;
        }
        foreach (($resp['data']['domains'] ?? []) as $candidate) {
            if (is_array($candidate) && strtolower((string) ($candidate['domain'] ?? '')) === strtolower($domain)) {
                return $candidate;
            }
        }
        return null;
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function isPublicHttpsUrl(string $url): bool
    {
        if (!str_starts_with($url, 'https://')) return false;
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost') return false;
        if (str_starts_with($host, '127.')) return false;
        if (str_starts_with($host, '10.'))  return false;
        if (str_starts_with($host, '192.168.')) return false;
        if (str_ends_with($host, '.local'))    return false;
        if (str_ends_with($host, '.invalid'))  return false;
        return true;
    }

    /**
     * @param  array<string, mixed>  $domain   single domain blob from view/add
     */
    private function mapDomainToIdentity(array $domain): Identity
    {
        $name   = (string) ($domain['domain'] ?? '');
        $status = $this->domainStatusOf($domain);
        return Identity::domain(
            name: $name,
            status: $status,
            vendorMeta: [
                'verified'           => $status === IdentityStatus::VERIFIED,
                'tracking_subdomain' => $domain['tracking_subdomain'] ?? null,
                'returnpath_subdomain' => $domain['returnpath_subdomain'] ?? null,
            ],
        );
    }

    /**
     * A domain's status, the same for identity sync and Verify. SMTP2GO reports
     * verified or not — no rejected or switched-off domain state — so "not
     * verified" is PENDING: the DNS is not right yet.
     *
     * @param  array<string, mixed>  $domain
     */
    private function domainStatusOf(array $domain): IdentityStatus
    {
        return !empty($domain['verified']) || ($domain['status'] ?? null) === 'verified'
            ? IdentityStatus::VERIFIED
            : IdentityStatus::PENDING;
    }

    /**
     * @param  array<string, mixed>  $domain
     */
    private function mapDomainToVerificationResult(array $domain): VerificationResult
    {
        return new VerificationResult(
            domain:   (string) ($domain['domain'] ?? ''),
            records:  $this->extractDnsRecords($domain),
            status:   $this->domainStatusOf($domain),
            polledAt: new DateTimeImmutable(),
            vendorMeta: ['verified' => $domain['verified'] ?? null],
        );
    }

    /**
     * SMTP2GO returns DNS records under `records` array OR top-level fields
     * (spf, dkim, tracking) depending on endpoint version. Handle both.
     *
     * @param  array<string, mixed>  $domain
     * @return list<DnsRecord>
     */
    private function extractDnsRecords(array $domain): array
    {
        $out = [];

        // Shape A — flat list (newer endpoint variant).
        foreach (($domain['records'] ?? []) as $r) {
            if (!is_array($r) || empty($r['hostname']) || empty($r['value'])) continue;
            $out[] = $this->mapSingleRecord(
                (string) ($r['type'] ?? 'TXT'),
                (string) $r['hostname'],
                (string) $r['value'],
                ($r['status'] ?? false) === true || $r['status'] === 'verified',
                (string) ($r['purpose'] ?? ''),
            );
        }
        if ($out !== []) return $out;

        // Shape B — fixed-key (spf / dkim / tracking) — common in domain/view response.
        $keys = [
            'spf'            => RecordPurpose::SPF,
            'dkim'           => RecordPurpose::DKIM,
            'returnpath'     => RecordPurpose::OWNERSHIP,
            'tracking'       => RecordPurpose::TRACKING,
            'dmarc'          => RecordPurpose::DMARC,
        ];
        foreach ($keys as $key => $purpose) {
            $r = $domain[$key] ?? null;
            if (!is_array($r) || empty($r['hostname']) || empty($r['value'])) continue;
            $out[] = new DnsRecord(
                purpose:  $purpose,
                type:     strtoupper((string) ($r['type'] ?? 'TXT')),
                name:     (string) $r['hostname'],
                value:    (string) $r['value'],
                status:   (($r['status'] ?? false) === true || ($r['status'] ?? null) === 'verified')
                    ? CheckStatus::PASSED
                    : CheckStatus::PENDING,
                selector: $purpose === RecordPurpose::DKIM ? $this->extractDkimSelector((string) $r['hostname']) : null,
            );
        }
        return $out;
    }

    private function mapSingleRecord(string $type, string $name, string $value, bool $verified, string $purposeHint): DnsRecord
    {
        $purpose = $this->detectPurpose($purposeHint, $name, $type, $value);
        return new DnsRecord(
            purpose:  $purpose,
            type:     strtoupper($type),
            name:     $name,
            value:    $value,
            status:   $verified ? CheckStatus::PASSED : CheckStatus::PENDING,
            selector: $purpose === RecordPurpose::DKIM ? $this->extractDkimSelector($name) : null,
        );
    }

    private function detectPurpose(string $hint, string $name, string $type, string $value): RecordPurpose
    {
        $h = strtolower($hint);
        if ($h === 'dkim') return RecordPurpose::DKIM;
        if ($h === 'spf')  return RecordPurpose::SPF;
        if ($h === 'dmarc') return RecordPurpose::DMARC;
        if ($h === 'tracking') return RecordPurpose::TRACKING;

        $nLow = strtolower($name);
        $vLow = strtolower($value);
        if (str_contains($nLow, '_domainkey'))         return RecordPurpose::DKIM;
        if (str_starts_with($nLow, '_dmarc'))          return RecordPurpose::DMARC;
        if ($type === 'TXT' && str_contains($vLow, 'v=spf1')) return RecordPurpose::SPF;
        return RecordPurpose::OWNERSHIP;
    }

    private function extractDkimSelector(string $name): ?string
    {
        if (!preg_match('/^([^.]+)\._domainkey/i', $name, $m)) {
            return null;
        }
        return $m[1];
    }

    /**
     * Build SMTP2GO JSON from a Symfony Mime Email.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(\Symfony\Component\Mime\Email $message): array
    {
        $fromAddr  = $message->getFrom()[0] ?? null;
        $fromEmail = $fromAddr?->getAddress() ?? '';
        $fromName  = $fromAddr?->getName() ?: null;
        $sender    = $fromName ? sprintf('%s <%s>', $fromName, $fromEmail) : $fromEmail;

        // SMTP2GO `to` is array of strings, NOT objects with name. Format as
        // "Name <email>" so display name survives.
        $toList = [];
        foreach ($message->getTo() as $addr) {
            $toList[] = $addr->getName()
                ? sprintf('%s <%s>', $addr->getName(), $addr->getAddress())
                : $addr->getAddress();
        }

        $payload = [
            'sender'  => $sender,
            'to'      => $toList,
            'subject' => (string) $message->getSubject(),
        ];

        $html = $message->getHtmlBody();
        $text = $message->getTextBody();
        if ($html !== null) $payload['html_body'] = $html;
        if ($text !== null) $payload['text_body'] = $text;

        // Custom headers — preserve X-Acelle-* / X-Campaign-* etc. Skip standard
        // headers SMTP2GO auto-fills.
        $skip = ['from','to','cc','bcc','reply-to','subject','content-type',
                 'content-transfer-encoding','mime-version','date','message-id','x-mailer'];
        $custom = [];
        foreach ($message->getHeaders()->all() as $h) {
            if (in_array(strtolower($h->getName()), $skip, true)) continue;
            $custom[] = ['header' => $h->getName(), 'value' => $h->getBodyAsString()];
        }
        if ($custom !== []) $payload['custom_headers'] = $custom;

        // Attachments — SMTP2GO accepts `[{filename, fileblob (base64), mimetype}]`.
        $atts = [];
        foreach ($message->getAttachments() as $part) {
            $atts[] = [
                'filename' => $part->getFilename(),
                'fileblob' => base64_encode($part->getBody()),
                'mimetype' => $part->getContentType(),
            ];
        }
        if ($atts !== []) $payload['attachments'] = $atts;

        $cc = $message->getCc();
        if ($cc) {
            $payload['cc'] = array_map(
                fn ($a) => $a->getName() ? sprintf('%s <%s>', $a->getName(), $a->getAddress()) : $a->getAddress(),
                $cc,
            );
        }
        $bcc = $message->getBcc();
        if ($bcc) {
            $payload['bcc'] = array_map(
                fn ($a) => $a->getName() ? sprintf('%s <%s>', $a->getName(), $a->getAddress()) : $a->getAddress(),
                $bcc,
            );
        }

        return $payload;
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $this->client = new Client((string) $this->config('smtp2go_api_key'));
        }
        return $this->client;
    }
}
