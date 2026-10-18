<?php

return [
    'broadcasting' => [
        // 'echo' => [ ... ],
    ],
    'default_filesystem_disk' => env('FILESYSTEM_DISK', 'public'),
    'assets_path' => null,
    'cache_path' => base_path('bootstrap/cache/filament'),
    'livewire_loading_delay' => 'default',
    'file_generation' => [
        'flags' => [],
    ],
    'system_route_prefix' => 'filament',
];
