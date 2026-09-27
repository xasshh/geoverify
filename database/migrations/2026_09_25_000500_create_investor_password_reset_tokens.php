<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Reset tokens for investors, apart from staff ones. See config/auth.php. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investor_password_reset_tokens', function (Blueprint $table): void {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investor_password_reset_tokens');
    }
};
