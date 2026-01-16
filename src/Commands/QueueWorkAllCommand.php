<?php

/**
 * Queue worker supervisor command with Horizon-style configuration.
 * Spawns multiple workers per supervisor with per-supervisor settings
 * for queues, processes, timeout, memory, etc.
 *
 * Features:
 * - Config-driven worker counts per queue
 * - Auto-restart dead workers
 * - Optional integrated scheduler (--with-scheduler)
 * - Queue job counts on startup
 * - Periodic status output every 5 minutes
 */

namespace Snakeo\WorkerPerQueue\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class QueueWorkAllCommand extends Command
{
    protected $signature = 'queue:work-all {--with-scheduler : Also run the Laravel scheduler}';

    protected $description = 'Start queue workers using supervisor configuration';

    /** @var array<string, Process> */
    protected array $workers = [];

    protected ?Process $schedulerProcess = null;

    protected bool $shouldQuit = false;

    protected int $lastStatusOutput = 0;

    protected bool $lastStatusWasEmpty = false;

    protected const STATUS_INTERVAL = 300; // 5 minutes

    public function handle(): int
    {
        $supervisors = config('worker-per-queue.supervisors', []);

        if (empty($supervisors)) {
            return $this->runLegacyMode();
        }

        return $this->runSupervisorMode($supervisors);
    }

    /**
     * Legacy mode: single worker on all queues (backward compatible).
     */
    protected function runLegacyMode(): int
    {
        $queues = config('worker-per-queue.queues', ['default']);
        $queueList = implode(',', $queues);

        $this->info("Starting single worker for: {$queueList}");

        $process = new Process([
            PHP_BINARY,
            'artisan',
            'queue:work',
            "--queue={$queueList}",
        ], base_path());

        $process->setTimeout(null);
        $process->setTty(Process::isTtySupported());

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->getExitCode() ?? self::SUCCESS;
    }

    /**
     * Supervisor mode: spawn workers per supervisor config.
     */
    protected function runSupervisorMode(array $supervisors): int
    {
        $this->displaySupervisorSummary($supervisors);
        $this->displayQueueStatus();

        $this->registerSignalHandlers();

        // Start scheduler if requested
        if ($this->option('with-scheduler')) {
            $this->startScheduler();
        }

        $totalWorkers = 0;

        foreach ($supervisors as $name => $config) {
            $processes = $config['processes'] ?? 1;
            $queues = implode(',', $config['queues'] ?? ['default']);

            for ($i = 1; $i <= $processes; $i++) {
                $this->startWorker($name, $i, $queues, $config);
                $totalWorkers++;
            }
        }

        $supervisorCount = count($supervisors);
        $this->newLine();
        $this->info("{$totalWorkers} workers started across {$supervisorCount} supervisors");
        if ($this->schedulerProcess) {
            $this->info('Scheduler running (PID: '.$this->schedulerProcess->getPid().')');
        }
        $this->info('Press Ctrl+C to stop all workers');
        $this->newLine();

        $this->lastStatusOutput = time();

        // Monitor loop
        while (! $this->shouldQuit) {
            // Check scheduler process
            if ($this->schedulerProcess && ! $this->schedulerProcess->isRunning() && ! $this->shouldQuit) {
                $this->warn('[scheduler] died, restarting...');
                $this->startScheduler();
            }

            foreach ($this->workers as $workerId => $process) {
                $output = $process->getIncrementalOutput();
                $errorOutput = $process->getIncrementalErrorOutput();

                if ($output) {
                    $this->writeWorkerOutput($workerId, $output);
                }
                if ($errorOutput) {
                    $this->writeWorkerOutput($workerId, $errorOutput, true);
                }

                // Restart dead workers
                if (! $process->isRunning() && ! $this->shouldQuit) {
                    $exitCode = $process->getExitCode();
                    $this->warn("[{$workerId}] exited (code {$exitCode}), restarting...");

                    // Parse supervisor name and id from workerId
                    [$supervisor, $id] = explode(':', $workerId);
                    $config = $supervisors[$supervisor] ?? [];
                    $queues = implode(',', $config['queues'] ?? ['default']);

                    $this->startWorker($supervisor, (int) $id, $queues, $config);
                }
            }

            // Periodic status output
            $this->outputPeriodicStatus();

            usleep(100000); // 100ms
            pcntl_signal_dispatch();
        }

        // Graceful shutdown
        $this->info('Shutting down workers...');

        if ($this->schedulerProcess && $this->schedulerProcess->isRunning()) {
            $this->schedulerProcess->stop(5, SIGTERM);
            $this->line('[scheduler] stopped');
        }

        foreach ($this->workers as $workerId => $process) {
            if ($process->isRunning()) {
                $process->stop(10, SIGTERM);
                $this->line("[{$workerId}] stopped");
            }
        }

        $this->info('All workers stopped');

        return self::SUCCESS;
    }

    /**
     * Start the Laravel scheduler process.
     */
    protected function startScheduler(): void
    {
        $command = [
            PHP_BINARY,
            'artisan',
            'schedule:work',
        ];

        $this->schedulerProcess = new Process($command, base_path());
        $this->schedulerProcess->setTimeout(null);
        $this->schedulerProcess->start();

        $this->info('[scheduler] started (PID: '.$this->schedulerProcess->getPid().')');
    }

