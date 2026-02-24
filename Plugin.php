<?php

namespace Dilexus\Deploy;

use Backend;
use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public function pluginDetails(): array
    {
        return [
            'name' => 'dilexus.deploy::lang.plugin.name',
            'description' => 'dilexus.deploy::lang.plugin.description',
            'author' => 'Dilexus',
            'icon' => 'icon-rocket',
        ];
    }

    public function register()
    {
        $this->registerConsoleCommand('deploy.list', \Dilexus\Deploy\Console\ListServers::class);
        $this->registerConsoleCommand('deploy.run', \Dilexus\Deploy\Console\DeployServer::class);
    }

    public function registerNavigation(): array
    {
        return [
            'deploy' => [
                'label' => 'dilexus.deploy::lang.nav.deploy',
                'url' => Backend::url('dilexus/deploy/servers'),
                'icon' => 'icon-rocket',
                'permissions' => ['dilexus.deploy.*'],
                'order' => 500,

                'sideMenu' => [
                    'servers' => [
                        'label' => 'dilexus.deploy::lang.nav.servers',
                        'icon' => 'icon-server',
                        'url' => Backend::url('dilexus/deploy/servers'),
                        'permissions' => ['dilexus.deploy.servers'],
                    ],
                    'deploylogs' => [
                        'label' => 'dilexus.deploy::lang.nav.deploy_logs',
                        'icon' => 'icon-list-alt',
                        'url' => Backend::url('dilexus/deploy/deploylogs'),
                        'permissions' => ['dilexus.deploy.logs'],
                    ],
                ],
            ],
        ];
    }

    public function registerPermissions(): array
    {
        return [
            'dilexus.deploy.servers' => [
                'label' => 'dilexus.deploy::lang.permissions.manage_servers',
                'tab' => 'dilexus.deploy::lang.plugin.name',
            ],
            'dilexus.deploy.logs' => [
                'label' => 'dilexus.deploy::lang.permissions.view_logs',
                'tab' => 'dilexus.deploy::lang.plugin.name',
            ],
        ];
    }
}
