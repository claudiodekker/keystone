<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $this->compareExactly($table->string('address'));
            $this->compareExactly($table->string('verified_address')->nullable()->unique());
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->index('address');
        });

        Schema::create('user_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('identifier')->nullable();
            $this->compareExactly($table->string('identifier_hash', 64)->nullable());
            $table->string('label', 64)->nullable();
            $table->text('secret')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->boolean('served_challenge')->default(false);
            $table->boolean('proves_multiple_factors')->default(false);
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->unique(['type', 'identifier_hash']);
            $table->index(['user_id', 'type']);
        });
    }

    /**
     * Keep the column a UTF-8 string, but compare it byte for byte, so the database never folds case or accents.
     */
    protected function compareExactly(ColumnDefinition $column): void
    {
        match (Schema::getConnection()->getDriverName()) {
            'mysql', 'mariadb' => $column->charset('utf8mb4')->collation('utf8mb4_bin'),
            'pgsql' => $column->collation('C'),
            default => null,
        };
    }
};
