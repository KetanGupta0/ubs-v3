<?php

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * The capability list staff accounts are granted from.
 *
 * The owner holds everything implicitly, so these matter for everybody else:
 * someone who should run the leads inbox without also seeing billing.
 *
 * Keys are added as each phase lands. Re-running this is safe.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'Clients' => [
                'clients.view' => 'View clients',
                'clients.create' => 'Create client accounts',
                'clients.update' => 'Edit clients',
            ],
            'Students' => [
                'students.view' => 'View students',
                'students.create' => 'Create student accounts',
                'students.update' => 'Edit students',
            ],
            'Leads' => [
                'leads.view' => 'View the leads inbox',
                'leads.assign' => 'Assign leads',
                'leads.convert' => 'Convert a lead to a client',
            ],
            'Delivery' => [
                'projects.view' => 'View projects',
                'projects.manage' => 'Create and edit projects',
                'proposals.manage' => 'Write and send proposals',
                'documents.manage' => 'Upload and manage client documents',
                'support.manage' => 'Answer support tickets',
                'api.manage' => 'Issue and revoke API keys',
            ],
            'Catalogue' => [
                'catalogue.view' => 'View the catalogue',
                'catalogue.manage' => 'Edit solutions, services and courses',
            ],
            'Billing' => [
                'billing.view' => 'View invoices and payments',
                'billing.manage' => 'Raise payment requests and issue invoices',
                'billing.refund' => 'Process refunds',
            ],
            'Settings' => [
                'settings.view' => 'View settings',
                'settings.manage' => 'Change settings',
                'staff.manage' => 'Manage staff accounts and permissions',
                'audit.view' => 'Read the audit log',
            ],
        ];

        foreach ($permissions as $group => $entries) {
            foreach ($entries as $key => $label) {
                Permission::query()->updateOrCreate(
                    ['key' => $key],
                    ['label' => $label, 'group' => $group],
                );
            }
        }

        $this->command?->info('Seeded '.collect($permissions)->flatten()->count().' permissions.');
    }
}
