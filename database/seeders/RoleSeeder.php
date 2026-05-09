<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full system administrator. Can manage users, roles, documents, security, AI settings, antivirus settings, sandbox, reports, and system configuration.',
                'permissions' => [
                    'manage_users',
                    'manage_roles',
                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'delete_documents',
                    'approve_documents',
                    'scan_documents',
                    'view_scan_results',
                    'manage_quarantine',
                    'manage_sandbox',
                    'manage_cryptography',
                    'use_ai',
                    'manage_ai',
                    'view_reports',
                    'view_audit_logs',
                    'manage_system_settings',
                ],
            ],
            [
                'name' => 'Geologist',
                'slug' => 'geologist',
                'description' => 'Can upload, view, classify, and review geological reports, maps, field records, and site documents.',
                'permissions' => [
                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_own_documents',
                    'request_document_approval',
                    'use_ai',
                    'view_own_activity',
                ],
            ],
            [
                'name' => 'Engineer',
                'slug' => 'engineer',
                'description' => 'Can manage technical drawings, construction documents, engineering reports, and project files.',
                'permissions' => [
                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_own_documents',
                    'request_document_approval',
                    'use_ai',
                    'view_own_activity',
                ],
            ],
            [
                'name' => 'Project Manager',
                'slug' => 'project_manager',
                'description' => 'Can supervise project documents, approve workflows, monitor document progress, and view project reports.',
                'permissions' => [
                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'approve_documents',
                    'assign_document_review',
                    'use_ai',
                    'view_reports',
                    'view_project_audit_logs',
                ],
            ],
            [
                'name' => 'Auditor',
                'slug' => 'auditor',
                'description' => 'Can view documents, audit logs, compliance reports, document history, and security activities.',
                'permissions' => [
                    'view_documents',
                    'download_documents',
                    'view_reports',
                    'view_audit_logs',
                    'view_scan_results',
                    'view_document_history',
                ],
            ],
            [
                'name' => 'Document Controller',
                'slug' => 'document_controller',
                'description' => 'Responsible for document organization, metadata, categorization, version control, and document workflow support.',
                'permissions' => [
                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'categorize_documents',
                    'manage_document_metadata',
                    'manage_versions',
                    'request_document_approval',
                    'use_ai',
                ],
            ],
            [
                'name' => 'Security Officer',
                'slug' => 'security_officer',
                'description' => 'Responsible for antivirus scanning, sandbox checking, quarantine review, encryption monitoring, and suspicious activity review.',
                'permissions' => [
                    'view_documents',
                    'scan_documents',
                    'view_scan_results',
                    'manage_quarantine',
                    'manage_sandbox',
                    'manage_cryptography',
                    'view_audit_logs',
                    'view_security_reports',
                ],
            ],
            [
                'name' => 'Viewer',
                'slug' => 'viewer',
                'description' => 'Read-only user. Can only view allowed documents.',
                'permissions' => [
                    'view_documents',
                ],
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['slug' => $role['slug']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'permissions' => $role['permissions'],
                ]
            );
        }
    }
}