<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'app_name' => env('APP_NAME', 'Enterprise MCP Gateway Hyperf Demo'),
    'scan_cacheable' => false,
];
