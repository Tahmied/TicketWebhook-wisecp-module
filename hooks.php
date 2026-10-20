<?php

Modules::Load('Addons', 'TicketWebhook', true);
$tw_config = Modules::Config('Addons', 'TicketWebhook') ?: [];

if ($tw_config['status'] ?? false) {

    Hook::add('action:ticket.opened_by_client', 50,
        function ($ticketAfter, $message, $clientId, $replyId) {
            $m = Modules::getInstance('Addons', 'TicketWebhook');
            if ($m) $m->send_ticket('client', $ticketAfter, (string) $message, (int) $clientId);
        });

    // Tickets opened by staff
    Hook::add('action:ticket.created', 50, function ($ticket) {
        $m = Modules::getInstance('Addons', 'TicketWebhook');
        if ($m) $m->send_ticket(
            'staff',
            $ticket,
            (string) ($ticket['message'] ?? ''),
            (int) ($ticket['user_id'] ?? 0)
        );
    });
}