<?php namespace Dilexus\Deploy\Console;

use Illuminate\Console\Command;
use RainLab\Deploy\Models\Server;
use RainLab\Deploy\Classes\ArchiveBuilder;

/**
 * deploy:run — CLI driver for the RainLab Deploy plugin workflow.
 *
 * Mirrors manage_onDeployToServer (Servers controller) for steps, and
 * Deployer::onExecuteStep for step execution.  No deploy logic lives here —
 * it all delegates to the RainLab Deploy plugin classes.
 *
 * Usage:
 *   php artisan deploy:run                                   interactive
 *   php artisan deploy:run https://myapp.com
 *   php artisan deploy:run --name="Production"
 *   php artisan deploy:run --all
 *   php artisan deploy:run --plugins=Dilexus.Smartdilu,Dilexus.Deploy
 *   php artisan deploy:run --no-files   (migrate + cache only)
 *   php artisan deploy:run --dry-run
 */
class DeployServer extends Command
{
    protected $description = 'Deploy plugins to a server via the RainLab Deploy workflow';

    protected $signature = 'deploy:run
                            {url?           : The server URL to deploy}
                            {--name=        : Match server by name instead of URL}
                            {--all          : Deploy all active servers}
                            {--dry-run      : Print steps without executing them}
                            {--force        : Skip confirmation prompt}
                            {--plugins=     : Comma-separated plugin codes (default: all dilexus plugins)}
                            {--no-files     : Skip file upload — only migrate and clear cache}';

    public function handle(): int
    {
        $servers = $this->resolveServers();
        $pluginCodes = $this->resolvePluginCodes();
        $skipFiles = $this->option('no-files');

        if ($servers->isEmpty()) {
            $this->error('No matching server found.');
            $this->line('Use <comment>php artisan deploy:list</comment> to see available servers.');
            return self::FAILURE;
        }

        $steps = $this->buildDeploySteps($pluginCodes, $skipFiles);

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] Would deploy to:');
            foreach ($servers as $s) {
                $this->line("  » [{$s->id}] {$s->server_name} ({$s->endpoint_url})");
            }
            $this->line('Steps:');
            foreach ($steps as $step) {
                $this->line("  · [{$step['action']}] {$step['label']}");
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
                $this->runSteps($server, $steps);
                $this->components->success("Deployed successfully [{$server->server_name}].");
            } catch (\Throwable $e) {
                $this->components->error("FAILED [{$server->server_name}]: " . $e->getMessage());
                $exitCode = self::FAILURE;
            }
        }

        return $exitCode;
    }

    // ── Step builder ──────────────────────────────────────────────────────────
    // Mirrors RainLab\Deploy\Controllers\Servers::manage_onDeployToServer

    protected function buildDeploySteps(array $pluginCodes, bool $skipFiles): array
    {
        $steps = [];
        $useFiles = [];

        if (!$skipFiles && !empty($pluginCodes)) {
            $useFiles[] = $this->queueArchiveStep($steps, 'Plugins', 'buildPluginsBundle', [$pluginCodes]);
        }

        if (!empty($useFiles)) {
            $steps[] = [
                'label' => 'Extracting Files',
                'action' => 'extractFiles',
                'files' => $useFiles,
            ];
        }

        $steps[] = [
            'label' => 'Clearing Cache',
            'action' => 'transmitScript',
            'script' => 'clear_cache',
        ];

        $steps[] = [
            'label' => 'Migrating Database',
            'action' => 'transmitArtisan',
            'artisan' => 'october:migrate',
        ];

        $steps[] = [
            'label' => 'Finishing Up',
            'action' => 'final',
            'files' => $useFiles,
        ];

        return $steps;
    }

    /**
     * queueArchiveStep mirrors Servers::buildArchiveDeployStep —
     * appends an archiveBuilder + transmitFile pair and returns the local path.
     */
    protected function queueArchiveStep(array &$steps, string $label, string $func, array $args): string
    {
        $filePath = temp_path('deploy-' . md5(uniqid()) . '.arc');

        $steps[] = [
            'label' => "Building {$label} Archive",
            'action' => 'archiveBuilder',
            'func' => $func,
            'args' => array_merge([$filePath], $args),
        ];

        $steps[] = [
            'label' => "Uploading {$label} Archive",
            'action' => 'transmitFile',
            'file' => $filePath,
        ];

        return $filePath;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    protected function resolvePluginCodes(): array
    {
        if ($opt = $this->option('plugins')) {
            return array_map('trim', explode(',', $opt));
        }

        $pluginsPath = plugins_path('dilexus');
        if (!is_dir($pluginsPath)) {
            return [];
        }

        $codes = [];
        foreach (scandir($pluginsPath) as $folder) {
            if ($folder === '.' || $folder === '..') {
                continue;
            }
            if (is_dir("{$pluginsPath}/{$folder}")) {
                $codes[] = 'Dilexus.' . ucfirst($folder);
            }
        }

        return $codes;
    }

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

    // ── Step executor ─────────────────────────────────────────────────────────
    // Mirrors RainLab\Deploy\Widgets\Deployer::onExecuteStep

    protected function runSteps(Server $server, array $steps): void
    {
        // fileMap accumulates local→server path pairs across transmitFile steps,
        // exactly as deployer.js does in the browser (self.fileMap[step.file] = data.path).
        $fileMap = [];

        foreach ($steps as $step) {
            $this->line("  → {$step['label']}");

            switch ($step['action']) {

                case 'archiveBuilder':
                    // Mirrors: ArchiveBuilder::instance()->$func(...$args)
                    ArchiveBuilder::instance()->{$step['func']}(...$step['args']);
                    break;

                case 'transmitFile':
                    // Mirrors: $server->transmitFile($file) → return ['path' => decoded]
                    $res = $server->transmitFile($step['file']);
                    if (empty($res['path'])) {
                        throw new \RuntimeException('transmitFile returned no path.');
                    }
                    $fileMap[$step['file']] = base64_decode($res['path']);
                    break;

                case 'extractFiles':
                    // Mirrors: $server->transmitScript('extract_archive', ['files' => $fileMap])
                    $res = $server->transmitScript('extract_archive', ['files' => $fileMap]);
                    if (($res['status'] ?? null) !== 'ok') {
                        throw new \RuntimeException('extract_archive failed: ' . ($res['error'] ?? 'unknown'));
                    }
                    break;

                case 'transmitScript':
                    $res = $server->transmitScript($step['script'], $step['vars'] ?? []);
                    if (($res['status'] ?? null) !== 'ok') {
                        throw new \RuntimeException("{$step['script']} failed: " . ($res['error'] ?? 'unknown'));
                    }
                    break;

                case 'transmitArtisan':
                    // Mirrors: $server->transmitArtisan($cmd), checks errCode, outputs result
                    $res = $server->transmitArtisan($step['artisan']);
                    $errCode = $res['errCode'] ?? null;
                    $output = isset($res['output']) ? base64_decode($res['output']) : '';
                    if ((int) $errCode !== 0) {
                        throw new \RuntimeException("{$step['artisan']} failed:\n{$output}");
                    }
                    foreach (array_filter(explode("\n", trim($output))) as $line) {
                        $this->line("     {$line}");
                    }
                    break;

                case 'final':
                    // Mirrors Deployer 'final': cleanup temp files, update last-deploy timestamp
                    foreach ((array) ($step['files'] ?? []) as $file) {
                        if ($file && file_exists($file)) {
                            @unlink($file);
                        }
                    }
                    $server->touchLastDeploy();
                    $server->touchLastVersion();
                    break;
            }
        }
    }
}
