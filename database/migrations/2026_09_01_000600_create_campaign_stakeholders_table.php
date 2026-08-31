<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who has to be spoken to before officers walk the ground.
 *
 * Enumeration in Nigeria is not only a data exercise. A ward that has not heard
 * from its traditional authority turns officers away at the boundary, and a
 * state agency that learns about a count from the newspaper makes the next one
 * harder. This register is the difference between a campaign that starts on
 * time and one that stalls in week two.
 *
 * `visible_to_client` exists because some of these contacts are ours. A
 * commissioner's aide who agreed to smooth a permit is a real and useful
 * relationship, and it is not something to put on a client's screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_stakeholders', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('category', 32);

            $table->string('organisation')->nullable();
            $table->string('role_title')->nullable();

            $table->string('contact_person')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();

            $table->string('engagement_status', 24)->default('identified');
            $table->text('notes')->nullable();

            // Default true: most of the register is the client's to see, and a
            // default of false would quietly hide the ordinary case.
            $table->boolean('visible_to_client')->default(true);

            $table->timestamps();

            // The client's stakeholder view filters on both of these together,
            // so they are indexed together.
            $table->index(['campaign_id', 'visible_to_client']);
            $table->index(['campaign_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_stakeholders');
    }
};
