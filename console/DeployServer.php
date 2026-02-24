<?php namespace Dilexus\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;
use RainLab\Deploy\Classes\ArchiveBuilder;

/**
 * deploy:run
 *
 * Deploys plugins to a server using the RainLab Deploy workflow:
 *   1. Build a plugin zip archive locally (ArchiveBuilder)
 *   2. Upload it via transmitFile
 *   3. Extract it on the server via transmitScript('extract_archive')
 *   4. Clear cache via transmitScript('clear_cache')
 *   5. Run migrations via transmitArtisan('october:migrate')
 *
 * Usage:
 *   php artisan deploy:run                                   (interactive)
 *   php artisan deploy:run https://myapp.com
 *   php artisan deploy:run --name="Production"
 *   php artisan deploy:run --all
 *   php artisan deploy:run --plugins=Dilexus.Smartdilu,Dilexus.Deploy
 *   php artisan deploy:run --no-files   (migrate + cache only, no file upload)
 *   php artisan deploy:run --dry-run
 */
class DeployServer extends Command
{
    protected $description = 'Deploy plugins to a server using the RainLab Deploy workflow';

    protected $signature = 'deploy:run
                            {url?           : The server URL to deploy}
                            {--name=        : Match server by name instead of URL}
                            {--all          : Deploy all active servers}
                            {--dry-run      : Print what would happen without deploying}
                            {--force        : Skip confirmation prompt}
                            {--plugins=     : Comma-separated plugin codes (default: all dilexus plugins)}
                            {--no-files     : Skip file upload — only migrate and clear cache}';

    public function handle(): int
    {
        $servers     = $this->resolveServers();
        $pluginCodes = $this->resolvePluginCodes();
        $skipFiles   = $this->option('no-files');

        if ($servers->isEmpty()) {
            $this->error('No matching server found.');
            $this->line('Use <comment>php artisan deploy:list</comment> to see available servers.');
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] Would deploy to:');
            foreach ($servers as $s) {
                $this->line("  » [{$s->id}] {$s->server_name} ({$s->endpoint_url})");
            }
            if (!$skipFiles) {
                $this->line('  Plugins: ' . implode(', ', $pluginCodes));
            }
            return self::SUCCESS;
        }

        if (!$this->option('force') && !$this->option('all')) {
            $choices = $servers->map(fn(Server $s) => "[{$s->id}] {$s->server_name} ({$s->endpoint_url})")->toArray();
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
                if (!$skipFiles) {
                    $this->runDeployFiles($server, $pluginCodes);
                }

                // Step: clear cache
                $this->line('  → clear_cache');
                $res = $server->transmitScript('clear_cache');
                if (($res['status'] ?? null) !== 'ok') {
                    throw new \RuntimeException('clear_cache failed: ' . ($res['error'] ?? 'unknown'));
                }

                // Step: migrate database
                $this->line('  → october:migrate');
                $res     = $server->transmitArtisan('october:migrate');
                $errCode = $res['errCode'] ?? null;
                $output  = isset($res['output']) ? base64_decode($res['output']) : '';
                if ((int) $errCode !== 0) {
                    throw new \RuntimeException("october:migrate failed:\n{$output}");
                }
                if (trim($output)) {
                    foreach (explode("\n", trim($output)) as $line) {
                        $this->line("     {$line}");
                    }
                }

                $server->touchLastDeploy();
                $server->touchLastVersion();

                $this->components->success("Deployed successfully [{$server->server_name}].");
            } catch (\Throwable $e) {
                $this->components->error("FAILED [{$server->server_name}]: " . $e->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * runDeployFiles mirrors the RainLab Deploy "onDeployToServer" workflow:
     *   1) Build local archive  (ArchiveBuilder::buildPluginsBundle)
     *   2) Upload               (Server::transmitFile)
     *   3) Extract on server    (Server::transmitScript 'extract_archive')
     */
    protected function runDeployFiles(Server $server, array $pluginCodes): void
    {
        $this->line('  → Building plugin archive (' . implode(', ', $pluginCodes) . ')');

        $archivePath = temp_path('deploy-' . md5(uniqid()) . '.arc');

        try {
            // Step 1 — build zip locally (same as 'archiveBuilder' action in Deployer widget)
            ArchiveBuilder::instance()->buildPluginsBundle($archivePath, $pluginCodes);

            // Step 2 — upload to server (same as 'transmitFile' action in Deployer widget)
            $this->line('  → Uploading archive');
            $uploadRes = $server->transmitFile($archivePath);

            // The beacon returns the server-side file path base64-encoded
            if (empty($uploadRes['path'])) {
                throw new \RuntimeException('Upload failed — beacon returned no path.');
            }
            $serverPath = base64_decode($uploadRes['path']);

            // Step 3 — extract on server (same as 'extractFiles' action in Deployer widget)
            // The file map format is: [ localPath => serverPath ] — key is used for result tracking
            $this->line('  → Extracting archive on server');
            $extractRes = $server->transmitScript('extract_archive', [
                'files' => [$archivePath => $serverPath],
            ]);

            if (($extractRes['status'] ?? null) !== 'ok') {
                throw new \RuntimeException('Extraction failed: ' . ($extractRes['error'] ?? 'unknown'));
            }
        } finally {
            // Cleanup local temp archive (same as 'final' action in Deployer widget)
            if (file_exists($archivePath)) {
                @unlink($archivePath);
            }
        }
    }

    /**
     * resolvePluginCodes returns the list of plugin codes to deploy.
     * Defaults to all dilexus plugins; overrideable via --plugins=.
     */
    protected function resolvePluginCodes(): array
    {
        if ($opt = $this->option('plugins')) {
            return array_map('trim', explode(',', $opt));
        }

        // Default: all plugins under the dilexus/ directory
        $pluginsPath = plugins_path('dilexus');
        if (!is_dir($pluginsPath)) {
            return [];
        }

        $codes = [];
        foreach (scandir($pluginsPath) as $folder) {
            if ($folder === '.' || $folder === '..') continue;
            if (is_dir("{$pluginsPath}/{$folder}")) {
                $codes[] = 'Dilexus.' . ucfirst($folder);
            }
        }

        return $codes;
    }

    /**
     * resolveServers returns the collection of servers to deploy.
     */
    protected function resolveServers()
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


