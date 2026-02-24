<?php namespace Dilexus\Deploy\Classes;

use Dilexus\Deploy\Models\Server;
use Dilexus\Deploy\Models\DeployLog;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Deployer Service
 *
 * Handles the actual deployment logic for both webhook and SSH methods.
 */
class Deployer
{
    /**
     * Deploy a server and return the resulting DeployLog.
     */
    public function deploy(Server $server, string $triggeredBy = 'cli'): DeployLog
    {
        $log = DeployLog::create([
            'server_id' => $server->id,
            'status' => 'running',
            'output' => '',
            'triggered_by' => $triggeredBy,
            'deployed_at' => now(),
        ]);

        try {
            $output = match ($server->deploy_method) {
                'webhook' => $this->deployViaWebhook($server),
                'ssh' => $this->deployViaSsh($server),
                default => throw new \RuntimeException("Unknown deploy method: {$server->deploy_method}"),
            };

            $log->status = 'success';
            $log->output = $output;
        } catch (\Throwable $e) {
            $log->status = 'failed';
            $log->output = ($log->output ?? '') . "\n\nERROR: " . $e->getMessage();
        }

        $log->save();

        return $log;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function deployViaSsh(Server $server): string
    {
        if (empty($server->ssh_host) || empty($server->ssh_user) || empty($server->deploy_path)) {
            throw new \RuntimeException('SSH host, user and deploy path must be configured.');
        }

        $output = '';
        $branch = $server->branch ?: 'main';
        $path = rtrim($server->deploy_path, '/');

        // Build the full remote command sequence
        $remoteCommands = [];

        // Before hooks
        if (!empty(trim($server->before_hooks ?? ''))) {
            foreach ($this->parseHooks($server->before_hooks) as $cmd) {
                $remoteCommands[] = "cd {$path} && {$cmd}";
            }
        }

        // Git pull
        $remoteCommands[] = "cd {$path} && git fetch --all && git checkout {$branch} && git pull origin {$branch}";

        // After hooks
        if (!empty(trim($server->after_hooks ?? ''))) {
            foreach ($this->parseHooks($server->after_hooks) as $cmd) {
                $remoteCommands[] = "cd {$path} && {$cmd}";
            }
        }

        $remoteScript = implode(' && ', $remoteCommands);

        // Build SSH command
        $sshArgs = ['ssh', '-o', 'StrictHostKeyChecking=no', '-p', (string) ($server->ssh_port ?: 22)];

        if ($server->ssh_auth_method === 'key' && !empty($server->ssh_key_path)) {
            $sshArgs[] = '-i';
            $sshArgs[] = $server->ssh_key_path;
        }

        $sshArgs[] = "{$server->ssh_user}@{$server->ssh_host}";
        $sshArgs[] = $remoteScript;

        $env = [];

        if ($server->ssh_auth_method === 'password' && !empty($server->ssh_password)) {
            // Use sshpass for password auth
            $sshArgs = array_merge(['sshpass', '-e'], $sshArgs);
            $env['SSHPASS'] = $server->ssh_password;
        }

        $process = new Process($sshArgs, null, $env ?: null, null, 120);
        $process->run(function ($type, $buffer) use (&$output) {
            $output .= $buffer;
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                "SSH deployment failed (exit {$process->getExitCode()}):\n{$output}"
            );
        }

        return $output;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function deployViaWebhook(Server $server): string
    {
        if (empty($server->webhook_url)) {
            throw new \RuntimeException('Webhook URL is not configured for this server.');
        }

        $headers = ['Accept' => 'application/json'];

        if (!empty($server->webhook_secret)) {
            $headers['X-Deploy-Secret'] = $server->webhook_secret;
        }

        $response = Http::withHeaders($headers)
            ->timeout(30)
            ->post($server->webhook_url, [
                'server' => $server->url,
                'branch' => $server->branch ?? 'main',
            ]);

        $body = $response->body();

        if ($response->failed()) {
            throw new \RuntimeException(
                "Webhook returned HTTP {$response->status()}: {$body}"
            );
        }

        return "Webhook POST to {$server->webhook_url}\nHTTP {$response->status()}\n\n{$body}";
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function parseHooks(string $hooks): array
    {
        return array_filter(
            array_map('trim', explode("\n", $hooks)),
            fn($line) => $line !== '' && !str_starts_with($line, '#')
        );
    }
}
