<?php namespace Dilexus\Deploy\Controllers;

use BackendMenu;
use Backend\Classes\Controller;

class DeployLogs extends Controller
{
    public $implement = [
        \Backend\Behaviors\ListController::class,
    ];

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['dilexus.deploy.logs'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Dilexus.Deploy', 'deploy', 'deploylogs');
    }
}
