<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * This seeder keeps only the required system roles in code:
     * - Admin
     * - Geologist
     * - Viewer
     *
     * Important:
     * This file seeds/updates these three roles only.
     * It does not delete old roles automatically because old users may already
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
                    'view_geological_records',

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
                'name' => 'Geologist',
                'slug' => 'geologist',
                'description' => 'Can work with geological projects, study areas, samples, maps, geological records, and technical documents. Can upload, view, download, and update geological documents but cannot manage users, roles, or system security.',
                'permissions' => [
                    'view_projects',
                    'create_projects',
                    'edit_projects',

                    'view_study_areas',
                    'create_study_areas',
                    'edit_study_areas',

                    'view_samples',
                    'create_samples',
                    'edit_samples',

                    'view_geological_records',
                    'manage_geological_records',

                    'upload_documents',
                    'view_documents',
                    'download_documents',
                    'edit_documents',
                    'categorize_documents',
                    'manage_document_metadata',

                    'use_ai',
                    'view_reports',
                ],
            ],
            [
                'name' => 'Viewer',
                'slug' => 'viewer',
                'description' => 'Read-only user. Can view projects, study areas, samples, documents, reports, and geological records but cannot create, edit, delete, approve, or manage system settings.',
                'permissions' => [
                    'view_projects',
                    'view_study_areas',
                    'view_samples',
                    'view_geological_records',

                    'view_documents',
                    'download_documents',

                    'view_reports',
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