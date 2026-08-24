<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();

            // Which configured endpoint this came through. A site can run several
            // (a free consultation and a paid lesson are different funnels with
            // different secrets), and everything downstream needs to know which.
            $table->string('endpoint', 64)->index();

            // The provider's own id for this booking. It is what makes a
            // redelivered webhook idempotent, so it is unique and not merely
            // indexed — the database refuses the second row rather than trusting
            // the code to check first.
            $table->string('external_id', 191);

            $table->string('status', 32)->default('booked');
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->string('timezone', 64)->nullable();
            // Integer, not smallInteger: 65535 minutes is 45 days, and a
            // multi-day format would hit a SQL error in strict mode rather than
            // simply storing a large number.
            $table->unsignedInteger('duration_minutes')->nullable();

            // Who booked. Deliberately plain columns and not a relation: a
            // booking is made by whoever filled the form, who need not have an
            // account and usually does not.
            $table->string('name')->nullable();
            $table->string('email')->nullable();

            $table->string('meeting_url')->nullable();
            $table->json('meta')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['endpoint', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
