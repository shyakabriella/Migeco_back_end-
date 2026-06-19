<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder now keeps only the two required system roles:
     * - Admin
     * - Project Manager
     *
     * Important:
     * This file seeds/updates these two roles only.
     * It does not delete old roles automatically, because old users may already
     * be linked to them. Deleting linked roles can break existing users.
     */
    public function run(): void
    {
        $roles = [
            [
                'name' => 'Admin',
                'slug' => 'admin',
                'description' => 'Full system administrator. Can manage users, roles, projects, documents, categories, geological metadata, security, AI settings, antivirus scanning, sandbox validation, encryption, reports, audit logs, and system configuration.',
                'permissions' => [
                    'manage_users',
                    'manage_roles',

                    'manage_projects',
                    'create_projects',
                    'view_projects',
                    'edit_projects',
                    'delete_projects',

                    'manage_categories',
                    'manage_metadata_schemas',
                    'manage_geological_records',

                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'delete_documents',
                    'approve_documents',
                    'categorize_documents',
                    'manage_document_metadata',
                    'manage_versions',

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
                'name' => 'Project Manager',
                'slug' => 'project_manager',
                'description' => 'Can supervise projects, upload and manage project documents, manage geological records for projects, approve document workflows, monitor document progress, use AI support, and view project reports.',
                'permissions' => [
                    'manage_projects',
                    'create_projects',
                    'view_projects',
                    'edit_projects',

                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'approve_documents',
                    'assign_document_review',
                    'request_document_approval',

                    'manage_geological_records',
                    'view_geological_records',

                    'use_ai',
                    'view_reports',
                    'view_project_audit_logs',
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