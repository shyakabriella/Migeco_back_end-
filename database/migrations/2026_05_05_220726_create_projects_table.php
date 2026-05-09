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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            /*
            |--------------------------------------------------------------------------
            | Created By
            |--------------------------------------------------------------------------
            | Shows which user created the project.
            | Usually Admin, Project Manager, or Document Controller.
            */
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            /*
            |--------------------------------------------------------------------------
            | Basic Project Information
            |--------------------------------------------------------------------------
            */
            $table->string('name');

            // Example: GEO-2026-001, CONST-2026-001
            $table->string('code')->unique();

            $table->text('description')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Location Information
            |--------------------------------------------------------------------------
            | Useful for geological, mining, construction, and site documents.
            */
            $table->string('location_name')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            /*
            |--------------------------------------------------------------------------
            | Project Type
            |--------------------------------------------------------------------------
            */
            $table->enum('project_type', [
                'geological_survey',
                'construction',
                'technical_study',
                'mining',
                'administration',
                'other',
            ])->default('other');

            /*
            |--------------------------------------------------------------------------
            | Project Status
            |--------------------------------------------------------------------------
            */
            $table->enum('status', [
                'planned',
                'active',
                'completed',
                'archived',
            ])->default('planned');

            /*
            |--------------------------------------------------------------------------
            | Security Level
            |--------------------------------------------------------------------------
            | This helps us control sensitive projects.
            */
            $table->enum('security_level', [
                'public',
                'internal',
                'confidential',
                'restricted',
            ])->default('internal');

            /*
            |--------------------------------------------------------------------------
            | Project Dates
            |--------------------------------------------------------------------------
            */
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            /*
            |--------------------------------------------------------------------------
            | Extra Metadata
            |--------------------------------------------------------------------------
            | Later we can store custom data like client, contractor,
            | license number, site manager, etc.
            */
            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'project_type']);
            $table->index(['security_level']);
            $table->index(['created_by']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};