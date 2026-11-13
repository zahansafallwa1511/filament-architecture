<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('stripe_charge_id')->unique();
            $table->unsignedInteger('amount_pence');
            $table->string('currency', 3)->default('gbp');
            $table->string('status'); // paid, partially_refunded, refunded
            $table->timestamp('paid_at');
            $table->timestamps();
        });
    }
};
