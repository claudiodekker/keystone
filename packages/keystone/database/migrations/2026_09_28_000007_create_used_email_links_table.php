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
        Schema::create('used_email_links', function (Blueprint $table) {
            $table->id();
            $this->compareExactly($table->char('digest', 64));
            $table->timestamp('expires_at');

            $table->unique('digest');
            $table->index('expires_at');
        });
    }

    /**
     * Compare the column byte for byte, so the database never folds case.
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