    /**
     * Display a summary table of supervisor configuration.
     */
    protected function displaySupervisorSummary(array $supervisors): void
    {
        $this->newLine();
        $this->info('Queue Supervisor Configuration');
        $this->line(str_repeat('-', 70));

        $rows = [];
        $totalWorkers = 0;

        foreach ($supervisors as $name => $config) {
            $processes = $config['processes'] ?? 1;
            $queues = implode(', ', $config['queues'] ?? ['default']);
            $timeout = $config['timeout'] ?? 60;
            $memory = $config['memory'] ?? 128;
            $tries = $config['tries'] ?? 1;

            $rows[] = [
                "<fg=cyan>{$name}</>",
                $queues,
                "<fg=yellow>{$processes}</>",
                "{$timeout}s",
                "{$memory}MB",
                $tries,
            ];

            $totalWorkers += $processes;
        }

        $this->table(
            ['Supervisor', 'Queues', 'Workers', 'Timeout', 'Memory', 'Tries'],
            $rows
        );

        $this->newLine();
        $this->info("Starting {$totalWorkers} workers...");
        $this->newLine();
    }

    /**
     * Display current queue job counts.
     */
    protected function displayQueueStatus(): void
    {
        $counts = $this->getQueueCounts();
        $total = array_sum($counts);

        if ($total === 0) {
            $this->line('<fg=gray>Queues: all empty</>');
            return;
        }

        $parts = [];
        foreach ($counts as $queue => $count) {
            if ($count > 0) {
                $parts[] = "<fg=yellow>{$queue}</>: {$count}";
            }
        }

        $this->line('Queued jobs: '.implode(' | ', $parts)." (<fg=yellow>{$total} total</>)");
    }

    /**
     * Get job counts per queue from the database.
     */
    protected function getQueueCounts(): array
    {
        $counts = [];
        $supervisors = config('worker-per-queue.supervisors', []);

        // Collect all queue names
        $allQueues = [];
        foreach ($supervisors as $config) {
            foreach ($config['queues'] ?? ['default'] as $queue) {
                $allQueues[$queue] = true;
            }
        }

        // Get counts from jobs table
        $connection = config('queue.connections.database.connection');
        $table = config('queue.connections.database.table', 'jobs');

        try {
            $results = DB::connection($connection)
                ->table($table)
                ->select('queue', DB::raw('COUNT(*) as count'))
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray();

            foreach (array_keys($allQueues) as $queue) {
                $counts[$queue] = $results[$queue] ?? 0;
            }
        } catch (\Throwable $e) {
            // If we can't query the database, return empty counts
            foreach (array_keys($allQueues) as $queue) {
                $counts[$queue] = 0;
            }
        }

        return $counts;
    }

    /**
     * Output periodic status (every 5 minutes).
     */
    protected function outputPeriodicStatus(): void
    {
        $now = time();

        if ($now - $this->lastStatusOutput < self::STATUS_INTERVAL) {
            return;
        }

        $this->lastStatusOutput = $now;

        $counts = $this->getQueueCounts();
        $total = array_sum($counts);

        if ($total === 0) {
            // Only output "empty" once until queue has jobs again
            if (! $this->lastStatusWasEmpty) {
                $timestamp = date('H:i:s');
                $this->line("<fg=gray>[{$timestamp}] Queues: all empty</>");
                $this->lastStatusWasEmpty = true;
            }
            return;
        }

        // Queue has jobs - output status
        $this->lastStatusWasEmpty = false;
        $timestamp = date('H:i:s');

        $parts = [];
        foreach ($counts as $queue => $count) {
            if ($count > 0) {
                $parts[] = "{$queue}:{$count}";
            }
        }

        $this->line("<fg=gray>[{$timestamp}]</> Queued: ".implode(' ', $parts)." (<fg=yellow>{$total}</>)");
    }

    protected function startWorker(string $supervisor, int $id, string $queues, array $config): void
    {
        $command = [
            PHP_BINARY,
            'artisan',
            'queue:work',
            "--queue={$queues}",
            '--timeout='.($config['timeout'] ?? 60),
            '--memory='.($config['memory'] ?? 128),
            '--tries='.($config['tries'] ?? 1),
            '--sleep='.($config['sleep'] ?? 3),
        ];

        $process = new Process($command, base_path());
        $process->setTimeout(null);
        $process->start();

        $workerId = "{$supervisor}:{$id}";
        $this->workers[$workerId] = $process;
        $this->info("[{$workerId}] started (PID: {$process->getPid()}) -> {$queues}");
    }

    protected function writeWorkerOutput(string $workerId, string $output, bool $isError = false): void
    {
        $lines = explode("\n", trim($output));
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $prefix = "<fg=cyan>[{$workerId}]</>";
            if ($isError) {
                $this->line("{$prefix} <fg=red>{$line}</>");
            } else {
                $this->line("{$prefix} {$line}");
            }
        }
    }

    protected function registerSignalHandlers(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->warn('PCNTL extension not loaded - graceful shutdown may not work');

            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->shutdown());
        pcntl_signal(SIGINT, fn () => $this->shutdown());
    }

    protected function shutdown(): void
    {
        $this->newLine();
        $this->info('Received shutdown signal...');
        $this->shouldQuit = true;
    }
}
