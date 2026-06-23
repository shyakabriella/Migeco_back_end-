<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('study_areas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('project_id')
                ->nullable()
                ->constrained('projects')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('name');
            $table->string('code', 100)->nullable()->unique();
            $table->string('slug')->nullable()->unique();
            $table->string('project_name')->nullable();
            $table->text('description')->nullable();

            $table->string('province')->nullable();
            $table->string('district')->nullable();
            $table->string('sector')->nullable();
            $table->string('cell')->nullable();
            $table->string('village')->nullable();
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('elevation')->nullable();
            $table->string('area_size')->nullable();

            $table->string('map_title')->nullable();
            $table->string('map_type')->nullable();
            $table->string('map_reference')->nullable();
            $table->string('map_scale')->nullable();
            $table->string('coordinate_system')->nullable()->default('WGS 84');
            $table->string('location_accuracy')->nullable();
            $table->text('access_route')->nullable();
            $table->string('field_team')->nullable();

            $table->enum('status', [
                'planned',
                'active',
                'under_review',
                'archived',
            ])->default('planned')->index();

            $table->date('last_surveyed')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'status']);
            $table->index(['district', 'sector']);
            $table->index(['latitude', 'longitude']);
        });

        Schema::create('study_area_photos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('study_area_id')
                ->constrained('study_areas')
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('caption')->nullable();
            $table->string('original_file_name');
            $table->string('stored_file_name');
            $table->string('file_path');
            $table->string('disk')->default('public');
            $table->string('mime_type')->nullable();
            $table->string('extension', 20)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['study_area_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('study_area_photos');
        Schema::dropIfExists('study_areas');
    }
};