<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection($this->connection())->create($this->table(), function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('status', 16);
            $table->string('resource', 2048);
            $table->string('payer', 64)->nullable();
            $table->string('pay_to', 64);
            $table->string('amount', 78);
            $table->string('asset', 64);
            $table->string('network', 32);
            $table->string('transaction', 80)->nullable();
            $table->string('nonce', 80)->nullable();
            $table->string('reason', 255)->nullable();
            $table->json('extensions')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('payer');

            // MySQL ≥ 8.0.13 + Postgres allow multiple NULLs in a UNIQUE
            // index, so a plain unique works on those engines. SQLite
            // (the testbench default) treats NULL as distinct already.
            // Older MySQL needs the application-level dedup that
            // RecordPayment::firstOrCreate provides — see spec §1.
            $table->unique('transaction');
            $table->unique('nonce');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection())->dropIfExists($this->table());
    }

    private function connection(): ?string
    {
        $value = config('x402.history.connection');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function table(): string
    {
        $value = config('x402.history.table');

        return is_string($value) && $value !== '' ? $value : 'x402_payments';
    }
};
