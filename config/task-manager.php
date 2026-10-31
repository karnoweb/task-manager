<?php

return [
    // Fresh installations only. Changing an installed prefix requires an explicit migration.
    'table_prefix' => 'tm_',
    'user' => ['model' => null, 'resolver' => null, 'identifier' => null],
    'branch' => ['enabled' => false, 'model' => null, 'resolver' => null, 'identifier' => null, 'default_id' => null],
    // Branch-enabled contexts use branch:<identifier>. Persisted scopes are never remapped.
    'scope' => ['resolver' => null, 'default' => 'default'],
    'context' => [
        'capabilities' => ['task.create', 'task.view', 'task.edit', 'task.share', 'list.create', 'list.view', 'activity.view'],
        'capabilities_resolver' => null,
    ],
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
