<?php namespace Dilexus\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;

/**
 * deploy:list
 *
 * Lists all servers registered in the RainLab Deploy plugin.
 *
 * Usage:
 *   php artisan deploy:list
 *   php artisan deploy:list --all       # include non-active servers
 *   php artisan deploy:list --json      # output as JSON
 */
class ListServers extends Command
{
    protected $description = 'List all servers registered in RainLab Deploy';

    protected $signature = 'deploy:list
                            {--all  : Include unreachable/legacy servers}
                            {--json : Output as JSON}';

    public function handle(): int
    {
        $query = Server::query();

        if (!$this->option('all')) {
            $query->whereIn('status_code', [
                Server::STATUS_ACTIVE,
                Server::STATUS_READY,
            ]);
        }

        $servers = $query->get();

        if ($servers->isEmpty()) {
            $this->warn('No servers found. Add a server via RainLab Deploy in the backend.');
            return self::SUCCESS;
        }

        if ($this->option('json')) {
            $this->line($servers->map(function (Server $s) {
                return [
                    'id' => $s->id,
                    'name' => $s->server_name,
                    'url' => $s->endpoint_url,
                    'status' => $s->status_code,
                    'last_deploy' => $s->last_deploy_at?->toIso8601String(),
                    'last_version' => $s->last_version,
                ];
            })->toJson(JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        $rows = $servers->map(function (Server $s) {
            $statusLabel = match ($s->status_code) {
                Server::STATUS_ACTIVE => '<fg=green>active</>',
                Server::STATUS_READY => '<fg=cyan>ready</>',
                Server::STATUS_LEGACY => '<fg=yellow>legacy</>',
                Server::STATUS_UNREACHABLE => '<fg=red>unreachable</>',
                default => $s->status_code,
            };

            return [
                $s->id,
                $s->server_name,
                $s->endpoint_url,
                $statusLabel,
                $s->last_version ?: '<fg=gray>—</>',
                $s->last_deploy_at ? $s->last_deploy_at->diffForHumans() : '<fg=gray>never</>',
            ];
        })->toArray();

        $this->table(
            ['ID', 'Name', 'URL', 'Status', 'Version', 'Last Deploy'],
            $rows
        );

        $this->newLine();
        $this->info("Total: {$servers->count()} server(s).");
        $this->newLine();
        $this->comment('To deploy:  php artisan deploy:run <url>');
        $this->comment('To deploy:  php artisan deploy:run --name="Server Name"');

        return self::SUCCESS;
    }
}
