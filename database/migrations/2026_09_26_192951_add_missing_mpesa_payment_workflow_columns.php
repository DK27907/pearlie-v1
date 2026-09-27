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
        if (! Schema::hasTable('mpesa_payments')) {
            Schema::create('mpesa_payments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('appointment_request_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('checkout_request_id')->nullable()->index();
                $table->string('merchant_request_id')->nullable();
                $table->string('phone');
                $table->decimal('amount', 10, 2);
                $table->string('account_reference')->nullable();
                $table->string('transaction_desc')->nullable();
                $table->string('status')->default('pending')->index();
                $table->integer('result_code')->nullable();
                $table->string('result_description')->nullable();
                $table->string('mpesa_receipt')->nullable();
                $table->json('callback_payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamp('notified_at')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('mpesa_payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('mpesa_payments', 'account_reference')) {
                    $table->string('account_reference')->nullable();
                }

                if (! Schema::hasColumn('mpesa_payments', 'transaction_desc')) {
                    $table->string('transaction_desc')->nullable();
                }

                if (Schema::hasColumn('mpesa_payments', 'amount')) {
                    $table->decimal('amount', 10, 2)->nullable()->change();
                }
            });

            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->dropForeign(['appointment_request_id']);
            });

            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->foreign('appointment_request_id')
                    ->references('id')
                    ->on('appointment_requests')
                    ->cascadeOnDelete();
            });
        }

        Schema::table('appointment_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('appointment_requests', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->index();
            }

            if (! Schema::hasColumn('appointment_requests', 'payment_amount')) {
                $table->decimal('payment_amount', 10, 2)->nullable();
            }

            if (! Schema::hasColumn('appointment_requests', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }

            if (Schema::hasColumn('appointment_requests', 'payment_status')) {
                $table->string('payment_status')->default('unpaid')->change();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('appointment_requests', 'payment_amount')) {
            Schema::table('appointment_requests', function (Blueprint $table): void {
                $table->dropColumn('payment_amount');
            });
        }

        if (Schema::hasColumn('appointment_requests', 'payment_status')) {
            Schema::table('appointment_requests', function (Blueprint $table): void {
                $table->string('payment_status')->default('pending')->change();
            });
        }

        if (Schema::hasColumn('mpesa_payments', 'amount')) {
            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->unsignedInteger('amount')->nullable()->change();
            });
        }

        if (Schema::hasTable('mpesa_payments')) {
            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->dropForeign(['appointment_request_id']);
            });

            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->foreign('appointment_request_id')
                    ->references('id')
                    ->on('appointment_requests')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasColumn('mpesa_payments', 'account_reference')
            && Schema::hasColumn('mpesa_payments', 'transaction_desc')
        ) {
            Schema::table('mpesa_payments', function (Blueprint $table): void {
                $table->dropColumn(['account_reference', 'transaction_desc']);
            });
        }
    }
};
