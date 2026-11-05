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
        Schema::create('ec_products', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 160)->unique();
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->boolean('reading_recommendable')->default(false)->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('ec_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('ec_product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('ec_products')->restrictOnDelete();
            $table->string('sku', 100)->unique();
            $table->string('label', 255)->default('標準');
            $table->json('options')->nullable();
            $table->unsignedBigInteger('price_minor');
            $table->char('currency', 3)->default('JPY');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('ec_category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('ec_categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('ec_products')->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
            $table->index(['product_id', 'category_id']);
        });

        Schema::create('ec_product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('ec_products')->cascadeOnDelete();
            $table->string('storage_disk', 64)->default('public');
            $table->text('path');
            $table->string('alt_text')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::create('ec_inventory_balances', function (Blueprint $table) {
            $table->foreignId('variant_id')->primary()->constrained('ec_product_variants')->restrictOnDelete();
            $table->unsignedInteger('on_hand')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ec_inventory_balances');
        Schema::dropIfExists('ec_product_images');
        Schema::dropIfExists('ec_category_product');
        Schema::dropIfExists('ec_product_variants');
        Schema::dropIfExists('ec_categories');
        Schema::dropIfExists('ec_products');
    }
};
