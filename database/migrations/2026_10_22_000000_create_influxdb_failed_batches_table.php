<?php

declare(strict_types=1);

use Hypervel\Database\Migrations\Migration;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('influxdb_failed_batches', function (Blueprint $table) {
            $table->id();
            $table->string('connection');
            $table->string('bucket');
            $table->string('org');
            $table->string('precision', 2);
            $table->longText('payload');
            $table->unsignedInteger('attempts');
            $table->dateTime('failed_at', 6);
            $table->text('exception');

            $table->index(['connection', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('influxdb_failed_batches');
    }
};
