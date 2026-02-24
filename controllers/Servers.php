<?php namespace Dilexus\Deploy\Controllers;

use BackendMenu;
use Backend\Classes\Controller;
use Dilexus\Deploy\Models\Server;
use Dilexus\Deploy\Classes\Deployer;

class Servers extends Controller
{
    public $formConfig = 'config_form.yaml';

    public $implement = [
        \Backend\Behaviors\ListController::class,
        \Backend\Behaviors\FormController::class,
    ];

    public $listConfig = 'config_list.yaml';

    public $requiredPermissions = ['dilexus.deploy.servers'];

    public function __construct()
    {
        parent::__construct();
        BackendMenu::setContext('Dilexus.Deploy', 'deploy', 'servers');
    }

    /**
     * "Deploy Now" button action from the list or form toolbar.
     */
    public function onDeploy(): array
    {
        $id = post('id', $this->params[0] ?? null);
        $server = Server::findOrFail($id);

        $deployer = new Deployer();
        $log = $deployer->deploy($server, 'backend');

        $statusClass = $log->status === 'success' ? 'success' : 'error';
        $statusLabel = ucfirst($log->status);

        return [
            '#deploy-result' => $this->makePartial('deploy_result', [
                'log' => $log,
                'statusClass' => $statusClass,
                'statusLabel' => $statusLabel,
            ]),
        ];
    }
}
