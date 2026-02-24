<?php

return [
    'plugin' => [
        'name' => 'Deploy Commander',
        'description' => 'Manage and deploy servers from the backend or command line.',
    ],
    'servers' => [
        'menu_label' => 'Servers',
        'create_title' => 'Add Server',
        'update_title' => 'Edit Server',
        'list_title' => 'Manage Servers',
    ],
    'logs' => [
        'menu_label' => 'Deploy Logs',
        'list_title' => 'Deployment History',
    ],
    'nav' => [
        'deploy' => 'Deploy',
        'servers' => 'Servers',
        'deploy_logs' => 'Deploy Logs',
    ],
    'permissions' => [
        'manage_servers' => 'Manage Servers',
        'view_logs' => 'View Deploy Logs',
    ],
];
