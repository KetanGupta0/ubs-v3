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
    /** Every key, for the preflight check that the seeder has run after a deploy. @return array<int, string> */
    public static function keys(): array
    {
        return collect(self::permissions())->flatMap(fn (array $entries) => array_keys($entries))->values()->all();
    }

    public function run(): void
    {
        $permissions = self::permissions();

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

    /** @return array<string, array<string, string>> */
    protected static function permissions(): array
    {
        return [
            'Clients' => [
                'clients.view' => 'View clients',
                'clients.create' => 'Create client accounts',
                'clients.update' => 'Edit clients',
                'clients.delete' => 'Delete client accounts that hold no records (made by a bot or by mistake)',
            ],
            'Students' => [
                'students.view' => 'View students',
                'students.create' => 'Create student accounts',
                'students.update' => 'Edit students',
                'students.delete' => 'Delete student accounts that hold no records (made by a bot or by mistake)',
                'lms.doubts' => 'Answer students\' questions on lessons',
                'quiz.proctor' => 'Watch proctored quizzes live: message a student, give more time, end an attempt',
            ],
            'Support access' => [
                'people.view_as' => 'See the platform as a student or client, read only',
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
                'agreements.countersign' => 'Countersign agreements for the company',
                'documents.manage' => 'Upload and manage client documents',
                'support.manage' => 'Answer support tickets',
                'api.manage' => 'Issue and revoke API keys',
            ],
            'Chat' => [
                'chat.reply' => 'Read and reply in client and batch conversations',
            ],
            'Public site' => [
                'content.manage' => 'Write and publish pages on the public site',
                'legal.manage' => 'Edit and publish the privacy policy, terms and refund policy',
                'assistant.manage' => 'Run the website assistant: switch it on, its limits, and read its conversations',
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
            'Mail' => [
                'mail.broadcast' => 'Write and send announcements by email, and keep the do-not-mail list',
                'mail.occasions' => 'Set the greeting days and their greetings',
            ],
            'Shop' => [
                'orders.view' => 'View shop orders and licences',
                'orders.manage' => 'Cancel orders, and withdraw, restore or extend licences',
                'coupons.manage' => 'Create and change discount codes',
            ],
            'Settings' => [
                'settings.view' => 'View settings',
                'settings.manage' => 'Change settings',
                'staff.manage' => 'Manage staff accounts and permissions',
                'audit.view' => 'Read the audit log',
            ],
        ];
    }
}
