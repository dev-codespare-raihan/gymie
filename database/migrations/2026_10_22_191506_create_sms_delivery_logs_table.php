<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sms_delivery_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->string('recipient', 25)->nullable();
            $table->string('status', 20);
            $table->text('provider_response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['event', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sms_delivery_logs');
    }
};
