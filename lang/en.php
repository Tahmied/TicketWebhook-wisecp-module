<?php
return [
    'meta' => [
        'name' => 'Ticket Webhook',
        'description' => 'Sends every new support ticket to an external API.',
    ],
    'url' => 'Slack Webhook URL',
    'secret' => 'Signing secret (optional)',
    'timeout' => 'Timeout in seconds (1-10)',
    'err-url' => 'Endpoint URL must start with https://',
    'company' => 'Company Name',
    'err-company' => 'Company name is required.',
];