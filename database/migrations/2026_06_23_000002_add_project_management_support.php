<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Project Management Support
|--------------------------------------------------------------------------
| This migration is intentionally defensive.
| It creates the projects table if it does not exist, or only adds missing
| columns when the table already exists.
|
| It also ensures documents can belong to a project through project_id.
*/
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('project_manager_id')->nullable();

                $table->string('name');
                $table->string('code', 100)->unique();
                $table->string('slug')->nullable()->index();
                $table->text('description')->nullable();

                $table->string('location_name')->nullable();
                $table->decimal('latitude', 11, 8)->nullable();
                $table->decimal('longitude', 11, 8)->nullable();

                $table->string('project_type', 60)->default('other')->index();
                $table->string('status', 40)->default('planned')->index();
                $table->string('security_level', 40)->default('internal')->index();

                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();

                $table->json('metadata')->nullable();

                $table->unsignedBigInteger('archived_by')->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->text('archive_reason')->nullable();

                $table->unsignedBigInteger('restored_by')->nullable();
                $table->timestamp('restored_at')->nullable();
                $table->text('restore_reason')->nullable();

                $table->timestamps();
                $table->softDeletes();
            });
        } else {
            $this->addColumnIfMissing('projects', 'created_by', function (Blueprint $table) {
                $table->unsignedBigInteger('created_by')->nullable();
            });

            $this->addColumnIfMissing('projects', 'project_manager_id', function (Blueprint $table) {
                $table->unsignedBigInteger('project_manager_id')->nullable();
            });

            $this->addColumnIfMissing('projects', 'code', function (Blueprint $table) {
                $table->string('code', 100)->nullable();
            });

            $this->addColumnIfMissing('projects', 'slug', function (Blueprint $table) {
                $table->string('slug')->nullable();
            });

            $this->addColumnIfMissing('projects', 'description', function (Blueprint $table) {
                $table->text('description')->nullable();
            });

            $this->addColumnIfMissing('projects', 'location_name', function (Blueprint $table) {
                $table->string('location_name')->nullable();
            });

            $this->addColumnIfMissing('projects', 'latitude', function (Blueprint $table) {
                $table->decimal('latitude', 11, 8)->nullable();
            });

            $this->addColumnIfMissing('projects', 'longitude', function (Blueprint $table) {
                $table->decimal('longitude', 11, 8)->nullable();
            });

            $this->addColumnIfMissing('projects', 'project_type', function (Blueprint $table) {
                $table->string('project_type', 60)->default('other');
            });

            $this->addColumnIfMissing('projects', 'status', function (Blueprint $table) {
                $table->string('status', 40)->default('planned');
            });

            $this->addColumnIfMissing('projects', 'security_level', function (Blueprint $table) {
                $table->string('security_level', 40)->default('internal');
            });

            $this->addColumnIfMissing('projects', 'start_date', function (Blueprint $table) {
                $table->date('start_date')->nullable();
            });

            $this->addColumnIfMissing('projects', 'end_date', function (Blueprint $table) {
                $table->date('end_date')->nullable();
            });

            $this->addColumnIfMissing('projects', 'metadata', function (Blueprint $table) {
                $table->json('metadata')->nullable();
            });

            $this->addColumnIfMissing('projects', 'archived_by', function (Blueprint $table) {
                $table->unsignedBigInteger('archived_by')->nullable();
            });

            $this->addColumnIfMissing('projects', 'archived_at', function (Blueprint $table) {
                $table->timestamp('archived_at')->nullable();
            });

            $this->addColumnIfMissing('projects', 'archive_reason', function (Blueprint $table) {
                $table->text('archive_reason')->nullable();
            });

            $this->addColumnIfMissing('projects', 'restored_by', function (Blueprint $table) {
                $table->unsignedBigInteger('restored_by')->nullable();
            });

            $this->addColumnIfMissing('projects', 'restored_at', function (Blueprint $table) {
                $table->timestamp('restored_at')->nullable();
            });

            $this->addColumnIfMissing('projects', 'restore_reason', function (Blueprint $table) {
                $table->text('restore_reason')->nullable();
            });

            if (!Schema::hasColumn('projects', 'deleted_at')) {
                Schema::table('projects', function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        if (Schema::hasTable('documents') && !Schema::hasColumn('documents', 'project_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Safe rollback
        |--------------------------------------------------------------------------
        | This migration may upgrade an existing production projects table.
        | To avoid accidental data loss, rollback is intentionally left empty.
        | If you need to remove columns, create a separate reviewed migration.
        */
    }

    private function addColumnIfMissing(string $tableName, string $columnName, callable $callback): void
    {
        if (!Schema::hasColumn($tableName, $columnName)) {
            Schema::table($tableName, function (Blueprint $table) use ($callback) {
                $callback($table);
            });
        }
    }
};