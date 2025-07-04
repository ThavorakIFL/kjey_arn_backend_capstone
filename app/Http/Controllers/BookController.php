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
use Illuminate\Support\Facades\Cache;


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
        // Enhanced validation with custom error messages
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'author' => 'nullable|string|max:255',
            'condition' => 'required|integer|min:0|max:100',
            'description' => 'required|string|max:1000',
            'availability' => 'sometimes|integer|in:0,1,2',
            'genres' => 'required|array|min:1',
            'genres.*' => 'exists:genres,id',
            'pictures' => 'nullable|array|max:5',
            'pictures.*' => 'image|mimes:jpeg,png,jpg,gif',
        ], [
            // Custom error messages
            'title.required' => 'Book title is required',
            'title.max' => 'Book title cannot exceed 255 characters',
            'description.required' => 'Book description is required',
            'description.max' => 'Description cannot exceed 1000 characters',
            'condition.required' => 'Book condition is required',
            'condition.min' => 'Condition must be at least 0',
            'condition.max' => 'Condition cannot exceed 100',
            'genres.required' => 'At least one genre must be selected',
            'genres.min' => 'At least one genre must be selected',
            'genres.*.exists' => 'Selected genre is invalid',
            'pictures.max' => 'Maximum 5 images allowed',
            'pictures.*.image' => 'File must be an image',
            'pictures.*.mimes' => 'Image must be JPEG, PNG, JPG, or GIF format',
        ]);


        if ($validator->fails()) {
            $errors = $validator->errors();

            // Log detailed validation errors for debugging
            Log::error('Book listing validation failed', [
                'user_id' => auth()->id(),
                'errors' => $errors->toArray(),
                'request_data' => $request->except(['pictures']) // Don't log file data
            ]);

            // Transform validation errors for better frontend handling
            $transformedErrors = [];
            foreach ($errors->toArray() as $field => $messages) {
                // Handle picture validation errors specifically
                if (strpos($field, 'pictures.') === 0) {
                    $index = explode('.', $field)[1];
                    $transformedErrors['pictures'][] = "Image " . ($index + 1) . ": " . implode(', ', $messages);
                } else {
                    $transformedErrors[$field] = $messages;
                }
            }

            return response()->json([
                'success' => false,
                'message' => 'Please check the form for errors',
                'errors' => $transformedErrors,
            ], 422);
        }

        try {
            DB::beginTransaction();

            // Validate file uploads before processing
            if ($request->hasFile('pictures')) {
                $pictures = $request->file('pictures');
                if (!is_array($pictures)) {
                    $pictures = [$pictures];
                }

                // Additional file validation
                foreach ($pictures as $index => $picture) {
                    if (!$picture->isValid()) {
                        throw new \Exception("Image " . ($index + 1) . " failed to upload properly. Please try again.");
                    }

                    // Check file size again (in case client-side validation was bypassed)
                    if ($picture->getSize() > 2048 * 1024) { // 2MB
                        throw new \Exception("Image " . ($index + 1) . " is too large. Maximum size is 2MB.");
                    }

                    // Verify it's actually an image
                    $imageInfo = getimagesize($picture->getPathname());
                    if ($imageInfo === false) {
                        throw new \Exception("Image " . ($index + 1) . " is not a valid image file.");
                    }
                }
            }

            // Create the book
            $book = Book::create([
                'user_id' => auth()->id(),
                'title' => trim($request->input('title')),
                'author' => trim($request->input('author')),
                'condition' => $request->input('condition'),
                'description' => trim($request->input('description')),
                'status' => 1,
            ]);

            if (!$book) {
                throw new \Exception('Failed to create book record');
            }

            // Create book availability
            $availability = BookAvailability::create([
                'book_id' => $book->id,
                'availability_id' => 1, // Default to available
            ]);

            if (!$availability) {
                throw new \Exception('Failed to create book availability record');
            }

            Log::info('Created availability record', [
                'availability_id' => $availability->id,
                'book_id' => $availability->book_id,
                'availability_value' => $availability->availability_id
            ]);

            // Attach genres
            try {
                $book->genres()->attach($request->genres);
            } catch (\Exception $e) {
                throw new \Exception('Failed to attach genres: ' . $e->getMessage());
            }

            // Handle picture uploads with ordering
            if ($request->hasFile('pictures')) {
                $pictures = $request->file('pictures');
                if (!is_array($pictures)) {
                    $pictures = [$pictures];
                }

                foreach ($pictures as $index => $picture) {
                    try {
                        // Generate a unique filename
                        $randomLength = random_int(30, 50);
                        $extension = $picture->getClientOriginalExtension();
                        $filename = Str::random($randomLength) . '.' . $extension;

                        // Store the file
                        $path = $picture->storeAs('books', $filename, 'public');

                        if (!$path) {
                            throw new \Exception("Failed to store image " . ($index + 1));
                        }

                        // Create picture record with order based on array index
                        $pictureRecord = BookPicture::create([
                            'book_id' => $book->id,
                            'picture' => 'storage/' . $path,
                            'order' => $index + 1
                        ]);

                        if (!$pictureRecord) {
                            throw new \Exception("Failed to create database record for image " . ($index + 1));
                        }
                    } catch (\Exception $e) {
                        // Clean up any uploaded files if there's an error
                        if (isset($path) && Storage::disk('public')->exists($path)) {
                            Storage::disk('public')->delete($path);
                        }
                        throw new \Exception("Image upload failed for image " . ($index + 1) . ": " . $e->getMessage());
                    }
                }
            }

            DB::commit();

            // Load relationships for response
            $book->load(['pictures', 'genres', 'availability']);

            Log::info('Book created successfully', [
                'book_id' => $book->id,
                'user_id' => auth()->id(),
                'title' => $book->title
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Book listed successfully!',
                'data' => [
                    'book' => $book,
                    'availability_status' => $book->availability_status ?? 'Available'
                ]
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Book creation failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->except(['pictures'])
            ]);

            // Return user-friendly error message
            $errorMessage = $e->getMessage();

            // Don't expose sensitive information in production
            if (app()->environment('production') && !str_contains($errorMessage, 'Image')) {
                $errorMessage = 'An error occurred while creating your book listing. Please try again.';
            }

            return response()->json([
                'success' => false,
                'message' => $errorMessage,
                'errors' => [
                    'general' => [$errorMessage]
                ]
            ], 500);
        }
    }
    /**
     * Search for books based on various criteria.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */

    public function searchBooks(Request $request)
    {
        try {
            // Create cache key based on request parameters
            $cacheKey = 'books_search_' . md5(json_encode($request->all()));

            // Try to get from cache first (cache for 5 minutes)
            $cachedResult = Cache::remember($cacheKey, 300, function () use ($request) {
                return $this->performSearch($request);
            });

            return response()->json($cachedResult);
        } catch (\Exception $e) {
            Log::error('Error in searchBooks method', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while searching for books',
                'error' => app()->environment('production') ? 'Server error' : $e->getMessage()
            ], 500);
        }
    }

    private function performSearch(Request $request)
    {
        $query = Book::with(['genres', 'pictures', 'availability', 'user']);
        $hasFilters = false;

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('author', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%');
            });
            $hasFilters = true;
            Log::info('Search filter applied', ['term' => $searchTerm]);
        }

        if ($request->filled('title')) {
            $query->where('title', 'like', '%' . $request->title . '%');
            $hasFilters = true;
            Log::info('Title filter applied', ['title' => $request->title]);
        }

        if ($request->filled('author')) {
            $query->where('author', 'like', '%' . $request->author . '%');
            $hasFilters = true;
            Log::info('Author filter applied', ['author' => $request->author]);
        }

        if ($request->filled('genre_ids')) {
            $genreIds = is_array($request->genre_ids)
                ? $request->genre_ids
                : explode(',', $request->genre_ids);

            $query->whereHas('genres', function ($q) use ($genreIds) {
                $q->whereIn('genres.id', $genreIds);
            });
            $hasFilters = true;
            Log::info('Genre filter applied', ['genre_ids' => $genreIds]);
        }

        if ($request->filled('sub')) {
            $user = User::where('sub', $request->sub)->first();
            if ($user) {
                $query->where('user_id', $user->id);
                $hasFilters = true;
                Log::info('User filter applied', ['sub' => $request->sub, 'user_id' => $user->id]);
            } else {
                Log::warning('User not found for sub', ['sub' => $request->sub]);
                return [
                    'success' => false,
                    'message' => 'User not found for the provided sub',
                ];
            }
        }

        // Pagination logic
        $perPage = $request->get('per_page', 14);
        $page = $request->get('page', 1);

        // Validate pagination parameters
        $perPage = max(1, min(100, (int)$perPage));
        $page = max(1, (int)$page);

        Log::info('Search pagination parameters', [
            'page' => $page,
            'per_page' => $perPage,
            'has_filters' => $hasFilters
        ]);

        // Get paginated results
        $paginatedBooks = $query->paginate($perPage, ['*'], 'page', $page);

        Log::info('Search completed successfully', [
            'total_results' => $paginatedBooks->total(),
            'current_page' => $paginatedBooks->currentPage(),
            'result_count' => count($paginatedBooks->items())
        ]);

        return [
            'success' => true,
            'data' => [
                'books' => $paginatedBooks->items(),
                'pagination' => [
                    'current_page' => $paginatedBooks->currentPage(),
                    'last_page' => $paginatedBooks->lastPage(),
                    'per_page' => $paginatedBooks->perPage(),
                    'total' => $paginatedBooks->total(),
                    'from' => $paginatedBooks->firstItem(),
                    'to' => $paginatedBooks->lastItem(),
                    'has_more_pages' => $paginatedBooks->hasMorePages(),
                    'prev_page_url' => $paginatedBooks->previousPageUrl(),
                    'next_page_url' => $paginatedBooks->nextPageUrl(),
                ]
            ],
            'message' => 'Books retrieved successfully'
        ];
    }

    // public function searchBooks(Request $request)
    // {
    //     try {
    //         $query = Book::with(['genres', 'pictures', 'availability', 'user']);
    //         $hasFilters = false;

    //         if ($request->filled('search')) {
    //             $searchTerm = $request->search;
    //             $query->where(function ($q) use ($searchTerm) {
    //                 $q->where('title', 'like', '%' . $searchTerm . '%')
    //                     ->orWhere('author', 'like', '%' . $searchTerm . '%')
    //                     ->orWhere('description', 'like', '%' . $searchTerm . '%');
    //             });
    //             $hasFilters = true;
    //             Log::info('Search filter applied', ['term' => $searchTerm]);
    //         }

    //         if ($request->filled('title')) {
    //             $query->where('title', 'like', '%' . $request->title . '%');
    //             $hasFilters = true;
    //             Log::info('Title filter applied', ['title' => $request->title]);
    //         }

    //         if ($request->filled('author')) {
    //             $query->where('author', 'like', '%' . $request->author . '%');
    //             $hasFilters = true;
    //             Log::info('Author filter applied', ['author' => $request->author]);
    //         }

    //         if ($request->filled('genre_ids')) {
    //             $genreIds = is_array($request->genre_ids)
    //                 ? $request->genre_ids
    //                 : explode(',', $request->genre_ids);

    //             $query->whereHas('genres', function ($q) use ($genreIds) {
    //                 $q->whereIn('genres.id', $genreIds);
    //             });
    //             $hasFilters = true;
    //             Log::info('Genre filter applied', ['genre_ids' => $genreIds]);
    //         }

    //         if ($request->filled('sub')) {
    //             $user = User::where('sub', $request->sub)->first();
    //             if ($user) {
    //                 $query->where('user_id', $user->id);
    //                 $hasFilters = true;
    //                 Log::info('User filter applied', ['sub' => $request->sub, 'user_id' => $user->id]);
    //             } else {
    //                 Log::warning('User not found for sub', ['sub' => $request->sub]);
    //                 return response()->json([
    //                     'success' => false,
    //                     'message' => 'User not found for the provided sub',
    //                 ], 404);
    //             }
    //         }

    //         // Pagination logic
    //         $perPage = $request->get('per_page', 14); // Default 14 books per page
    //         $page = $request->get('page', 1);

    //         // Validate pagination parameters
    //         $perPage = max(1, min(100, (int)$perPage)); // Between 1 and 100
    //         $page = max(1, (int)$page);

    //         Log::info('Search pagination parameters', [
    //             'page' => $page,
    //             'per_page' => $perPage,
    //             'has_filters' => $hasFilters
    //         ]);

    //         // Get paginated results
    //         $paginatedBooks = $query->paginate($perPage, ['*'], 'page', $page);

    //         Log::info('Search completed successfully', [
    //             'total_results' => $paginatedBooks->total(),
    //             'current_page' => $paginatedBooks->currentPage(),
    //             'result_count' => count($paginatedBooks->items())
    //         ]);

    //         return response()->json([
    //             'success' => true,
    //             'data' => [
    //                 'books' => $paginatedBooks->items(),
    //                 'pagination' => [
    //                     'current_page' => $paginatedBooks->currentPage(),
    //                     'last_page' => $paginatedBooks->lastPage(),
    //                     'per_page' => $paginatedBooks->perPage(),
    //                     'total' => $paginatedBooks->total(),
    //                     'from' => $paginatedBooks->firstItem(),
    //                     'to' => $paginatedBooks->lastItem(),
    //                     'has_more_pages' => $paginatedBooks->hasMorePages(),
    //                     'prev_page_url' => $paginatedBooks->previousPageUrl(),
    //                     'next_page_url' => $paginatedBooks->nextPageUrl(),
    //                 ]
    //             ],
    //             'message' => 'Books retrieved successfully'
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Error in searchBooks method', [
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString(),
    //             'request_data' => $request->all()
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'An error occurred while searching for books',
    //             'error' => app()->environment('production') ? 'Server error' : $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function newlyAddedBooks(Request $request)
    {
        try {
            $limit = $request->input('limit', 14);
            if (!is_numeric($limit) || $limit <= 0) {
                $limit = 14;
            }

            $books = Book::with(['genres', 'pictures', 'availability'])
                ->whereHas('availability', function ($query) {
                    $query->where('availability_id', 1);
                })
                ->orderBy('created_at', 'desc')
                ->limit($limit)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'New books retrieved successfully',
                'data' => $books
            ]);
        } catch (\Exception $e) {
            Log::error('Error retrieving newly added books', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving newly added books',
                'error' => $e->getMessage()
            ], 500);
        }
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

    public function getUserBookshelf($sub)
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

            'image_order' => 'sometimes|string', // JSON string of image order
            'final_image_order' => 'sometimes|string', // JSON string of final unified order
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

            // Delete pictures if requested
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

            // Handle new pictures first
            $newPictureIds = [];
            if ($request->hasFile('new_pictures')) {
                $files = $request->file('new_pictures');
                if (!is_array($files)) {
                    $files = [$files];
                }

                $currentImageCount = BookPicture::where('book_id', $book->id)->count();
                $newPicturesCount = count($files);

                if (($currentImageCount + $newPicturesCount) > 5) {
                    return response()->json([
                        'success' => false,
                        'message' => 'You cannot upload more than 5 images in total.',
                    ], 422);
                }

                // Save new pictures with temporary order (we'll fix this after)
                foreach ($files as $index => $picture) {
                    $filename = Str::random(40) . '.' . $picture->getClientOriginalExtension();
                    $path = $picture->storeAs('books', $filename, 'public');

                    $newPicture = BookPicture::create([
                        'book_id' => $book->id,
                        'picture' => 'storage/' . $path,
                        'order' => 9999 + $index // Temporary high order
                    ]);

                    $newPictureIds[] = $newPicture->id;
                }
            }

            // Handle final image order if provided (this should include both existing and new images)
            if ($request->filled('final_image_order')) {
                $finalOrder = json_decode($request->input('final_image_order'), true);

                if (is_array($finalOrder)) {
                    foreach ($finalOrder as $orderData) {
                        if (isset($orderData['id']) && isset($orderData['order']) && isset($orderData['type'])) {
                            if ($orderData['type'] === 'existing') {
                                // Update existing image order
                                BookPicture::where('id', $orderData['id'])
                                    ->where('book_id', $book->id)
                                    ->update(['order' => $orderData['order']]);
                            } elseif ($orderData['type'] === 'new') {
                                // Update new image order using the index
                                $newPictureIndex = $orderData['id']; // This should be the index in the new pictures array
                                if (isset($newPictureIds[$newPictureIndex])) {
                                    BookPicture::where('id', $newPictureIds[$newPictureIndex])
                                        ->where('book_id', $book->id)
                                        ->update(['order' => $orderData['order']]);
                                }
                            }
                        }
                    }
                }
            } else {
                // Fallback: Handle image order updates for existing images only
                if ($request->filled('image_order')) {
                    $imageOrder = json_decode($request->input('image_order'), true);

                    if (is_array($imageOrder)) {
                        foreach ($imageOrder as $orderData) {
                            if (isset($orderData['id']) && isset($orderData['order'])) {
                                BookPicture::where('id', $orderData['id'])
                                    ->where('book_id', $book->id)
                                    ->update(['order' => $orderData['order']]);
                            }
                        }
                    }
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
