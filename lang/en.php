<?php
return [
    'meta' => [
        'name' => 'Ticket Webhook',
        'description' => 'Posts new support tickets and replies to Slack, one thread per ticket.',
    ],
    'token' => 'Slack Bot Token (xoxb-...)',
    'channel' => 'Slack Channel ID',
    'company' => 'Company Name',
    'timeout' => 'Timeout in seconds (1-10)',
    'err-token' => 'Bot token must start with xoxb-',
    'err-channel' => 'Channel ID looks like C0123456789 (not the channel name).',
    'err-company' => 'Company name is required.',
];