<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('barcode_jobs', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('order_no');
            $t->string('batch_id')->nullable();
            $t->string('root');               // storage-relative folder (e.g. barcodes/order-...)
            $t->string('zip_rel_path')->nullable(); // storage-relative zip path when done
            $t->unsignedBigInteger('total_jobs')->default(0);
            $t->unsignedBigInteger('processed_jobs')->default(0);
            $t->unsignedBigInteger('failed_jobs')->default(0);
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('barcode_jobs');
    }
};
