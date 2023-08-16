<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Database\Factories\PostFactory;
use Illuminate\Support\Facades\File;
use App\{Models\Category, Models\Post, Models\User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::create([
            'name' => env('ADMIN_USER_NAME', 'admin'),
            'email' => env('ADMIN_USER_EMAIL', 'admin@example.com'),
            'password' => Hash::make(env('ADMIN_USER_PASSWORD', 'password')),
        ]);

        if (!app()->isProduction()) {
            $path = public_path(PostFactory::FOLDER);

            if (!File::exists($path)) {
                File::makeDirectory($path);
            }

            File::cleanDirectory($path);

            $categories = Category::factory(3)->create();

            Post::factory(50)->create()->each(function ($post) use ($categories) {
                $post->categories()->attach($categories->random());
            });
        }
    }
}
