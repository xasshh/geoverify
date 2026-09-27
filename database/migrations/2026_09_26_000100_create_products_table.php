<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a business sells, in its own words.
 *
 * Party-authored outright, like the storefront photographs and unlike anything
 * an officer recorded: a business edits its own catalogue freely, because a
 * price list is the owner's statement, not the register's. Nothing here is
 * public until the listing itself is published, and nothing is hard-deleted:
 * taking a product down sets its status.
 *
 * Photographs of a product live in `media`, kind `product`, with a party author
 * under the same media_one_author constraint that keeps an officer's evidence
 * out of anything a business publishes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->constrained();
            $table->foreignId('party_id')->constrained();
            $table->string('name', 120);
            $table->string('unit', 60)->nullable();
            $table->bigInteger('price_minor')->nullable();
            $table->string('currency', 3)->default('NGN');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['enterprise_id', 'status', 'position']);
        });

        DB::statement("ALTER TABLE products ADD CONSTRAINT products_status_check CHECK (status IN ('active', 'hidden', 'withdrawn'))");
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_not_negative CHECK (price_minor IS NULL OR price_minor >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
