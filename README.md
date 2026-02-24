# Dilexus Deploy Commander

An OctoberCMS plugin that adds CLI commands to list and deploy servers managed by the [RainLab Deploy](https://octobercms.com/plugin/rainlab-deploy) plugin.

## Requirements

- OctoberCMS 4.x
- [RainLab.Deploy](https://octobercms.com/plugin/rainlab-deploy) plugin installed and configured
- At least one server added and beacon installed via **RainLab Deploy → Servers → Manage**

## Installation

1. Copy the `dilexus/deploy` directory into your `plugins/` folder.
2. Run migrations:

```bash
php artisan october:migrate
```

## Commands

### `deploy:list`

Lists all servers registered in RainLab Deploy.

```bash
# List active and ready servers
php artisan deploy:list

# Include unreachable/legacy servers
php artisan deploy:list --all

# Output as JSON
php artisan deploy:list --json
```

Example output:

```
+----+-------------------+----------------------------+--------+---------+---------------+
| ID | Name              | URL                        | Status | Version | Last Deploy   |
+----+-------------------+----------------------------+--------+---------+---------------+
| 1  | Production Server | https://app.example.com/   | active | 4.1.14  | 2 hours ago   |
+----+-------------------+----------------------------+--------+---------+---------------+
```

---

### `deploy:run`

Deploys a server by running `october:migrate` and `clear_cache` via the RainLab beacon.

```bash
# Deploy by URL
php artisan deploy:run https://app.example.com/

# Deploy by name (partial match)
php artisan deploy:run --name="Production"

# Interactive selection (prompts you to pick a server)
php artisan deploy:run

# Deploy all active servers without confirmation
php artisan deploy:run --all --force

# Preview without actually deploying
php artisan deploy:run --dry-run
```

#### Options

| Option | Description |
|--------|-------------|
| `url` | Server endpoint URL (as registered in RainLab Deploy) |
| `--name` | Match server by name (partial) |
| `--all` | Deploy all active/ready servers |
| `--force` | Skip the confirmation prompt |
| `--dry-run` | Print what would be deployed without executing |

#### What happens during deployment

1. Connects to the server via its RainLab Deploy beacon
2. Runs `php artisan october:migrate` on the remote server
3. Runs the `clear_cache` script on the remote server
4. Updates `last_deploy_at` and `last_version` on the server record

## Permissions

| Permission | Description |
|------------|-------------|
| `dilexus.deploy.servers` | Manage servers in the backend |
| `dilexus.deploy.logs` | View deployment logs |

## Backend Navigation

The plugin adds a **Deploy** section to the backend sidebar with:

- **Servers** — view servers (mirrors RainLab Deploy data)
- **Deploy Logs** — history of deployments triggered from this plugin

## Notes

- This plugin does **not** replace RainLab Deploy. It wraps its beacon API for CLI use.
- Servers must have a working beacon endpoint (configured via RainLab Deploy) for `deploy:run` to succeed.
- The `status` shown in `deploy:list` reflects the RainLab Deploy beacon status check, not the result of the last `deploy:run`.
