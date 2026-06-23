<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\API\BaseController as BaseController;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SettingsController extends BaseController
{
    private const EMAIL_NOTIFICATIONS_DEFAULTS = [
        'documentUploaded' => true,
        'documentArchived' => true,
        'documentRestored' => true,
        'reviewAssigned' => true,
        'scanFailed' => true,
        'sandboxUnsafe' => true,
        'weeklySummary' => true,
    ];

    private const SYSTEM_NOTIFICATIONS_DEFAULTS = [
        'inAppAlerts' => true,
        'securityAlerts' => true,
        'projectUpdates' => true,
        'sampleUpdates' => true,
        'laboratoryResults' => true,
        'archiveActivity' => true,
        'systemMaintenance' => true,
    ];

    private const NOTIFICATION_PREFERENCES_DEFAULTS = [
        'emailDigest' => 'daily',
        'quietHoursEnabled' => false,
        'quietHoursStart' => '18:00',
        'quietHoursEnd' => '07:00',
        'notifyOnOwnActions' => false,
    ];

    private const EMAIL_SERVER_DEFAULTS = [
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
    ];

    private const ROLE_PERMISSION_DEFAULTS = [
        'adminCanManageUsers' => true,
        'geologistCanUpload' => true,
        'geologistCanViewReports' => true,
        'viewerCanDownload' => false,
        'viewerCanViewArchive' => true,
    ];

    private const SYSTEM_CONFIGURATION_DEFAULTS = [
        'defaultDocumentStatus' => 'quarantined',
        'archiveRetentionDays' => '365',
        'maxUploadSizeMb' => '50',
        'requireScanBeforeAccess' => true,
        'requireSandboxBeforeApproval' => true,
        'enableAiAnalysis' => true,
    ];

    private function canManageSystemSettings($user): bool
    {
        return $user && (
            $user->isAdmin()
            || $user->hasPermission('manage_system_settings')
            || $user->role?->slug === 'admin'
        );
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $user->load('role');

        return $this->sendResponse([
            'profile' => $this->profilePayload($user),
            'email_notifications' => $this->getStoredGroup(
                'email_notifications',
                'rules',
                self::EMAIL_NOTIFICATIONS_DEFAULTS
            ),
            'system_notifications' => $this->getStoredGroup(
                'system_notifications',
                'rules',
                self::SYSTEM_NOTIFICATIONS_DEFAULTS
            ),
            'notification_preferences' => $this->getStoredGroup(
                'notification_preferences',
                'defaults',
                self::NOTIFICATION_PREFERENCES_DEFAULTS
            ),
            'email_server' => $this->emailServerPayload(),
            'role_permissions' => $this->getStoredGroup(
                'role_permissions',
                'responsibilities',
                self::ROLE_PERMISSION_DEFAULTS
            ),
            'system_configuration' => $this->getStoredGroup(
                'system_configuration',
                'document_lifecycle',
                self::SYSTEM_CONFIGURATION_DEFAULTS
            ),
        ], 'Settings retrieved successfully.');
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSystemSettings($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can update system settings.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'email_notifications' => ['sometimes', 'array'],
            'email_notifications.documentUploaded' => ['sometimes', 'boolean'],
            'email_notifications.documentArchived' => ['sometimes', 'boolean'],
            'email_notifications.documentRestored' => ['sometimes', 'boolean'],
            'email_notifications.reviewAssigned' => ['sometimes', 'boolean'],
            'email_notifications.scanFailed' => ['sometimes', 'boolean'],
            'email_notifications.sandboxUnsafe' => ['sometimes', 'boolean'],
            'email_notifications.weeklySummary' => ['sometimes', 'boolean'],

            'system_notifications' => ['sometimes', 'array'],
            'system_notifications.inAppAlerts' => ['sometimes', 'boolean'],
            'system_notifications.securityAlerts' => ['sometimes', 'boolean'],
            'system_notifications.projectUpdates' => ['sometimes', 'boolean'],
            'system_notifications.sampleUpdates' => ['sometimes', 'boolean'],
            'system_notifications.laboratoryResults' => ['sometimes', 'boolean'],
            'system_notifications.archiveActivity' => ['sometimes', 'boolean'],
            'system_notifications.systemMaintenance' => ['sometimes', 'boolean'],

            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.emailDigest' => [
                'sometimes',
                Rule::in(['instant', 'daily', 'weekly']),
            ],
            'notification_preferences.quietHoursEnabled' => ['sometimes', 'boolean'],
            'notification_preferences.quietHoursStart' => ['sometimes', 'date_format:H:i'],
            'notification_preferences.quietHoursEnd' => ['sometimes', 'date_format:H:i'],
            'notification_preferences.notifyOnOwnActions' => ['sometimes', 'boolean'],

            'email_server' => ['sometimes', 'array'],
            'email_server.mailerName' => ['sometimes', 'string', 'max:255'],
            'email_server.fromName' => ['sometimes', 'string', 'max:255'],
            'email_server.fromEmail' => ['sometimes', 'email', 'max:255'],
            'email_server.replyToEmail' => ['sometimes', 'email', 'max:255'],
            'email_server.smtpHost' => ['sometimes', 'string', 'max:255'],
            'email_server.smtpPort' => ['sometimes', 'string', 'max:10'],
            'email_server.smtpUsername' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_server.smtpPassword' => ['sometimes', 'nullable', 'string', 'max:255'],
            'email_server.encryption' => ['sometimes', Rule::in(['tls', 'ssl', 'none'])],
            'email_server.adminEmail' => ['sometimes', 'email', 'max:255'],

            'role_permissions' => ['sometimes', 'array'],
            'role_permissions.adminCanManageUsers' => ['sometimes', 'boolean'],
            'role_permissions.geologistCanUpload' => ['sometimes', 'boolean'],
            'role_permissions.geologistCanViewReports' => ['sometimes', 'boolean'],
            'role_permissions.viewerCanDownload' => ['sometimes', 'boolean'],
            'role_permissions.viewerCanViewArchive' => ['sometimes', 'boolean'],

            'system_configuration' => ['sometimes', 'array'],
            'system_configuration.defaultDocumentStatus' => [
                'sometimes',
                Rule::in(['quarantined', 'active', 'pending_scan']),
            ],
            'system_configuration.archiveRetentionDays' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'system_configuration.maxUploadSizeMb' => ['sometimes', 'integer', 'min:1', 'max:2048'],
            'system_configuration.requireScanBeforeAccess' => ['sometimes', 'boolean'],
            'system_configuration.requireSandboxBeforeApproval' => ['sometimes', 'boolean'],
            'system_configuration.enableAiAnalysis' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $this->saveGroupIfPresent(
            $request,
            'email_notifications',
            'rules',
            self::EMAIL_NOTIFICATIONS_DEFAULTS,
            $user->id,
            'Email Notification Rules'
        );

        $this->saveGroupIfPresent(
            $request,
            'system_notifications',
            'rules',
            self::SYSTEM_NOTIFICATIONS_DEFAULTS,
            $user->id,
            'System Notification Rules'
        );

        $this->saveGroupIfPresent(
            $request,
            'notification_preferences',
            'defaults',
            self::NOTIFICATION_PREFERENCES_DEFAULTS,
            $user->id,
            'Notification Preferences'
        );

        if ($request->has('email_server')) {
            $currentEmailServer = $this->getStoredGroup(
                'email_server',
                'smtp',
                self::EMAIL_SERVER_DEFAULTS
            );

            $incomingEmailServer = $request->input('email_server', []);

            if (empty($incomingEmailServer['smtpPassword'])) {
                unset($incomingEmailServer['smtpPassword']);
            }

            SystemSetting::putSetting(
                'email_server',
                'smtp',
                array_merge($currentEmailServer, $incomingEmailServer),
                $user->id,
                'SMTP Email Server'
            );
        }

        $this->saveGroupIfPresent(
            $request,
            'role_permissions',
            'responsibilities',
            self::ROLE_PERMISSION_DEFAULTS,
            $user->id,
            'Role and Permission Responsibilities'
        );

        $this->saveGroupIfPresent(
            $request,
            'system_configuration',
            'document_lifecycle',
            self::SYSTEM_CONFIGURATION_DEFAULTS,
            $user->id,
            'Document Lifecycle Configuration'
        );

        return $this->sendResponse($this->index($request)->getData(true)['data'], 'Settings updated successfully.');
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'fullName' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
            'phone' => ['nullable', 'string', 'max:30'],
            'department' => ['nullable', 'string', 'max:255'],
            'jobTitle' => ['nullable', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $updates = [
            'name' => $request->fullName,
            'email' => $request->email,
        ];

        if (Schema::hasColumn('users', 'phone')) {
            $updates['phone'] = $request->phone;
        }

        if (Schema::hasColumn('users', 'department')) {
            $updates['department'] = $request->department;
        }

        if (Schema::hasColumn('users', 'job_title')) {
            $updates['job_title'] = $request->jobTitle;
        }

        /** @var User $user */
        $user->update($updates);
        $user->refresh()->load('role');

        return $this->sendResponse([
            'profile' => $this->profilePayload($user),
        ], 'Profile updated successfully.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:8'],
            'confirmPassword' => ['required', 'same:newPassword'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        if (!Hash::check($request->currentPassword, $user->password)) {
            return $this->sendError('Password Change Failed.', [
                'currentPassword' => ['Current password is incorrect.'],
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->newPassword),
        ]);

        return $this->sendResponse([], 'Password changed successfully.');
    }

    public function testEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return $this->sendError('Unauthenticated.', [
                'error' => 'You must login first.',
            ], 401);
        }

        if (!$this->canManageSystemSettings($user)) {
            return $this->sendError('Permission Denied.', [
                'error' => 'Only Admin can test system email settings.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation Error.', $validator->errors(), 422);
        }

        $emailServer = $this->emailServerPayload();
        $recipient = $request->input('email') ?: $emailServer['adminEmail'];

        try {
            Mail::raw(
                "This is a test email from MIGECO Document Management System.\n\nIf you received this email, SMTP delivery is working.",
                function ($message) use ($recipient, $emailServer) {
                    $message->to($recipient)
                        ->subject('MIGECO DMS Test Email');

                    if (!empty($emailServer['fromEmail'])) {
                        $message->from(
                            $emailServer['fromEmail'],
                            $emailServer['fromName'] ?? 'MIGECO DMS'
                        );
                    }
                }
            );
        } catch (\Throwable $exception) {
            return $this->sendError('Email Test Failed.', [
                'error' => $exception->getMessage(),
            ], 500);
        }

        return $this->sendResponse([
            'sent_to' => $recipient,
        ], 'Test email sent successfully.');
    }

    private function getStoredGroup(string $group, string $key, array $defaults): array
    {
        $stored = SystemSetting::getSetting($group, $key, []);

        if (!is_array($stored)) {
            $stored = [];
        }

        return array_merge($defaults, $stored);
    }

    private function saveGroupIfPresent(
        Request $request,
        string $requestKey,
        string $settingKey,
        array $defaults,
        int $userId,
        string $label
    ): void {
        if (!$request->has($requestKey)) {
            return;
        }

        SystemSetting::putSetting(
            $requestKey,
            $settingKey,
            array_merge($defaults, $request->input($requestKey, [])),
            $userId,
            $label
        );
    }

    private function emailServerPayload(): array
    {
        $emailServer = $this->getStoredGroup(
            'email_server',
            'smtp',
            self::EMAIL_SERVER_DEFAULTS
        );

        $emailServer['smtpPassword'] = '';

        return $emailServer;
    }

    private function profilePayload(User $user): array
    {
        return [
            'id' => $user->id,
            'fullName' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone ?? '',
            'department' => $user->department ?? '',
            'jobTitle' => $user->job_title ?? '',
            'role' => [
                'id' => $user->role?->id,
                'name' => $user->role?->name,
                'slug' => $user->role?->slug,
            ],
        ];
    }
}