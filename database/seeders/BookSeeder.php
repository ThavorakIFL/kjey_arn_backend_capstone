<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookAvailability;
use App\Models\BookPicture;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class BookSeeder extends Seeder
{
    public function run()
    {
        $faker = Faker::create();

        // Assuming that you have a 'User' model and 'Genre' model that you can fetch
        $users = User::all();  // Get all users from the 'users' table
        $genres = Genre::all();  // Get all genres from the 'genres' table

        foreach (range(1, 50) as $index) {  // Adjust the range for the number of books you want
            $user = $users->random();  // Randomly pick a user
            $bookGenres = $genres->random(rand(1, 3));  // Randomly pick 1-3 genres for this book

            // Create a new book
            $book = Book::create([
                'user_id' => $user->id,  // Assign the user_id
                'title' => $faker->sentence,  // Generate a random title
                'author' => $faker->name,  // Generate a random author name
                'condition' => rand(0, 100),  // Generate a random condition (0-100)
                'description' => $faker->paragraph,  // Generate a random description
            ]);

            // Create the availability record for the book
            BookAvailability::create([
                'book_id' => $book->id,
                'availability' => 2,  // Assuming '2' means available
            ]);

            // Attach genres to the book
            $book->genres()->attach($bookGenres->pluck('id'));

            // Optionally, add pictures (simulated as random image file names)
            if (rand(0, 1)) {  // 50% chance to add pictures
                $pictureCount = rand(1, 3);  // Random number of pictures (1-3)

                foreach (range(1, $pictureCount) as $picIndex) {
                    $filename = Str::random(40) . '.' . 'jpg';  // Generate a random filename
                    $path = 'books/' . $filename;  // You can change this to use storage paths

                    // Create the book picture record
                    BookPicture::create([
                        'book_id' => $book->id,
                        'picture' => $path,
                    ]);
                }
            }
        }
    }
}
