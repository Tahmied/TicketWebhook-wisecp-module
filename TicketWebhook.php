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

    // Keys: 'new:{ticketId}' and 'reply:{replyId}', per request.
    private static array $sent = [];

    public function fields(): array
    {
        $s = $this->config['settings'] ?? [];

        return [
            'url' => [
                'name' => $this->lang['url'],
                'type' => 'password',
                'wrap_width' => 100,
                'value' => ($s['url'] ?? '') !== '' ? '********' : '',
            ],
            'company' => [
                'name' => $this->lang['company'],
                'type' => 'text',
                'wrap_width' => 100,
                'value' => $s['company'] ?? '',
            ],
            'timeout' => [
                'name' => $this->lang['timeout'],
                'type' => 'text',
                'wrap_width' => 100,
                'value' => $s['timeout'] ?? '3',
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

        $company = trim((string) ($fields['company'] ?? ''));
        if ($company === '')
            throw new Exception($this->lang['err-company']);
        $fields['company'] = $company;

        $fields['timeout'] = max(1, min(10, (int) ($fields['timeout'] ?? 3)));

        return $fields;
    }

    // New ticket (customer, email, chat, or staff).
    public function send_ticket(string $source, $ticket, string $message, int $clientId, int $replyId = 0): void
    {
        if (!is_array($ticket))
            return;

        $ticketId = (int) ($ticket['id'] ?? 0);
        if (!$ticketId || isset(self::$sent['new:' . $ticketId]))
            return;
        self::$sent['new:' . $ticketId] = true;
        if ($replyId > 0)
            self::$sent['reply:' . $replyId] = true;

        $company = $this->company();
        $title = $this->slack_escape(trim((string) ($ticket['title'] ?? '')));
        $body = $this->slack_body($message);

        $text = "{$company} new ticket" . ($source === 'staff' ? ' (opened by staff)' : '');
        if ($title !== '')
            $text .= "\nTitle: {$title}";
        $text .= "\nTicket: " . self::TICKET_URL . $ticketId;
        if ($clientId > 0)
            $text .= "\nClient: " . self::CLIENT_URL . $clientId;
        if ($body !== '')
            $text .= "\nMessage: {$body}";

        $this->post_slack($text, "ticket #{$ticketId}");
    }

    // Reply on an existing ticket. $from is 'client' or 'staff'.
    public function send_reply(string $from, $ticket, string $message, int $replyId = 0): void
    {
        if (!is_array($ticket))
            return;

        $ticketId = (int) ($ticket['id'] ?? 0);
        if (!$ticketId)
            return;

        // Already announced as a new ticket in this request: don't repeat it as a reply.
        if (isset(self::$sent['new:' . $ticketId]))
            return;
        if ($replyId > 0) {
            if (isset(self::$sent['reply:' . $replyId]))
                return;
            self::$sent['reply:' . $replyId] = true;
        }

        $company = $this->company();
        $title = $this->slack_escape(trim((string) ($ticket['title'] ?? '')));
        $body = $this->slack_body($message);
        if ($body === '')
            $body = '(no text)';

        $label = $from === 'staff' ? "{$company} Team" : 'Client';

        $text = "{$company} ticket reply";
        if ($title !== '')
            $text .= "\nTitle: {$title}";
        $text .= "\nTicket: " . self::TICKET_URL . $ticketId;
        $text .= "\n{$label}: {$body}";

        $this->post_slack($text, "ticket #{$ticketId} reply");
    }

    private function post_slack(string $text, string $label): void
    {
        $s = $this->config['settings'] ?? [];
        $url = $this->decode_str((string) ($s['url'] ?? ''));
        if ($url === '')
            return;

        $payload = json_encode(
            ['text' => $text],
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false) {
            error_log("[TicketWebhook] {$label}: could not encode payload");
            return;
        }

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => (int) ($s['timeout'] ?? 3),
            ]);
            $resp = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            // Slack answers 200 with the body "ok" on success.
            if ($err !== '' || $code !== 200)
                error_log("[TicketWebhook] {$label} failed: HTTP {$code} {$err} " . (is_string($resp) ? $resp : ''));
        } catch (\Throwable $e) {
            error_log("[TicketWebhook] {$label} exception: " . $e->getMessage());
        }
    }

    private function company(): string
    {
        $name = trim((string) ($this->config['settings']['company'] ?? ''));

        return $this->slack_escape($name !== '' ? $name : 'Support');
    }

    // HTML -> short plain text, escaped for Slack.
    private function slack_body(string $html): string
    {
        $text = (string) preg_replace('#<\s*(br|/p|/div|/li)\b[^>]*>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);                  // non-breaking spaces
        $text = trim((string) preg_replace("/\n{3,}/", "\n\n", $text));

        $out = $this->slack_escape(mb_substr($text, 0, 500));

        return mb_strlen($text) > 500 ? $out . '…' : $out;
    }

    // Slack only requires these three characters to be escaped.
    private function slack_escape(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }
}