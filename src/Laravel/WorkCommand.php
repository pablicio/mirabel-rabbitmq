<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

use Illuminate\Console\Command;
use Mirabel\RabbitMQ\Worker;

/**
 * php artisan mirabel:work EmitirNotaFiscal
 * php artisan mirabel:work "App\Workers\EmitirNotaFiscal"
 */
final class WorkCommand extends Command
{
    protected $signature = 'mirabel:work {worker : class name, short (App\\Workers) or fully qualified}';

    protected $description = 'Run a Mirabel worker until it is stopped';

    public function handle(): int
    {
        $name = ltrim((string) $this->argument('worker'), '\\');
        $class = class_exists($name)
            ? $name
            : $this->laravel->getNamespace() . 'Workers\\' . $name;

        if (!is_subclass_of($class, Worker::class)) {
            $this->error("{$class} is not a Mirabel worker.");

            return self::INVALID;
        }

        $queue = $class::definition()['queue'];
        $short = substr(strrchr('\\' . $class, '\\'), 1);
        $this->info("{$short} consuming {$queue}, Ctrl+C to stop");
        $this->laravel->make($class)->subscribe();

        return self::SUCCESS;
    }
}
