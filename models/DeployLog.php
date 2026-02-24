<?php namespace Dilexus\Deploy\Models;

use Model;

/**
 * DeployLog Model
 *
 * Records each deployment attempt with full output and status.
 */
class DeployLog extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $belongsTo = [
        'server' => [Server::class, 'key' => 'server_id'],
    ];

    public $rules = [];

    public static $statusOptions = [
        'running' => 'Running',
        'success' => 'Success',
        'failed' => 'Failed',
    ];

    public $table = 'dilexus_deploy_logs';

    public $timestamps = true;

    protected $casts = [
        'deployed_at' => 'datetime',
    ];

    protected $fillable = [
        'server_id',
        'status',
        'output',
        'triggered_by',
        'deployed_at',
    ];

    public function getStatusOptions(): array
    {
        return static::$statusOptions;
    }
}
