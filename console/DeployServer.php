<?php namespace Dilexus\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;

/**
 * deploy:run
 *
 * Deploys a server identified by its URL or name.
 *
 * Usage:
 *   php artisan deploy:run https://myapp.com
 *   php artisan deploy:run --name="Production"
 *   php artisan deploy:run --all                   # deploy ALL active servers
 *   php artisan deploy:run https://myapp.com --dry-run
 */
class DeployServer extends Command
{
    protected $description = 'Deploy a server by its URL (or name)';

    protected $signature = 'deploy:run
                            {url?         : The server URL to deploy (as registered in the backend)}
                            {--name=      : Match server by name instead of URL}
                            {--all        : Deploy all active servers}
                            {--dry-run    : Print what would happen without actually deploying}
                            {--force      : Skip confirmation prompt}';

    public function handle(): int
    {
        $servers = $this->resolveServers();

        if ($servers->isEmpty()) {
            $this->error('No matching server found.');
            $this->line('Use <comment>php artisan deploy:list</comment> to see available servers.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] The following servers would be deployed:');
            foreach ($servers as $server) {
                $this->line("  » [{$server->id}] {$server->server_name} ({$server->endpoint_url})");
            }
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->option('all')) {
            foreach ($servers as $server) {
                if (!$this->confirm("Deploy <comment>{$server->server_name}</comment> ({$server->endpoint_url})?", true)) {
                    $this->line('Skipped.');
                    return self::SUCCESS;
                }
            }
        }

        $exitCode = self::SUCCESS;

        foreach ($servers as $server) {
            $this->newLine();
            $this->components->info("Deploying [{$server->server_name}] → {$server->endpoint_url}");

            try {
                $this->line('  → Running: october:migrate');
                $server->transmitArtisan('october:migrate');

                $this->line('  → Running: clear_cache');
                $server->transmitScript('clear_cache');

                $server->touchLastDeploy();
                $server->touchLastVersion();

                $this->components->success("Deployed successfully [{$server->server_name}].");
            } catch (\Throwable $e) {
                $this->components->error("Deployment FAILED for [{$server->server_name}]: " . $e->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function resolveServers(): \Illuminate\Database\Eloquent\Collection
    {
        $query = Server::whereIn('status_code', [
            Server::STATUS_ACTIVE,
            Server::STATUS_READY,
        ]);

        if ($this->option('all')) {
            return Server::all();
        }

        if ($name = $this->option('name')) {
            return $query->where('server_name', 'like', "%{$name}%")->get();
        }

        if ($url = $this->argument('url')) {
            $normalized = rtrim($url, '/');
            return $query->get()->filter(
                fn(Server $s) => rtrim($s->endpoint_url, '/') === $normalized
            )->values();
        }

        // Interactive selection when no argument given
        $servers = $query->get();

        if ($servers->isEmpty()) {
            return $servers;
        }

        $choice = $this->choice(
            'Which server do you want to deploy?',
            $servers->map(fn(Server $s) => "[{$s->id}] {$s->server_name} ({$s->endpoint_url})")->toArray()
        );

        preg_match('/^\[(\d+)\]/', $choice, $matches);
        $selectedId = $matches[1] ?? null;

        return $selectedId
            ? $servers->where('id', (int) $selectedId)->values()
            : collect();
    }
}
