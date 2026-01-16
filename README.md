# Laravel Worker Per Queue

Configure dedicated workers per queue with Horizon-style supervisors for **any Laravel queue driver** (database, Redis, SQS, etc.).

No Redis required. No Horizon dashboard. Just simple, config-driven queue workers.

## Why?

Laravel's built-in `queue:work` command runs a single worker. To run multiple workers, you need:
- Multiple terminal tabs, or
- Supervisor process manager, or
- Laravel Horizon (requires Redis)

**This package** lets you define workers-per-queue in a config file and spawns them all with a single command:

```bash
php artisan queue:work-all
```

## Installation

```bash
composer require snakeo/laravel-worker-per-queue
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag=worker-per-queue-config
```

## Configuration

Edit `config/worker-per-queue.php`:

```php
'supervisors' => [

    'default' => [
        'queues' => ['default'],
        'processes' => 1,
        'timeout' => 60,
        'memory' => 128,
        'tries' => 3,
        'sleep' => 3,
    ],

    'emails' => [
        'queues' => ['emails'],
        'processes' => 2,      // 2 workers for email queue
        'timeout' => 120,
        'memory' => 256,
        'tries' => 3,
        'sleep' => 3,
    ],

    'exports' => [
        'queues' => ['exports', 'reports'],  // One supervisor for multiple queues
        'processes' => 3,
        'timeout' => 600,      // Long timeout for exports
        'memory' => 512,
        'tries' => 1,
        'sleep' => 5,
    ],

],
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `queues` | `['default']` | Array of queue names to process |
| `processes` | `1` | Number of worker processes to spawn |
| `timeout` | `60` | Max seconds a job may run |
| `memory` | `128` | Memory limit in MB before worker restarts |
| `tries` | `1` | Max attempts before marking job as failed |
| `sleep` | `3` | Seconds to wait when no jobs available |

## Usage

Start all workers:

```bash
php artisan queue:work-all
```

### With Scheduler

Run the Laravel scheduler alongside workers (great for development):

```bash
php artisan queue:work-all --with-scheduler
```

This spawns `schedule:work` as a managed process that auto-restarts if it dies.

### Example Output

```
Queue Supervisor Configuration
----------------------------------------------------------------------
+------------------+------------------+---------+---------+--------+-------+
| Supervisor       | Queues           | Workers | Timeout | Memory | Tries |
+------------------+------------------+---------+---------+--------+-------+
| default          | default          | 1       | 60s     | 128MB  | 3     |
| gmail-sync       | gmail-sync       | 2       | 120s    | 256MB  | 3     |
| email-processing | email-processing | 3       | 90s     | 512MB  | 2     |
| bot-tasks        | bot-tasks        | 1       | 300s    | 512MB  | 1     |
+------------------+------------------+---------+---------+--------+-------+

Queued jobs: gmail-sync: 5 | email-processing: 12 (17 total)

Starting 7 workers...

[scheduler] started (PID: 12344)
[default:1] started (PID: 12345) -> default
[gmail-sync:1] started (PID: 12346) -> gmail-sync
[gmail-sync:2] started (PID: 12347) -> gmail-sync
[email-processing:1] started (PID: 12348) -> email-processing
[email-processing:2] started (PID: 12349) -> email-processing
[email-processing:3] started (PID: 12350) -> email-processing
[bot-tasks:1] started (PID: 12351) -> bot-tasks

7 workers started across 4 supervisors
Scheduler running (PID: 12344)
Press Ctrl+C to stop all workers

[14:35:00] Queued: gmail-sync:2 email-processing:8 (10)
[14:40:00] Queues: all empty
```

### Features

- **Auto-restart**: Dead workers are automatically restarted
- **Graceful shutdown**: Ctrl+C stops all workers cleanly
- **Output aggregation**: All worker output is prefixed with worker ID
- **Summary table**: See your configuration before workers start
- **Queue counts**: Shows pending jobs per queue on startup
- **Periodic status**: Outputs queue status every 5 minutes (suppresses repeated "empty" messages)
- **Integrated scheduler**: Optional `--with-scheduler` runs Laravel scheduler alongside workers

## Comparison to Horizon

| Feature | Horizon | Worker Per Queue |
|---------|---------|------------------|
| Redis required | Yes | No |
| Web dashboard | Yes | No |
| Queue driver | Redis only | Any (database, SQS, etc.) |
| Config-driven workers | Yes | Yes |
| Auto-restart workers | Yes | Yes |
| Graceful shutdown | Yes | Yes |
| Metrics & monitoring | Yes | No |
| Integrated scheduler | No | Yes (`--with-scheduler`) |
| Queue job counts | Yes | Yes |

Use **Horizon** if you need the dashboard and Redis-based features.

Use **Worker Per Queue** if you want simple config-driven workers with any queue driver.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- PCNTL extension (for graceful shutdown)

## License

MIT License. See [LICENSE](LICENSE) for details.
