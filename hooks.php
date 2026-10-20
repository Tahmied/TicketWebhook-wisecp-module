<?php
Modules::Load('Addons', 'TicketWebhook', true);
$tw_config = Modules::Config('Addons', 'TicketWebhook') ?: [];

if ($tw_config['status'] ?? false) {

    // New ticket: customer panel, email piping, WChat
    Hook::add(
        'action:ticket.opened_by_client',
        50,
        function ($ticketAfter, $message, $clientId, $replyId) {
            $m = Modules::getInstance('Addons', 'TicketWebhook');
            if ($m)
                $m->send_ticket('client', $ticketAfter, (string) $message, (int) $clientId, (int) $replyId);
        }
    );

    // New ticket: opened by staff
    Hook::add('action:ticket.created', 50, function ($ticket) {
        $m = Modules::getInstance('Addons', 'TicketWebhook');
        if ($m)
            $m->send_ticket(
                'staff',
                $ticket,
                (string) ($ticket['message'] ?? ''),
                (int) ($ticket['user_id'] ?? 0)
            );
    });

    // Reply from the customer
    Hook::add(
        'action:ticket.reply_added_by_client',
        50,
        function ($ticketAfter, $message, $clientId, $replyId) {
            $m = Modules::getInstance('Addons', 'TicketWebhook');
            if ($m)
                $m->send_reply('client', $ticketAfter, (string) $message, (int) $replyId);
        }
    );

    // Reply from staff
    Hook::add('action:ticket.reply_added', 50, function ($ticket, $reply_id, $reply) {
        $m = Modules::getInstance('Addons', 'TicketWebhook');
        if ($m)
            $m->send_reply(
                'staff',
                $ticket,
                (string) (is_array($reply) ? ($reply['message'] ?? '') : ''),
                (int) $reply_id
            );
    });
}