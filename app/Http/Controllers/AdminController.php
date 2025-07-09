<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Book;
use App\Models\User;
use App\Models\Genre;
use App\Models\BorrowEvent;
use App\Models\BorrowStatus;
use App\Models\Location;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function dashboard()
    {
        $totalUsers = User::count();
        $totalBooks = Book::count();
        $totalBorrowEvents = BorrowEvent::count();
        return response()->json([
            'success' => true,
            'message' => 'Dashboard data retrieved successfully',
            'data' => [
                'total_users' => $totalUsers,
                'total_books' => $totalBooks,
                'total_borrow_events' => $totalBorrowEvents,
            ]
        ]);
    }

    public function dashboardBorrowEvents()
    {
        $borrowEventCounts = DB::table('borrow_events')
            ->join('borrow_event_borrow_status', 'borrow_events.id', '=', 'borrow_event_borrow_status.borrow_event_id')
            ->join('borrow_statuses', 'borrow_event_borrow_status.borrow_status_id', '=', 'borrow_statuses.id')
            ->whereIn('borrow_event_borrow_status.borrow_status_id', [1, 2, 3, 4, 5, 6, 7, 8])
            ->select('borrow_statuses.id', 'borrow_statuses.status', DB::raw('COUNT(*) as count'))
            ->groupBy('borrow_statuses.id', 'borrow_statuses.status')
            ->get();
        return response()->json([
            'success' => true,
            'message' => 'Borrow event counts retrieved successfully',
            'data' => $borrowEventCounts
        ]);
    }

    public function index(Request $request)
    {
        $query = User::withCount('books');
        $filterCounts = [
            'all' => User::count(),
            'active' => User::where('status', 1)->count(),
            'suspended' => User::where('status', 0)->count(),
        ];

        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('name', 'like', '%' . $searchTerm . '%')
                    ->orWhere('email', 'like', '%' . $searchTerm . '%');
            });
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 10);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved successfully',
            'data' => [
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ],
                'users' => $paginator->items(),
                'filter_counts' => $filterCounts,  // Add this line
            ]
        ]);
    }

    public function getTotalUsers()
    {
        $totalUsers = User::count();
        return response()->json([
            'success' => true,
            'message' => 'Total users retrieved successfully',
            'data' =>  $totalUsers
        ]);
    }

    public function fetchUserbyId($id)
    {
        $user = User::withCount('books')->findOrFail($id);
        return response()->json(
            [
                'success' => true,
                'message' => 'User retrieved successfully',
                'data' => $user,
            ]
        );
    }

    public function fetchUserBooksById($id)
    {
        $query = Book::where('user_id', $id)->with('genres', 'pictures', 'availability');

        // Add search and filters (similar to your main books endpoint)

        if (request()->filled('search')) {
            $searchTerm = request()->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('author', 'like', '%' . $searchTerm . '%');
            });
        }

        if (request()->filled('availability')) {
            $query->whereHas('availability', function ($q) {
                $q->where('availability_id', request()->availability);
            });
        }

        // Add genre filter
        if (request()->filled('genre')) {
            $query->whereHas('genres', function ($q) {
                $q->where('genres.id', request()->genre);
            });
        }

        // Add status filter
        if (request()->filled('status')) {
            $query->where('status', request()->status);
        }

        $perPage = request()->input('per_page', 10);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'User books retrieved successfully',
            'data' => [
                'books' => $paginator->items(),
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage()
                ],
                'filter_counts' => [
                    'all' => Book::where('user_id', $id)->count(),
                    'available' => Book::where('user_id', $id)->whereHas('availability', fn($q) => $q->where('availability_id', 1))->count(),
                    'unavailable' => Book::where('user_id', $id)->whereHas('availability', fn($q) => $q->where('availability_id', 2))->count(),
                    'active' => Book::where('user_id', $id)->where('status', 1)->count(),
                    'suspended' => Book::where('user_id', $id)->where('status', 0)->count(),
                ]
            ]
        ]);
    }

    public function fetchUserBorrowEventsById($id)
    {
        $userBorrowEvents = BorrowEvent::where('borrower_id', $id)
            ->with(
                'borrower',
                'lender',
                'book.pictures',
                'book.genres',
                'borrowStatus.borrowStatus',
                'meetUpDetail',
                'returnDetail',
                'borrowEventRejectReason',
                'borrowEventCancelReason',
                'borrowEventReport'
            )->whereHas('borrowStatus', function ($query) {
                // Only get active borrowing activities (not completed/cancelled)
                $query->whereIn('borrow_status_id', [1, 2, 4, 7, 8]); // Pending, Accepted, In Progress, Ready for Return, Reported
            })
            ->latest() // Order by created_at in descending order
            ->take(3); // Limit to 3 records
        return response()->json(
            [
                'success' => true,
                'message' => 'User book retrieved successfully',
                'data' => $userBorrowEvents->get()->toArray(),
            ]
        );
    }


    public function updateUserStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|integer|in:0,1'
            ]);

            $user = User::findOrFail($id);
            $user->status = $request->status;
            $user->save();


            return response()->json([
                'success' => true,
                'message' => 'User status updated successfully',
                'data' => $user
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error when updating user status', [
                'user_id' => $id,
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('User not found when updating status', [
                'user_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'User not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating user status', [
                'user_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating user status'
            ], 500);
        }
    }

    // In AdminController.php
    public function indexBooks(Request $request)
    {
        $query = Book::with(['user', 'genres', 'pictures', 'availability']);
        $filterCounts = [
            'all' => Book::count(),
            'available' => Book::whereHas('availability', function ($q) {
                $q->where('availability_id', 1);
            })->count(),
            'unavailable' => Book::whereHas('availability', function ($q) {
                $q->where('availability_id', 2);
            })->count(),
            'active' => Book::where('status', 1)->count(),
            'suspended' => Book::where('status', 0)->count(),
        ];

        // Search functionality
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('title', 'like', '%' . $searchTerm . '%')
                    ->orWhere('author', 'like', '%' . $searchTerm . '%')
                    ->orWhere('description', 'like', '%' . $searchTerm . '%')
                    ->orWhereHas('user', function ($userQuery) use ($searchTerm) {
                        $userQuery->where('name', 'like', '%' . $searchTerm . '%');
                    });
            });
        }

        if ($request->filled('availability')) {
            $query->whereHas('availability', function ($q) use ($request) {
                $q->where('availability_id', $request->availability);
            });
        }

        if ($request->filled('genre')) {
            $query->whereHas('genres', function ($q) use ($request) {
                $q->where('genres.id', $request->genre);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $perPage = $request->input('per_page', 10);
        $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
                'books' => $paginator->items(),
                'filter_counts' => $filterCounts,
            ]
        ]);
    }

    public function getTotalBooks()
    {
        $totalBooks = Book::count();
        return response()->json([
            'success' => true,
            'message' => 'Total books retrieved successfully',
            'data' => $totalBooks
        ]);
    }

    public function getBookById($id)
    {
        $query = Book::with(['user', 'genres', 'pictures', 'availability'])->findOrFail($id);
        return response()->json(
            [
                'success' => true,
                'message' => 'Book retrieved successfully',
                'data' => $query,
            ]
        );
    }

    public function updateBookStatus(Request $request, $id)
    {
        try {
            $request->validate([
                'status' => 'required|integer|in:0,1'
            ]);

            $book = Book::findOrFail($id);
            $book->status = $request->status;

            // Update availability based on status
            if ($request->status == 1) {
                $book->availability()->update(['availability_id' => 1]);
            } else {
                $book->availability()->update(['availability_id' => 2]);
            }

            $book->save();

            return response()->json([
                'success' => true,
                'message' => 'Book status updated successfully',
                'data' => $book
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error when updating book status', [
                'book_id' => $id,
                'errors' => $e->errors(),
            ]);
            throw $e;
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            Log::error('Book not found when updating status', [
                'book_id' => $id
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Book not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating book status', [
                'book_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating book status'
            ], 500);
        }
    }


    public function getGenres()
    {
        $genres = Genre::orderBy('genre', 'asc')->get(['id', 'genre']);
        return response()->json([
            'success' => true,
            'message' => 'Genres retrieved successfully',
            'data' => $genres
        ]);
    }

    public function getPopularGenres()
    {
        $popularGenres = Genre::select('genres.id', 'genres.genre')
            ->join('book_genre', 'genres.id', '=', 'book_genre.genre_id')
            ->groupBy('genres.id', 'genres.genre')
            ->orderByRaw('COUNT(book_genre.book_id) DESC')
            ->limit(4)
            ->selectRaw('genres.id, genres.genre, COUNT(book_genre.book_id) as books_count')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Popular genres retrieved successfully',
            'data' => $popularGenres
        ]);
    }

    // In AdminController.php
    public function indexBorrowActivities(Request $request)
    {
        try {

            $query = BorrowEvent::with([
                'borrower',
                'lender',
                'book.pictures',
                'book.genres',
                'borrowStatus.borrowStatus',
                'meetUpDetail',
                'returnDetail',
                'borrowEventRejectReason',
                'borrowEventCancelReason',
                'borrowEventReport'
            ]);

            // Get counts for filters (based on borrow_status_id)
            $filterCounts = [
                'all' => BorrowEvent::count(),
                'pending' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 1);
                })->count(),
                'accepted' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 2);
                })->count(),
                'rejected' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 3);
                })->count(),
                'in_progress' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 4);
                })->count(),
                'completed' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 5);
                })->count(),
                'cancelled' => BorrowEvent::whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 6);
                })->count(),
            ];


            // Search functionality
            if ($request->filled('search')) {
                $searchTerm = $request->search;


                $query->where(function ($q) use ($searchTerm) {
                    $q->whereHas('book', function ($bookQuery) use ($searchTerm) {
                        $bookQuery->where('title', 'like', '%' . $searchTerm . '%')
                            ->orWhere('author', 'like', '%' . $searchTerm . '%');
                    })
                        ->orWhereHas('borrower', function ($borrowerQuery) use ($searchTerm) {
                            $borrowerQuery->where('name', 'like', '%' . $searchTerm . '%');
                        })
                        ->orWhereHas('lender', function ($lenderQuery) use ($searchTerm) {
                            $lenderQuery->where('name', 'like', '%' . $searchTerm . '%');
                        })
                        ->orWhereHas('borrowStatus.borrowStatus', function ($statusQuery) use ($searchTerm) {
                            $statusQuery->where('status', 'like', '%' . $searchTerm . '%');
                        });
                });
            }

            // Status filter
            if ($request->filled('status')) {


                $query->whereHas('borrowStatus', function ($q) use ($request) {
                    $q->where('borrow_status_id', $request->status);
                });
            }

            // Date filter
            if ($request->filled('date_filter')) {
                $dateFilter = $request->date_filter;

                $query->where(function ($q) use ($dateFilter) {
                    switch ($dateFilter) {
                        case 'today':
                            $q->whereDate('created_at', today());
                            break;
                        case 'this_week':
                            $q->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
                            break;
                        case 'this_month':
                            $q->whereMonth('created_at', now()->month)
                                ->whereYear('created_at', now()->year);
                            break;
                        case 'last_month':
                            $q->whereMonth('created_at', now()->subMonth()->month)
                                ->whereYear('created_at', now()->subMonth()->year);
                            break;
                        case 'this_year':
                            $q->whereYear('created_at', now()->year);
                            break;
                    }
                });
            }

            // Custom date range filter
            if ($request->filled('start_date') && $request->filled('end_date')) {

                $query->whereBetween('created_at', [
                    $request->start_date . ' 00:00:00',
                    $request->end_date . ' 23:59:59'
                ]);
            }

            // Log the final query for debugging

            // Pagination
            $perPage = $request->input('per_page', 10);
            $paginator = $query->orderBy('created_at', 'desc')->paginate($perPage);


            return response()->json([
                'success' => true,
                'message' => 'Borrow activities retrieved successfully',
                'data' => [
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage()
                    ],
                    'activities' => $paginator->items(),
                    'filter_counts' => $filterCounts,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error in indexBorrowActivities', [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve borrow activities',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getBorrowActivityById($activityId)
    {
        $borrowEvent = BorrowEvent::with([
            'borrower',
            'lender',
            'book.pictures',
            'book.genres',
            'borrowStatus.borrowStatus',
            'meetUpDetail',
            'returnDetail',
            'borrowEventRejectReason',
            'borrowEventCancelReason',
            'borrowEventReport',
            'borrowEventReport.reporter'
        ])
            ->findOrFail($activityId);
        return response()->json([
            'success' => true,
            'message' => 'Borrow activity retrieved successfully',
            'data' => $borrowEvent
        ]);
    }

    public function getBorrowActivityReport(Request $request)
    {
        try {
            $query = BorrowEvent::has('borrowEventReport')
                ->with([
                    'borrowEventReport',
                    'book.pictures',
                    'book:id,user_id,title,author,condition,description,status,created_at,updated_at',
                    'borrower:id,name,email,sub,picture,bio,status,created_at,updated_at',
                    'lender:id,name,email,sub,picture,bio,status,created_at,updated_at'
                ]);

            // Search functionality
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->whereHas('book', function ($bookQuery) use ($search) {
                        $bookQuery->where('title', 'LIKE', "%{$search}%")
                            ->orWhere('author', 'LIKE', "%{$search}%");
                    })
                        ->orWhereHas('borrower', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('lender', function ($userQuery) use ($search) {
                            $userQuery->where('name', 'LIKE', "%{$search}%");
                        })
                        ->orWhereHas('borrowEventReport', function ($reportQuery) use ($search) {
                            $reportQuery->where('reason', 'LIKE', "%{$search}%");
                        });
                });
            }

            // Status filter
            if ($request->filled('status')) {
                $query->whereHas('borrowEventReport', function ($reportQuery) use ($request) {
                    $reportQuery->where('status', $request->status);
                });
            }

            // Date range filter
            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }
            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            // Order by most recent reports first
            $query->latest('created_at');

            // Handle pagination vs regular get
            if ($request->filled('per_page')) {
                $borrowActivitiesReport = $query->paginate($request->per_page);

                return response()->json([
                    'success' => true,
                    'message' => 'Borrow Event Reports retrieved successfully',
                    'data' => $borrowActivitiesReport->items(), // Use items() method for paginated results
                    'meta' => [
                        'current_page' => $borrowActivitiesReport->currentPage(),
                        'last_page' => $borrowActivitiesReport->lastPage(),
                        'per_page' => $borrowActivitiesReport->perPage(),
                        'total' => $borrowActivitiesReport->total(),
                    ]
                ]);
            } else {
                $borrowActivitiesReport = $query->get();

                return response()->json([
                    'success' => true,
                    'message' => 'Borrow Event Reports retrieved successfully',
                    'data' => $borrowActivitiesReport, // Direct collection for non-paginated results
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching borrow activity reports: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve borrow event reports',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error'
            ], 500);
        }
    }

    public function updateBorrowActivityReportStatus($id)
    {
        try {
            $borrowEvent = BorrowEvent::has('borrowEventReport')->findOrFail($id);
            $borrowEvent->borrowEventReport->status = true;
            $borrowEvent->borrowEventReport->save();
            return response()->json([
                'success' => true,
                'message' => 'Borrow event report status updated successfully',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Borrow event report not found'
            ], 404);
        }
    }

    // Also add this method for borrow statuses
    public function getBorrowStatuses()
    {
        $statuses = BorrowStatus::orderBy('id', 'asc')->get(['id', 'status']);

        return response()->json([
            'success' => true,
            'message' => 'Borrow statuses retrieved successfully',
            'data' => $statuses
        ]);
    }

    public function getLocations(Request $request)
    {
        $query = Location::orderBy('location', 'asc');

        // Search functionality
        if ($request->filled('search')) {
            $query->where('location', 'like', '%' . $request->search . '%');
        }

        // Check if pagination is requested
        if ($request->filled('paginate') && $request->paginate === 'true') {
            $perPage = $request->input('per_page', 10);
            $paginator = $query->paginate($perPage);

            return response()->json([
                'success' => true,
                'message' => 'Locations retrieved successfully',
                'data' => [
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage()
                    ],
                    'locations' => $paginator->items(),
                ]
            ]);
        }
        $locations = $query->get(['id', 'location']);

        return response()->json([
            'success' => true,
            'message' => 'Locations retrieved successfully',
            'data' => $locations
        ]);
    }

    public function createLocation(Request $request)
    {
        $request->validate([
            'location' => 'required|string|max:255|unique:locations,location'
        ], [
            'location.required' => 'Location name is required',
            'location.unique' => 'This location already exists'
        ]);

        try {
            $location = Location::create([
                'location' => trim($request->location)
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Location created successfully',
                'data' => $location
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create location',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function updateLocation(Request $request, $id)
    {
        $request->validate([
            'location' => 'required|string|max:255|unique:locations,location,' . $id
        ]);

        try {
            $location = Location::findOrFail($id);
            $location->location = trim($request->location);
            $location->save();

            return response()->json([
                'success' => true,
                'message' => 'Location updated successfully',
                'data' => $location
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update location',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function deleteLocation($id)
    {
        try {
            $location = Location::findOrFail($id);

            // Optional: Check if location is being used in any meet_up_details
            $isInUse = DB::table('meet_up_details')
                ->where('final_location', $location->location)
                ->exists();

            if ($isInUse) {
                return response()->json([
                    'success' => false,
                    'message' => 'Cannot delete location as it is currently being used in active borrow activities'
                ], 400);
            }

            $location->delete();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Location not found'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete location',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function login(Request $request)
    {
        try {
            $credentials = $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ]);

            $admin = Admin::where('username', $credentials['username'])->first();

            if (!$admin || !Hash::check($credentials['password'], $admin->password)) {
                Log::warning('Failed login attempt', [
                    'username' => $credentials['username'],
                    'ip' => $request->ip()
                ]);
                return response()->json(['message' => 'Invalid credentials'], 401);
            }
            Log::info('Admin found: ' . $admin->id);
            // Delete old tokens (optional - for single session)
            $admin->tokens()->delete();
            $token = $admin->createToken('admin-token')->plainTextToken;
            Log::info('Token created: ' . substr($token, 0, 10) . '...');
            Log::info('Admin tokens count: ' . $admin->tokens()->count());

            Log::info('Admin logged in successfully', [
                'admin_id' => $admin->id,
                'username' => $admin->username,
                'ip' => $request->ip()
            ]);

            return response()->json([
                'admin' => $admin,
                'token' => $token
            ]);
        } catch (\Exception $e) {
            Log::error('Login error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return response()->json(['message' => 'An error occurred during login'], 500);
        }
    }

    public function getAllData()
    {
        $totalUsers = User::count();
        $totalBooks = Book::count();

        return response()->json([
            'total_users' => $totalUsers,
            'total_books' => $totalBooks,
        ]);
    }

    public function getAllUsers()
    {
        $users = User::select('id', 'name', 'email', 'picture')
            ->withCount('books')
            ->get();
        return response()->json($users);
    }

    public function getUserById($userId)
    {
        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }
        return response()->json($user);
    }


    public function getUserBooks($userId)
    {
        try {
            $user = User::where('id', $userId)->first();
            if (!$user) {
                return response()->json(['message' => 'User not found'], 404);
            }
            $books = Book::where('user_id', $user->id)
                ->with(['pictures', 'genres', 'availability'])
                ->get();
            if ($books->isEmpty()) {
                return response()->json(['message' => 'No books found for this user'], 404);
            }
            return response()->json($books);
        } catch (\Exception $e) {
            Log::error('Error fetching user books', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json(['message' => 'An error occurred while fetching user books'], 500);
        }
    }

    public function getUserBorrowEvents($userId)
    {
        try {
            Log::info('Attempting to fetch borrow events', ['user_id' => $userId]);

            $user = User::where('id', $userId)->first();

            if (!$user) {
                Log::warning('User not found when fetching borrow events', ['user_id' => $userId]);
                return response()->json(['message' => 'User not found'], 404);
            }

            Log::info('Found user, fetching borrow events', ['user_id' => $userId, 'user_name' => $user->name]);

            $borrowEventsQuery = $user->borrowedEvents()
                ->with([
                    'book.pictures',
                    'book.genres',
                    'lender',
                    'meetUpDetail',
                    'returnDetail',
                    'borrowStatus',
                ])
                ->whereHas('borrowStatus', function ($query) {
                    $query->whereIn('borrow_status_id', [1, 2, 4, 7, 8]);
                })
                ->latest()  // Order by created_at desc
                ->take(3);  // Limit to 3 records

            Log::debug('Borrow events query', ['sql' => $borrowEventsQuery->toSql(), 'bindings' => $borrowEventsQuery->getBindings()]);

            $borrowEvents = $borrowEventsQuery->get();

            Log::info('Borrow events query executed', [
                'user_id' => $userId,
                'events_count' => $borrowEvents->count(),
                'has_events' => !$borrowEvents->isEmpty()
            ]);

            if ($borrowEvents->isEmpty()) {
                Log::warning('No borrow events found for user', ['user_id' => $userId]);
                return response()->json(['message' => 'No borrow events found for this user'], 404);
            } else {
                Log::info('Borrow events retrieved successfully', [
                    'user_id' => $userId,
                    'events_count' => $borrowEvents->count(),
                    'first_event_id' => $borrowEvents->first()->id ?? 'none'
                ]);
                return response()->json($borrowEvents);
            }
        } catch (\Exception $e) {
            Log::error('Error fetching user borrow events', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'An error occurred while fetching user borrow events'], 500);
        }
    }

    public function users(Request $request)
    {
        $request->validate([
            'query' => 'sometimes|string|max:100',
            'fields' => 'sometimes|string',
            'status' => 'sometimes|in:0,1,all',
            'sort_by' => 'sometimes|in:name,email,created_at',
            'sort_order' => 'sometimes|in:asc,desc',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        return $this->search(User::class, $request, [
            'searchFields' => ['name', 'email'],
            'filterFields' => ['status'],
            'relationships' => [],
            'withCount' => ['books'],
            'defaultSort' => 'created_at',
            'defaultOrder' => 'desc',
        ]);
    }

    public function books(Request $request)
    {
        $request->validate([
            'query' => 'sometimes|string|max:100',
            'fields' => 'sometimes|string',
            'availability' => 'sometimes|in:1,2,all',
            'status' => 'sometimes|in:0,1,all',
            'genre_id' => 'sometimes|integer',
            'sort_by' => 'sometimes|in:title,author,created_at',
            'sort_order' => 'sometimes|in:asc,desc',
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        return $this->search(Book::class, $request, [
            'searchFields' => ['title', 'author'],
            'filterFields' => ['status'],
            'relationships' => ['user', 'genres', 'pictures', 'availability'],
            'customFilters' => [
                'availability' => function ($query, $value) {
                    if ($value === '1') {
                        $query->whereHas('availability', function ($q) {
                            $q->where('availability_id', 1);
                        });
                    } elseif ($value === '2') {
                        $query->where(function ($q) {
                            $q->whereHas('availability', function ($subQ) {
                                $subQ->where('availability_id', 2);
                            })->orWhereDoesntHave('availability');
                        });
                    }
                },
                'genre_id' => function ($query, $value) {
                    $query->whereHas('genres', function ($q) use ($value) {
                        $q->where('genres.id', $value);
                    });
                }
            ],
            'defaultSort' => 'title',
            'defaultOrder' => 'asc',
        ]);
    }

    private function search($model, Request $request, array $config)
    {
        $query = $request->input('query', '');
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', $config['defaultSort']);
        $sortOrder = $request->input('sort_order', $config['defaultOrder']);

        // Start building query
        $builder = $model::query();

        // Add relationships
        if (!empty($config['relationships'])) {
            $builder->with($config['relationships']);
        }

        // Add withCount
        if (!empty($config['withCount'])) {
            $builder->withCount($config['withCount']);
        }

        // Apply search
        if ($query && $query !== 'a') {
            $searchFields = $config['searchFields'] ?? [];
            if (!empty($searchFields)) {
                $builder->where(function ($q) use ($query, $searchFields) {
                    foreach ($searchFields as $field) {
                        $q->orWhere($field, 'LIKE', "%{$query}%");
                    }
                });
            }
        }

        // Apply simple filters
        if (!empty($config['filterFields'])) {
            foreach ($config['filterFields'] as $field) {
                $value = $request->input($field);
                if ($value !== null && $value !== 'all') {
                    $builder->where($field, $value);
                }
            }
        }

        // Apply custom filters
        if (!empty($config['customFilters'])) {
            foreach ($config['customFilters'] as $filterName => $filterFunction) {
                $value = $request->input($filterName);
                if ($value !== null && $value !== 'all') {
                    $filterFunction($builder, $value);
                }
            }
        }

        // Apply sorting
        $builder->orderBy($sortBy, $sortOrder);

        // Get paginated results
        $results = $builder->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => $results->items(),
            'total' => $results->total(),
            'page' => $results->currentPage(),
            'perPage' => $results->perPage(),
            'hasMore' => $results->hasMorePages(),
        ]);
    }

    public function getAllBooks()
    {
        $book = Book::with(['user', 'genres', 'pictures', 'availability'])->get();

        return response()->json($book);
    }
    public function logout(Request $request)
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function check(Request $request)
    {
        return response()->json($request->user());
    }

    public function getTotalAdmins()
    {
        $totalAdmins = Admin::select('id', 'username', 'super_admin', 'created_at', 'updated_at')->get();
        return response()->json([
            'success' => true,
            'message' => 'Total admins retrieved successfully',
            'data' => $totalAdmins
        ]);
    }

    public function createAdmin(Request $request)
    {

        try {
            $admin = Admin::create([
                'username' => $request->username,
                'password' => Hash::make($request->password),
                'super_admin' => 0,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Admin created successfully',
                'data' => $admin
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating admin', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An error occurred while creating admin',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