/**
 * deploy:run
 *
 * Deploys a server identified by its URL or name.
 * Builds a plugin archive, uploads it, extracts it, then migrates & clears cache.
 *
 * Usage:
 *   php artisan deploy:run https://myapp.com
 *   php artisan deploy:run --name="Production"
 *   php artisan deploy:run --all                          # deploy ALL active servers
 *   php artisan deploy:run https://myapp.com --dry-run
 *   php artisan deploy:run --plugins=Dilexus.Smartdilu   # deploy a specific plugin
 *   php artisan deploy:run --plugins=Dilexus.Smartdilu,Dilexus.Deploy
 *   php artisan deploy:run --no-files                    # skip file sync (migrate + cache only)
 */
class DeployServer extends Command
{
    protected $description = 'Deploy plugins to a server (uploads files, migrates DB, clears cache)';

    protected $signature = 'deploy:run
                            {url?           : The server URL to deploy (as registered in the backend)}
                            {--name=        : Match server by name instead of URL}
                            {--all          : Deploy all active servers}
                            {--dry-run      : Print what would happen without actually deploying}
                            {--force        : Skip confirmation prompt}
                            {--plugins=     : Comma-separated plugin codes to deploy (default: all dilexus plugins)}
                            {--no-files     : Skip file upload — only run migrate and clear cache}';

    public function handle(): int
    {
        $servers = $this->resolveServers();

        if ($servers->isEmpty()) {
            $this->error('No matching server found.');
            $this->line('Use <comment>php artisan deploy:list</comment> to see available servers.');
            return self::FAILURE;
        }

        $pluginCodes = $this->resolvePluginCodes();
        $skipFiles = $this->option('no-files');

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] The following servers would be deployed:');
            foreach ($servers as $server) {
                $this->line("  » [{$server->id}] {$server->server_name} ({$server->endpoint_url})");
            }
            if (!$skipFiles) {
                $this->line('  Plugins: ' . implode(', ', $pluginCodes));
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
                if (!$skipFiles) {
                    $this->deployFiles($server, $pluginCodes);
                }

                $this->line('  → Running: clear_cache');
                $server->transmitScript('clear_cache');

                $this->line('  → Running: october:migrate');
                $response = $server->transmitArtisan('october:migrate');
                $errCode = $response['errCode'] ?? null;
                $output = isset($response['output']) ? base64_decode($response['output']) : '';
                if ((int) $errCode !== 0) {
                    throw new \RuntimeException("october:migrate failed:\n{$output}");
                }
                if ($output) {
                    foreach (explode("\n", trim($output)) as $line) {
                        $this->line("     {$line}");
                    }
                }

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

    /**
     * deployFiles builds a plugin archive locally, uploads it to the server and extracts it.
     */
    protected function deployFiles(Server $server, array $pluginCodes): void
    {
        $this->line('  → Building plugin archive (' . implode(', ', $pluginCodes) . ')');

        $filePath = temp_path('deploy-' . md5(uniqid()) . '.arc');

        try {
            ArchiveBuilder::instance()->buildPluginsBundle($filePath, $pluginCodes);

            $this->line('  → Uploading archive');
            $uploadResponse = $server->transmitFile($filePath);

            if (empty($uploadResponse['path'])) {
                throw new \RuntimeException('File upload failed — no path returned from server.');
            }

            $serverPath = base64_decode($uploadResponse['path']);

            $this->line('  → Extracting archive on server');
            $extractResponse = $server->transmitScript('extract_archive', [
                'files' => [$filePath => $serverPath],
            ]);

            $status = $extractResponse['status'] ?? null;
            if ($status !== 'ok') {
                throw new \RuntimeException('Archive extraction failed: ' . ($extractResponse['error'] ?? 'unknown error'));
            }
        } finally {
            // Always clean up the local temp archive
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
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
