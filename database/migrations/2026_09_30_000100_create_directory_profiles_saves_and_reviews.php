<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M4: what the directory's search needs that the register did not hold.
 *
 * business_profiles is the owner's statement about how to deal with them:
 * weekly opening hours a machine can read (the officer's note is free text and
 * stays as it was), whether they deliver, and the street address they choose
 * to publish, which is what Directions searches for. Like a product, it is the
 * party's statement and only appears on a claimed, published listing.
 *
 * saved_listings is a buyer's bookmark. reviews are written by a buyer about an
 * order that was released to the merchant, one per order, and can be hidden by
 * an admin on a report but never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('enterprise_id')->unique()->constrained();
            $table->foreignId('party_id')->constrained();

            // {"mon": {"opens": "08:00", "closes": "18:00"}, "sun": null, ...}
            // in Nigerian time. A day that is absent or null is closed.
            $table->jsonb('weekly_hours')->nullable();

            // Null is "has not said": the checkout keeps charging delivery as
            // before, and the Delivers filter does not match it.
            $table->boolean('delivers')->nullable();

            $table->string('street_address', 200)->nullable();
            $table->foreignId('updated_by')->constrained('portal_accounts');
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->string('fulfilment', 16)->default('delivery')->after('channel');
        });

        DB::statement("ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_fulfilment_check CHECK (fulfilment IN ('delivery', 'collection'))");

        Schema::create('saved_listings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_account_id')->constrained();
            $table->foreignId('enterprise_id')->constrained();
            // Unsaving sets this; saving again clears it. Nothing is deleted.
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['portal_account_id', 'enterprise_id']);
        });

        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_order_id')->unique()->constrained();
            $table->foreignId('enterprise_id')->constrained();
            $table->foreignId('buyer_account_id')->constrained('portal_accounts');
            $table->unsignedTinyInteger('rating');
            $table->text('body')->nullable();

            // published or hidden. Hidden by an admin on a report, with the
            // reason, and restorable: a review is evidence of how an order went.
            $table->string('status', 16)->default('published');
            $table->foreignId('moderated_by')->nullable()->constrained('users');
            $table->timestamp('moderated_at')->nullable();
            $table->string('moderation_note', 500)->nullable();
            $table->timestamps();

            $table->index(['enterprise_id', 'status']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE reviews
              ADD CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5),
              ADD CONSTRAINT reviews_status_check CHECK (status IN ('published', 'hidden')),
              ADD CONSTRAINT reviews_body_length CHECK (body IS NULL OR char_length(body) <= 1000)
        SQL);

        Schema::create('review_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained();
            $table->foreignId('reporter_account_id')->constrained('portal_accounts');
            $table->string('reason', 500);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'reporter_account_id']);
            $table->index('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_reports');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('saved_listings');
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT IF EXISTS purchase_orders_fulfilment_check');
        Schema::table('purchase_orders', function (Blueprint $table): void {
            $table->dropColumn('fulfilment');
        });
        Schema::dropIfExists('business_profiles');
    }
};
