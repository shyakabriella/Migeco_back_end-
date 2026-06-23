<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 80);
            $table->string('key', 120);
            $table->string('label')->nullable();
            $table->longText('value')->nullable();
            $table->string('type', 40)->default('json');
            $table->boolean('is_public')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['group', 'key']);
            $table->index('group');
        });

        $now = now();

        DB::table('system_settings')->insert([
            [
                'group' => 'email_notifications',
                'key' => 'rules',
                'label' => 'Email Notification Rules',
                'value' => json_encode([
                    'documentUploaded' => true,
                    'documentArchived' => true,
                    'documentRestored' => true,
                    'reviewAssigned' => true,
                    'scanFailed' => true,
                    'sandboxUnsafe' => true,
                    'weeklySummary' => true,
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'system_notifications',
                'key' => 'rules',
                'label' => 'System Notification Rules',
                'value' => json_encode([
                    'inAppAlerts' => true,
                    'securityAlerts' => true,
                    'projectUpdates' => true,
                    'sampleUpdates' => true,
                    'laboratoryResults' => true,
                    'archiveActivity' => true,
                    'systemMaintenance' => true,
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'notification_preferences',
                'key' => 'defaults',
                'label' => 'Notification Preferences',
                'value' => json_encode([
                    'emailDigest' => 'daily',
                    'quietHoursEnabled' => false,
                    'quietHoursStart' => '18:00',
                    'quietHoursEnd' => '07:00',
                    'notifyOnOwnActions' => false,
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'email_server',
                'key' => 'smtp',
                'label' => 'SMTP Email Server',
                'value' => json_encode([
                    'mailerName' => 'MIGECO DMS Mailer',
                    'fromName' => 'MIGECO Document System',
                    'fromEmail' => 'no-reply@migeco.rw',
                    'replyToEmail' => 'support@migeco.rw',
                    'smtpHost' => 'smtp.migeco.rw',
                    'smtpPort' => '587',
                    'smtpUsername' => 'no-reply@migeco.rw',
                    'smtpPassword' => null,
                    'encryption' => 'tls',
                    'adminEmail' => 'admin@migeco.rw',
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'role_permissions',
                'key' => 'responsibilities',
                'label' => 'Role and Permission Responsibilities',
                'value' => json_encode([
                    'adminCanManageUsers' => true,
                    'geologistCanUpload' => true,
                    'geologistCanViewReports' => true,
                    'viewerCanDownload' => false,
                    'viewerCanViewArchive' => true,
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'system_configuration',
                'key' => 'document_lifecycle',
                'label' => 'Document Lifecycle Configuration',
                'value' => json_encode([
                    'defaultDocumentStatus' => 'quarantined',
                    'archiveRetentionDays' => '365',
                    'maxUploadSizeMb' => '50',
                    'requireScanBeforeAccess' => true,
                    'requireSandboxBeforeApproval' => true,
                    'enableAiAnalysis' => true,
                ]),
                'type' => 'json',
                'is_public' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};