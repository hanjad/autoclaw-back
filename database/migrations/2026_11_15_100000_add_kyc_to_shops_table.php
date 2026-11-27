<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Seller profile + the information we review before a shop is marked Verified
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Public profile
            $table->text('description')->nullable();
            $table->string('phone', 20)->nullable();

            // Verification details (documents are stored on the private disk)
            $table->string('business_type', 20)->default('individual'); // individual | registered_business
            $table->string('cac_number', 30)->nullable();
            $table->string('tin', 30)->nullable();
            $table->string('id_type', 30)->nullable();                  // nin | passport | drivers_license | voters_card
            $table->string('id_document')->nullable();
            $table->string('selfie')->nullable();
            $table->string('address_proof')->nullable();
            $table->string('cac_certificate')->nullable();

            // Payout account (number is stored encrypted)
            $table->string('bank_code', 10)->nullable();
            $table->string('bank_name')->nullable();
            $table->text('account_number')->nullable();
            $table->string('account_name')->nullable();       // as typed by the vendor
            $table->string('bank_account_name')->nullable();  // as returned by the bank lookup
            $table->boolean('bank_name_match')->nullable();

            // Review workflow: unsubmitted | pending | approved | rejected | suspended
            $table->string('verification_status', 20)->default('unsubmitted')->index();
            $table->text('rejection_reason')->nullable();     // also holds the reason for a suspension
            $table->timestamp('terms_accepted_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Shops already marked verified keep their status
        DB::table('shops')->where('is_verified', true)->update(['verification_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropIndex(['verification_status']);
            $table->dropColumn([
                'description', 'phone', 'business_type', 'cac_number', 'tin', 'id_type',
                'id_document', 'selfie', 'address_proof', 'cac_certificate',
                'bank_code', 'bank_name', 'account_number', 'account_name', 'bank_account_name',
                'bank_name_match', 'verification_status', 'rejection_reason',
                'terms_accepted_at', 'submitted_at', 'reviewed_at',
            ]);
        });
    }
};
