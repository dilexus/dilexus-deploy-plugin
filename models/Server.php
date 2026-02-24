<?php namespace Dilexus\Deploy\Models;

use Model;

/**
 * Server Model
 *
 * Represents a remote deployment target. Supports two deploy methods:
 *   - webhook: HTTP POST to a URL (e.g. GitHub/Bitbucket deploy hook)
 *   - ssh:     SSH into the server and run git pull + hooks
 */
class Server extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public static $deployMethodOptions = [
        'webhook' => 'Webhook (HTTP POST)',
        'ssh' => 'SSH',
    ];

    public $hasMany = [
        'logs' => [DeployLog::class, 'key' => 'server_id', 'order' => 'created_at desc'],
    ];

    public $rules = [
        'name' => 'required',
        'url' => 'required|url',
    ];

    public static $sshAuthOptions = [
        'password' => 'Password',
        'key' => 'SSH Key File',
    ];

    public $table = 'dilexus_deploy_servers';

    protected $casts = [
        'is_active' => 'boolean',
        'ssh_port' => 'integer',
    ];

    protected $fillable = [
        'name',
        'url',
        'deploy_method',
        'webhook_url',
        'webhook_secret',
        'ssh_host',
        'ssh_port',
        'ssh_user',
        'ssh_auth_method',
        'ssh_password',
        'ssh_key_path',
        'deploy_path',
        'branch',
        'before_hooks',
        'after_hooks',
        'is_active',
    ];

    public function getDeployMethodOptions(): array
    {
        return static::$deployMethodOptions;
    }

    /**
     * Get the last deployment log for this server.
     */
    public function getLastLogAttribute(): ?DeployLog
    {
        return $this->logs()->latest()->first();
    }

    public function getSshAuthMethodOptions(): array
    {
        return static::$sshAuthOptions;
    }

    /**
     * Get a human-readable status badge from the last log.
     */
    public function getStatusAttribute(): string
    {
        $log = $this->last_log;
        if (!$log) {
            return 'never';
        }
        return $log->status;
    }
}
