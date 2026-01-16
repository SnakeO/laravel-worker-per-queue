<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Names (Fallback)
    |--------------------------------------------------------------------------
    |
    | When no supervisors are defined, the command will run a single worker
    | listening on all queues listed here (in priority order).
    |
    */

    'queues' => [
        'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Supervisors
    |--------------------------------------------------------------------------
    |
    | Define worker supervisors for processing queues. Each supervisor can
    | listen to one or more queues with its own process count and settings.
    |
    | Available options per supervisor:
    | - queues: Array of queue names to process
    | - processes: Number of worker processes to spawn
    | - timeout: Max seconds a job may run before timing out
    | - memory: Max memory (MB) before worker restarts
    | - tries: Max attempts before marking job as failed
    | - sleep: Seconds to wait when no jobs available
    |
    */

    'supervisors' => [

        'default' => [
            'queues' => ['default'],
            'processes' => 1,
            'timeout' => 60,
            'memory' => 128,
            'tries' => 3,
            'sleep' => 3,
        ],

        // Example: High-priority queue with more workers
        // 'high-priority' => [
        //     'queues' => ['high'],
        //     'processes' => 3,
        //     'timeout' => 30,
        //     'memory' => 256,
        //     'tries' => 3,
        //     'sleep' => 1,
        // ],

        // Example: Long-running jobs with extended timeout
        // 'long-running' => [
        //     'queues' => ['exports', 'reports'],
        //     'processes' => 2,
        //     'timeout' => 600,
        //     'memory' => 512,
        //     'tries' => 1,
        //     'sleep' => 5,
        // ],

    ],

];
