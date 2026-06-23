<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sample_records', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('study_area_id')->nullable()->index();
            $table->unsignedBigInteger('collector_user_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('sample_code', 100)->unique();
            $table->string('sample_name');
            $table->string('project_name')->nullable()->index();
            $table->string('study_area_name')->nullable()->index();
            $table->string('sample_type', 120)->nullable()->index();
            $table->string('material', 120)->nullable();

            $table->string('collection_location')->nullable();
            $table->string('province', 120)->nullable();
            $table->string('district', 120)->nullable()->index();
            $table->string('sector', 120)->nullable();
            $table->string('cell', 120)->nullable();
            $table->string('village', 120)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('depth', 80)->nullable();
            $table->string('collection_method', 150)->nullable();

            $table->string('collected_by')->nullable()->index();
            $table->date('collected_date')->nullable()->index();
            $table->string('storage_condition')->nullable();
            $table->text('chain_of_custody')->nullable();

            $table->enum('status', [
                'collected',
                'in_transit',
                'received',
                'testing',
                'completed',
                'rejected',
            ])->default('collected')->index();

            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['latitude', 'longitude']);
        });

        Schema::create('laboratory_results', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sample_record_id')->index();
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->string('laboratory')->nullable()->index();
            $table->string('lab_reference', 120)->nullable()->index();
            $table->date('received_date')->nullable()->index();
            $table->string('test_type', 150)->nullable()->index();
            $table->string('test_method')->nullable();
            $table->string('tested_by')->nullable();
            $table->date('test_date')->nullable()->index();

            $table->enum('result_status', [
                'pending',
                'received',
                'testing',
                'completed',
                'rejected',
                'cancelled',
            ])->default('pending')->index();

            $table->text('result_summary')->nullable();
            $table->json('test_results')->nullable();
            $table->text('interpretation')->nullable();
            $table->text('recommendation')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sample_record_id', 'lab_results_sample_record_fk')
                ->references('id')
                ->on('sample_records')
                ->cascadeOnDelete();
        });

        Schema::create('laboratory_result_documents', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('sample_record_id')->index();
            $table->unsignedBigInteger('laboratory_result_id')->index();
            $table->unsignedBigInteger('document_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable()->index();

            $table->string('document_type', 120)->nullable()->index();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('original_file_name');
            $table->string('stored_file_name');
            $table->string('file_path');
            $table->string('file_disk', 50)->default('public');
            $table->string('file_extension', 30)->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('sha256_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('sample_record_id', 'lab_docs_sample_record_fk')
                ->references('id')
                ->on('sample_records')
                ->cascadeOnDelete();

            $table->foreign('laboratory_result_id', 'lab_docs_laboratory_result_fk')
                ->references('id')
                ->on('laboratory_results')
                ->cascadeOnDelete();
        });

        $this->addOptionalForeignKeys();
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratory_result_documents');
        Schema::dropIfExists('laboratory_results');
        Schema::dropIfExists('sample_records');
    }

    private function addOptionalForeignKeys(): void
    {
        if (Schema::hasTable('projects')) {
            Schema::table('sample_records', function (Blueprint $table) {
                $table->foreign('project_id', 'sample_records_project_fk')
                    ->references('id')
                    ->on('projects')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('study_areas')) {
            Schema::table('sample_records', function (Blueprint $table) {
                $table->foreign('study_area_id', 'sample_records_study_area_fk')
                    ->references('id')
                    ->on('study_areas')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('sample_records', function (Blueprint $table) {
                $table->foreign('collector_user_id', 'sample_records_collector_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->foreign('created_by', 'sample_records_created_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });

            Schema::table('laboratory_results', function (Blueprint $table) {
                $table->foreign('created_by', 'lab_results_created_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });

            Schema::table('laboratory_result_documents', function (Blueprint $table) {
                $table->foreign('uploaded_by', 'lab_docs_uploaded_by_fk')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('documents')) {
            Schema::table('laboratory_result_documents', function (Blueprint $table) {
                $table->foreign('document_id', 'lab_docs_document_fk')
                    ->references('id')
                    ->on('documents')
                    ->nullOnDelete();
            });
        }
    }
};