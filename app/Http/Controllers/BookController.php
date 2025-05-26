<?php

namespace App\Http\Controllers;

use App\Models\BookAvailability;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use App\Models\BookPicture;
use App\Models\Book;
use App\Models\User;
use Illuminate\Support\Facades\Log;



class BookController extends Controller
{
    /**
     * Store a newly created book in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */

    public function listBook(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'condition' => 'required|integer|min:0|max:100',
            'description' => 'required|string',
            'availability' => 'sometimes|integer|in:0,1,2',
            'genres' => 'required|array',
            'genres.*' => 'exists:genres,id',
            'pictures' => 'nullable|array|max:6',
            'pictures.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',
            'delete_pictures' => 'sometimes|array',
            'delete_pictures.*' => 'integer|exists:book_pictures,id',
        ]);
        if ($validator->fails()) {
            Log::error('Validation failed', ['errors' => $validator->errors()]);
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }
        try {
            DB::beginTransaction();
            $book = Book::create([
                'user_id' => auth()->id(),
                'title' => $request->input('title'),
                'author' => $request->input('author'),
                'condition' => $request->input('condition'),
                'description' => $request->input('description'),
            ]);

            BookAvailability::create([
                'book_id' => $book->id,
                'availability_id' => 1,
            ]);

            $book->genres()->attach($request->genres);

            if ($request->hasFile('pictures')) {
                foreach ($request->file('pictures') as $picture) {
                    // Generate a unique filename
                    $randomLength = random_int(30, 50);
                    $filename = Str::random($randomLength) . '.' . $picture->getClientOriginalExtension();

                    // Store the file
                    $path = $picture->storeAs('books', $filename, 'public');

                    // Create picture record with correctly formatted path
                    BookPicture::create([
                        'book_id' => $book->id,
                        'picture' => 'storage/' . $path
                    ]);
                }
            }
            DB::commit();

            $book->load(['pictures', 'genres', 'availability']);

            return response()->json([
                'success' => true,
                'message' => 'Book created successfully',
                'data' => [
                    'book' => $book,
                    'availability_status' => $book->availability_status
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('An error occurred while creating the book', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating the book',
                'error' => $e->getMessage()
            ], 500);
        };
    }
    /**
     * Search for books based on various criteria.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function searchBooks(Request $request)
    {
        $query = Book::with(['genres', 'pictures', 'availability', 'user']);
        $hasFilters = false;

        // Step 1: Handle general search input (search across multiple fields)
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('author', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%');
            });
            $hasFilters = true;
        }

        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
            $hasFilters = true;
        }

        if ($request->filled('author')) {
            $query->where('author', 'like', '%' . $request->author . '%');
            $hasFilters = true;
        }

        if ($request->filled('genre_ids')) {
            $genreIds = is_array($request->genre_ids)
                ? $request->genre_ids
                : explode(',', $request->genre_ids); // Allow comma-separated input

            $query->whereHas('genres', function ($q) use ($genreIds) {
                $q->whereIn('genres.id', $genreIds);
            });
            $hasFilters = true;
        }

        // Step 3: Apply user filtering if sub is provided (last step)
        if ($request->filled('sub')) {
            $user = User::where('sub', $request->sub)->first();
            if ($user) {
                $query->where('user_id', $user->id);
                $hasFilters = true;
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found for the provided sub',
                ], 404);
            }
        }

        // Pagination
        $perPage = $request->input('per_page', 20);
        $paginator = $query->paginate($perPage);

        // Only return an error if filters were applied and no results found
        if ($hasFilters && $paginator->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No books found matching your search criteria',
                'data' => [
                    'pagination' => [
                        'total' => 0,
                        'per_page' => $perPage,
                        'current_page' => 1,
                        'last_page' => 1
                    ],
                    'books' => [],
                ]
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Search completed successfully',
            'data' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ],
                'books' => $paginator->items(),
            ]
        ]);
    }

    public function viewAllBooks(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $genres = $request->input('genres', []);
        if (!is_numeric($perPage) || $perPage <= 0) {
            $perPage = 20;
        }
        $query = Book::with(['genres', 'pictures', 'availability'])->orderBy('created_at', 'desc');
        if (!empty($genres)) {
            $query->whereHas('genres', function ($query) use ($genres) {
                $query->whereIn("genres.id", $genres);
            });
        }
        $paginator = $query->paginate($perPage);
        $books = $paginator->items();
        return response()->json([
            'success' => true,
            'message' => 'Books retrieved successfully',
            'data' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ],
                'books' => $books,
            ]
        ]);
    }

    public function newlyAddedBooks(Request $request)
    {
        $limit = $request->input('limit', 20);

        if (!is_numeric($limit) || $limit <= 0) {
            $limit = 20;
        }

        $books = Book::with(['genres', 'pictures', 'availability'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'New books retrieved successfully',
            'data' => $books
        ]);
    }


    public function viewBook($bookId)
    {

        $book = Book::find($bookId);
        if (!$book) {
            return response()->json([
                'success' => false,
                'message' => 'Book not found'
            ], 404);
        }
        $book->load(['pictures', 'genres', 'availability', 'user']);
        return response()->json([
            'success' => true,
            'message' => 'Book retrieved successfully',
            'data' => $book
        ]);
    }

    public function getUserBooks($sub)
    {

        if (!$sub) {
            return response()->json([
                'success' => false,
                'message' => 'Sub parameter is required'
            ], 400);
        }

        $user = User::where('sub', $sub)->first();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'User not found for the provided sub'
            ], 404);
        }

        // Get books using the found user ID
        $books = Book::where('user_id', $user->id)
            ->with(['pictures', 'genres', 'availability'])
            ->get();

        if ($books->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No books found for this user'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Books retrieved successfully',
            'data' => $books
        ]);
    }

    public function deleteBook(Request $request, $id)
    {
        $book = Book::find($id);

        if (!$book) {
            return response()->json([
                'success' => false,
                'message' => 'Book not found'
            ], 404);
        }


        if ($book->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You do not own this book'
            ], 403);
        }

        try {
            DB::beginTransaction();
            foreach ($book->pictures as $picture) {
                if (Storage::disk('public')->exists($picture->picture)) {
                    Storage::disk('public')->delete($picture->picture);
                }
                $picture->delete();
            }
            $book->genres()->detach();
            if ($book->availability) {
                $book->availability->delete();
            }
            $book->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Book deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while deleting the book',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function editBook(Request $request, $id)
    {
        // Validate request
        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'author' => 'sometimes|nullable|string|max:255',
            'condition' => 'sometimes|required|integer|min:0|max:100',
            'description' => 'sometimes|required|string',
            'availability' => 'sometimes|required|integer|in:0,1,2',
            'genres' => 'sometimes|array',
            'genres.*' => 'exists:genres,id',

            'delete_pictures' => 'sometimes|array',
            'delete_pictures.*' => 'integer|exists:book_pictures,id',

            'new_pictures' => 'sometimes|array',
            'new_pictures.*' => 'image|mimes:jpeg,png,jpg,gif|max:2048',

        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }


        // Find book
        $book = Book::find($id);

        if (!$book) {
            return response()->json([
                'success' => false,
                'message' => 'Book not found'
            ], 404);
        }


        // Check ownership
        if ($book->user_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. You do not own this book'
            ], 403);
        }

        try {
            DB::beginTransaction();

            // Update basic book info - form data compatible approach
            if ($request->filled('title')) {
                $book->title = $request->input('title');
            }

            if ($request->filled('author')) {
                $book->author = $request->input('author');
            }

            if ($request->filled('condition')) {
                $book->condition = $request->input('condition');
            }

            if ($request->filled('description')) {
                $book->description = $request->input('description');
            }

            // Update genres if provided - handle form array format
            if ($request->has('genres')) {
                $genreIds = is_array($request->input('genres'))
                    ? $request->input('genres')
                    : [$request->input('genres')];

                $book->genres()->sync($genreIds);
            }

            // Update availability if provided
            if ($request->filled('availability')) {
                $availabilityValue = (int)$request->input('availability');

                if ($book->availability) {
                    $book->availability->availability = $availabilityValue;
                    $book->availability->save();
                } else {
                    BookAvailability::create([
                        'book_id' => $book->id,
                        'availability' => $availabilityValue
                    ]);
                }
            }

            if ($request->has('delete_pictures')) {
                $picturesToDelete = $request->input('delete_pictures');

                foreach ($picturesToDelete as $pictureId) {
                    $picture = BookPicture::where('id', $pictureId)
                        ->where('book_id', $book->id)
                        ->first();

                    if ($picture) {
                        // Delete the file
                        if (Storage::disk('public')->exists($picture->picture)) {
                            Storage::disk('public')->delete($picture->picture);
                        }

                        // Delete the record
                        $picture->delete();
                    }
                }
            }

            if ($request->hasFile('new_pictures')) {
                $files = $request->file('new_pictures');
                if (!is_array($files)) {
                    $files = [$files];
                }
                $currentImageCount = BookPicture::where('book_id', $book->id)->count();
                // $currentImageCount = $book->pictures()->count();
                $newPicturesCount = count($files); // ✅ we already have $files array here
                if (($currentImageCount + $newPicturesCount) > 5) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You cannot upload more than 5 images in total.',
                    ], 422);
                }
                foreach ($files as $picture) {
                    $filename = Str::random(40) . '.' . $picture->getClientOriginalExtension();
                    $path = $picture->storeAs('books', $filename, 'public');

                    BookPicture::create([
                        'book_id' => $book->id,
                        'picture' => 'storage/' . $path
                    ]);
                }
            }


            $book->save();

            DB::commit();

            // Reload book with relationships to return updated data
            $book->load(['pictures', 'genres', 'availability']);

            return response()->json([
                'success' => true,
                'message' => 'Book updated successfully',
                'data' => [
                    'book' => $book,
                    'availability_status' => $book->availability_status
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating the book',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
