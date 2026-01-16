<?php

/**
 * Queue worker supervisor command with Horizon-style configuration.
 * Spawns multiple workers per supervisor with per-supervisor settings
 * for queues, processes, timeout, memory, etc.
 */

namespace Snakeo\WorkerPerQueue\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class QueueWorkAllCommand extends Command
{
    protected $signature = 'queue:work-all';

    protected $description = 'Start queue workers using supervisor configuration';

    /** @var array<string, Process> */
    protected array $workers = [];

    protected bool $shouldQuit = false;

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

        $this->registerSignalHandlers();

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
        $this->info('Press Ctrl+C to stop all workers');
        $this->newLine();

        // Monitor loop
        while (! $this->shouldQuit) {
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

            usleep(100000); // 100ms
            pcntl_signal_dispatch();
        }

        // Graceful shutdown
        $this->info('Shutting down workers...');
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
