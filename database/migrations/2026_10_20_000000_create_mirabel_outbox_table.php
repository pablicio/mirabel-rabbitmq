<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Mirabel\RabbitMQ\Outbox\PdoOutboxStore;

// Loaded by the Laravel bridge: `php artisan migrate` creates the outbox
// table of mirabel/rabbitmq, the same table PdoOutboxStore::install() makes.
return new class extends Migration
{
    public function up(): void
    {
        (new PdoOutboxStore(DB::connection()->getPdo()))->install();
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS mirabel_outbox');
    }
};
