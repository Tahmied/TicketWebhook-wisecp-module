<?php
namespace WISECP\Modules\Addons;

use AddonModule;
use Exception;

class TicketWebhook extends AddonModule
{
    public string $version = '1.0';

    // Admin links: the id is appended to the end.
    private const CLIENT_URL = 'https://app.ogahost.com/ishquj/users/detail?id=';
    private const TICKET_URL = 'https://app.ogahost.com/ishquj/tickets/detail?id=';

    private static array $sent = [];

    public function fields(): array
    {
        $s = $this->config['settings'] ?? [];

        return [
            'url' => [
                'name'       => $this->lang['url'],
                'type'       => 'password',
                'wrap_width' => 100,
                'value'      => ($s['url'] ?? '') !== '' ? '********' : '',
            ],
            'timeout' => [
                'name'       => $this->lang['timeout'],
                'type'       => 'text',
                'wrap_width' => 100,
                'value'      => $s['timeout'] ?? '3',
            ],
        ];
    }

    public function save_fields($fields = []): array|bool
    {
        $url = trim((string) ($fields['url'] ?? ''));

        if ($url === '********') {
            // Mask means "unchanged": keep the stored (encrypted) value.
            $fields['url'] = $this->config['settings']['url'] ?? '';
        } else {
            if (!preg_match('#^https://#i', $url))
                throw new Exception($this->lang['err-url']);

            $fields['url'] = $this->encode_str($url);
        }

        $fields['timeout'] = max(1, min(10, (int) ($fields['timeout'] ?? 3)));

        return $fields;
    }

    public function send_ticket(string $source, $ticket, string $message, int $clientId): void
    {
        if (!is_array($ticket)) return;

        $id = (int) ($ticket['id'] ?? 0);
        if (!$id || isset(self::$sent[$id])) return;   // dedupe if both hooks fire
        self::$sent[$id] = true;

        $s   = $this->config['settings'] ?? [];
        $url = $this->decode_str((string) ($s['url'] ?? ''));
        if ($url === '') return;

        // Build the Slack text.
        $title = $this->slack_escape(trim((string) ($ticket['title'] ?? '')));

        $plain = trim(html_entity_decode(strip_tags($message), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $body  = $this->slack_escape(mb_substr($plain, 0, 500));
        if (mb_strlen($plain) > 500) $body .= '…';

        $text = "New support ticket #{$id}" . ($source === 'staff' ? ' (opened by staff)' : '');
        if ($title !== '')  $text .= "\nTitle: {$title}";
        $text .= "\nTicket: " . self::TICKET_URL . $id;
        if ($clientId > 0)  $text .= "\nClient: " . self::CLIENT_URL . $clientId;
        if ($body !== '')   $text .= "\nMessage: {$body}";

        $payload = json_encode(
            ['text' => $text],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false) {
            error_log("[TicketWebhook] ticket #{$id}: could not encode payload");
            return;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT        => (int) ($s['timeout'] ?? 3),
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err  = curl_error($ch);
            curl_close($ch);

            // Slack answers 200 with the body "ok" on success.
            if ($err !== '' || $code !== 200)
                error_log("[TicketWebhook] ticket #{$id} failed: HTTP {$code} {$err} " . (is_string($resp) ? $resp : ''));
        } catch (\Throwable $e) {
            error_log("[TicketWebhook] ticket #{$id} exception: " . $e->getMessage());
        }
    }

    // Slack only requires these three characters to be escaped.
    private function slack_escape(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }
}