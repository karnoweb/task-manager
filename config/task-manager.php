<?php

return [
    'page_size' => 30,
    'max_page_size' => 100,
    // Private application storage; never point this at a public uploads directory.
    'export_directory' => null,
    'export_max_bytes' => 104857600,
    // Empty means public HTTPS hosts passing DNS/IP validation; nonempty narrows this further.
    'webhook_allowed_hosts' => [],
    // Bind NotificationPreferences for per-actor/per-scope preferences in the host.
    'notification_channels' => ['in_app'],
    'quiet_hours' => ['timezone' => 'UTC', 'start' => null, 'end' => null],
];
