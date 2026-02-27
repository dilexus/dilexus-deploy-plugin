<?php

namespace Dilexus\Deploy;

use System\Classes\PluginBase;

class Plugin extends PluginBase
{
    public $require = ['RainLab.Deploy'];

    public function pluginDetails(): array
    {
        return [
            'name' => 'Dilexus Deploy Commander',
            'description' => 'CLI commands to deploy plugins to remote servers via RainLab Deploy.',
            'author' => 'Dilexus',
            'icon' => 'icon-rocket',
        ];
    }

    public function register(): void
    {
        $this->registerConsoleCommand('deploy.list', \Dilexus\Deploy\Console\ListServers::class);
        $this->registerConsoleCommand('deploy.run', \Dilexus\Deploy\Console\DeployServer::class);
    }
}
