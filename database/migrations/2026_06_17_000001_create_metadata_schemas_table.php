<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metadata_schemas', function (Blueprint $table) {
            $table->id();

            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | record_type
            |--------------------------------------------------------------------------
            | This optionally links a schema to a geological record type such as:
            | borehole, groundwater, rock_sample, fault_structure, etc.
            */
            $table->string('record_type')->nullable()->index();

            $table->unsignedInteger('version')->default(1);
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->boolean('is_system')->default(false);

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metadata_schemas');
    }
};
