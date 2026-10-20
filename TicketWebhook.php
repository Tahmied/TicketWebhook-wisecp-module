<?php
namespace WISECP\Modules\Addons;

use AddonModule;
use Exception;

class TicketWebhook extends AddonModule
{
    public string $version = '1.1';

    // Admin links: the id is appended to the end.
    private const CLIENT_URL = 'https://app.ogahost.com/ishquj/users/detail?id=';
    private const TICKET_URL = 'https://app.ogahost.com/ishquj/tickets/detail?id=';

    // ticket_id -> Slack thread (channel + ts of the parent message)
    private const TABLE = 'ticketwebhook_threads';

    // Keys: 'new:{ticketId}' and 'reply:{replyId}', per request.
    private static array $sent = [];
    private static bool $tableReady = false;

    // ---------------------------------------------------------------- settings

    public function fields(): array
    {
        $s = $this->config['settings'] ?? [];

        return [
            'token' => [
                'name' => $this->lang['token'],
                'type' => 'password',
                'wrap_width' => 100,
                'value' => ($s['token'] ?? '') !== '' ? '********' : '',
            ],
            'channel' => [
                'name' => $this->lang['channel'],
                'type' => 'text',
                'wrap_width' => 100,
                'value' => $s['channel'] ?? '',
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
        $token = trim((string) ($fields['token'] ?? ''));

        if ($token === '********') {
            // Mask means "unchanged": keep the stored (encrypted) value.
            $fields['token'] = $this->config['settings']['token'] ?? '';
        } else {
            if (!preg_match('#^xoxb-#', $token))
                throw new Exception($this->lang['err-token']);

            $fields['token'] = $this->encode_str($token);
        }

        $channel = trim((string) ($fields['channel'] ?? ''));
        if (!preg_match('#^[CG][A-Z0-9]{8,}$#', $channel))
            throw new Exception($this->lang['err-channel']);
        $fields['channel'] = $channel;

        $company = trim((string) ($fields['company'] ?? ''));
        if ($company === '')
            throw new Exception($this->lang['err-company']);
        $fields['company'] = $company;

        $fields['timeout'] = max(1, min(10, (int) ($fields['timeout'] ?? 3)));

        return $fields;
    }

    public function enable(): bool
    {
        if (!$this->ensure_table())
            throw new Exception('Table could not be created: ' . \WDB::getErrorMessage());

        return true;
    }

    // ---------------------------------------------------------------- senders

    // New ticket (customer, email, chat, or staff): becomes the thread parent.
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

        $res = $this->slack_post($text, '', "ticket #{$ticketId}");
        if ($res['ok'])
            $this->thread_save($ticketId, $res['ts'], $res['channel']);
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
        $body = $this->slack_body($message);
        if ($body === '')
            $body = '(no text)';

        $label = $from === 'staff' ? "{$company} Team" : 'Client';
        $line = "{$label}: {$body}";

        // 1) Known thread: post the short line inside it.
        $thread = $this->thread_find($ticketId);
        if ($thread) {
            $res = $this->slack_post($line, $thread['slack_ts'], "ticket #{$ticketId} reply", $thread['channel']);
            if ($res['ok'] || $res['error'] !== 'thread_not_found')
                return;
            // Parent message is gone: fall through and start a fresh thread.
        }

        // 2) No thread yet (ticket predates threading, or parent lost): start one with context.
        $title = $this->slack_escape(trim((string) ($ticket['title'] ?? '')));

        $text = "{$company} ticket reply";
        if ($title !== '')
            $text .= "\nTitle: {$title}";
        $text .= "\nTicket: " . self::TICKET_URL . $ticketId;
        $text .= "\n{$line}";

        $res = $this->slack_post($text, '', "ticket #{$ticketId} reply");
        if ($res['ok'])
            $this->thread_save($ticketId, $res['ts'], $res['channel']);
    }

    // ---------------------------------------------------------------- Slack

    // chat.postMessage. Returns ['ok', 'ts', 'channel', 'error'].
    private function slack_post(string $text, string $threadTs, string $label, string $channel = ''): array
    {
        $fail = ['ok' => false, 'ts' => '', 'channel' => '', 'error' => ''];

        $s = $this->config['settings'] ?? [];
        $token = $this->decode_str((string) ($s['token'] ?? ''));
        $channel = $channel !== '' ? $channel : trim((string) ($s['channel'] ?? ''));

        if ($token === '' || $channel === '') {
            $fail['error'] = 'not_configured';
            error_log("[TicketWebhook] {$label}: Slack bot token / channel not configured");
            return $fail;
        }

        $data = ['channel' => $channel, 'text' => $text];
        if ($threadTs !== '')
            $data['thread_ts'] = $threadTs;

        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            $fail['error'] = 'encode_failed';
            error_log("[TicketWebhook] {$label}: could not encode payload");
            return $fail;
        }

        try {
            $ch = curl_init('https://slack.com/api/chat.postMessage');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json; charset=utf-8',
                    'Authorization: Bearer ' . $token,
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => (int) ($s['timeout'] ?? 3),
            ]);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            $json = is_string($raw) ? json_decode($raw, true) : null;

