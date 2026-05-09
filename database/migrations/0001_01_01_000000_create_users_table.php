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
        /*
        |--------------------------------------------------------------------------
        | Roles Table
        |--------------------------------------------------------------------------
        | Roles are used to control what each user can do in the Document
        | Management System.
        */
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            // Example: Admin, Geologist, Engineer
            $table->string('name');

            // Example: admin, geologist, engineer
            $table->string('slug')->unique();

            $table->text('description')->nullable();

            // Store role permissions as JSON.
            // Example: ["upload_documents", "scan_documents", "approve_documents"]
            $table->json('permissions')->nullable();

            $table->timestamps();
        });

        /*
        |--------------------------------------------------------------------------
        | Users Table
        |--------------------------------------------------------------------------
        | Only the Admin user will be seeded first.
        | Other users will be created later by Admin from the system.
        */
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')
                ->nullable()
                ->constrained('roles')
                ->nullOnDelete();

            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            $table->string('password');

            // Extra fields needed for this DMS project
            $table->string('phone')->nullable();
            $table->string('department')->nullable();

            // active = can login
            // inactive = temporarily disabled
            // suspended = blocked because of security or admin decision
            $table->enum('status', ['active', 'inactive', 'suspended'])
                ->default('active');

            // Shows which admin created this user
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->rememberToken();
            $table->timestamps();

            $table->index(['role_id', 'status']);
        });

        /*
        |--------------------------------------------------------------------------
        | Password Reset Tokens
        |--------------------------------------------------------------------------
        */
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        /*
        |--------------------------------------------------------------------------
        | Sessions Table
        |--------------------------------------------------------------------------
        */
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();

            $table->foreignId('user_id')
                ->nullable()
                ->index()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('roles');
    }
};