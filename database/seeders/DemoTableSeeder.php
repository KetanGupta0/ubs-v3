<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Rows for the design system gallery table.
 *
 * Development only. It exists so the table on /design has something real to
 * search, sort, filter and export against.
 */
class DemoTableSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Demo seeder skipped in production.');

            return;
        }

        $names = [
            'Aarti Sharma', 'Rahul Verma', 'Priya Nair', 'Imran Qureshi', 'Sneha Iyer',
            'Vikram Desai', 'Fatima Sheikh', 'Arjun Menon', 'Kavya Reddy', 'Nikhil Joshi',
            'Meera Pillai', 'Rohan Gupta', 'Ananya Bose', 'Sameer Khan', 'Divya Rao',
            'Karan Malhotra', 'Ishita Chatterjee', 'Aditya Kulkarni', 'Neha Bhatt', 'Yash Agarwal',
            'Pooja Saxena', 'Manish Tiwari', 'Ritu Chauhan', 'Deepak Naik', 'Shreya Ghosh',
            'Harsh Vardhan', 'Tanvi Kapoor', 'Gaurav Sinha', 'Lakshmi Krishnan', 'Zoya Ansari',
        ];

        foreach ($names as $index => $name) {
            $email = str($name)->lower()->replace(' ', '.')->append('@example.com')->toString();

            User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => bcrypt('password'),
                    // Roughly two thirds verified, so the filter has both sides to show.
                    'email_verified_at' => $index % 3 === 0 ? null : now()->subDays($index * 3),
                    'created_at' => now()->subDays(180 - ($index * 5)),
                    'updated_at' => now()->subDays(180 - ($index * 5)),
                ],
            );
        }

        $this->command?->info('Seeded '.count($names).' demo people.');
    }
}