            if ($err !== '' || !is_array($json) || empty($json['ok'])) {
                // Slack error codes: invalid_auth, channel_not_found, not_in_channel, thread_not_found ...
                $fail['error'] = (string) ($json['error'] ?? ($err !== '' ? $err : "http_{$code}"));
                error_log("[TicketWebhook] {$label} failed: {$fail['error']} (HTTP {$code})");
                return $fail;
            }

            return [
                'ok' => true,
                'ts' => (string) ($json['ts'] ?? ''),
                'channel' => (string) ($json['channel'] ?? $channel),
                'error' => '',
            ];
        } catch (\Throwable $e) {
            $fail['error'] = 'exception';
            error_log("[TicketWebhook] {$label} exception: " . $e->getMessage());
            return $fail;
        }
    }

    // ---------------------------------------------------------------- thread storage

    private function ensure_table(): bool
    {
        if (self::$tableReady)
            return true;

        if (!\WDB::hasTable(self::TABLE)) {
            \WDB::exec('CREATE TABLE `' . self::TABLE . '` (
                `ticket_id` INT UNSIGNED NOT NULL,
                `channel`   VARCHAR(32)  NOT NULL DEFAULT "",
                `slack_ts`  VARCHAR(32)  NOT NULL DEFAULT "",
                `cdate`     DATETIME     NOT NULL,
                PRIMARY KEY (`ticket_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        }

        // exec() swallows errors, so read the result back.
        return self::$tableReady = \WDB::hasTable(self::TABLE);
    }

    private function thread_find(int $ticketId): ?array
    {
        if (!$this->ensure_table())
            return null;

        try {
            $stmt = \WDB::select('channel, slack_ts')->from(self::TABLE)->where('ticket_id', '=', $ticketId)->limit(1);
            $row = $stmt->build() ? $stmt->getAssoc() : [];

            return !empty($row['slack_ts']) ? $row : null;
        } catch (\Throwable $e) {
            error_log("[TicketWebhook] thread lookup failed for ticket #{$ticketId}: " . $e->getMessage());
            return null;
        }
    }

    private function thread_save(int $ticketId, string $ts, string $channel): void
    {
        if ($ts === '' || !$this->ensure_table())
            return;

        try {
            $stmt = \WDB::select('ticket_id')->from(self::TABLE)->where('ticket_id', '=', $ticketId)->limit(1);

            if ($stmt->build()) {
                \WDB::update(self::TABLE, ['channel' => $channel, 'slack_ts' => $ts])
                    ->where('ticket_id', '=', $ticketId)
                    ->save();
            } else {
                \WDB::insert(self::TABLE, [
                    'ticket_id' => $ticketId,
                    'channel' => $channel,
                    'slack_ts' => $ts,
                    'cdate' => date('Y-m-d H:i:s'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log("[TicketWebhook] could not save thread for ticket #{$ticketId}: " . $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- text helpers

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