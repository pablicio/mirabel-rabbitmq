<?php

declare(strict_types=1);

namespace Mirabel\RabbitMQ\Laravel;

use Illuminate\Console\Command;
use Mirabel\RabbitMQ\Worker;

/**
 * php artisan mirabel:declare                    every worker in app/Workers
 * php artisan mirabel:declare EmitirNotaFiscal   only these
 *
 * A deploy step: the queues exist before the first event is published.
 */
final class DeclareCommand extends Command
{
    protected $signature = 'mirabel:declare {worker?* : class names; none means every worker in app/Workers}';

    protected $description = 'Declare the queues and bindings of workers';

    public function handle(): int
    {
        $names = (array) $this->argument('worker');
        $classes = $names === [] ? $this->discover() : array_map($this->resolve(...), $names);

        foreach ($classes as $class) {
            if (!is_subclass_of($class, Worker::class)) {
                $this->error("{$class} is not a Mirabel worker.");

                return self::INVALID;
            }
        }

        foreach ($classes as $class) {
            $this->laravel->make($class)->declare();
            $definition = $class::definition();
            $keys = implode(', ', $definition['routing_keys']) ?: '(no routing keys)';
            $this->line("{$definition['queue']} <- {$keys}");
        }

        return self::SUCCESS;
    }

    private function resolve(string $name): string
    {
        $name = ltrim($name, '\\');

        return class_exists($name) ? $name : $this->laravel->getNamespace() . 'Workers\\' . $name;
    }

    /** @return list<class-string<Worker>> */
    private function discover(): array
    {
        $directory = $this->laravel->path('Workers');
        $classes = [];
        foreach (glob($directory . '/*.php') ?: [] as $file) {
            $class = $this->laravel->getNamespace() . 'Workers\\' . basename($file, '.php');
            if (is_subclass_of($class, Worker::class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }
        sort($classes);

        return $classes;
    }
}
