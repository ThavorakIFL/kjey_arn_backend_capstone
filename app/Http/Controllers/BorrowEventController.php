<?php

namespace App\Http\Controllers;

use App\Models\MeetUpDetail;
use App\Models\ReturnDetail;
use Illuminate\Http\Request;
use App\Models\Book;
use App\Models\BookAvailability;
use App\Models\BorrowEvent;
use App\Models\BorrowEventBorrowStatus;
use App\Models\BorrowStatus;
use App\Models\MeetUpDetailMeetUpStatus;
use App\Models\ReturnDetailReturnStatus;
use App\Models\BorrowEventCancelReason;
use App\Models\BorrowEventRejectReason;
use App\Models\BorrowEventReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BorrowEventController extends Controller
{

    public function borrowBook(Request $request)
    {
        try {
            // Fixed validation - combine all end_date rules into one array
            $validated = $request->validate([
                'book_id' => 'required|exists:books,id',
                'start_date' => 'required|date|after:today',
                'end_date' => [
                    'required',
                    'date',
                    'after:start_date',
                    function ($attribute, $value, $fail) use ($request) {

                        $startDate = new \DateTime($request->start_date);
                        $endDate = new \DateTime($value);
                        $interval = $startDate->diff($endDate);


                        if ($interval->days > 14) {

                            $fail('The borrowing period cannot exceed 2 weeks (14 days).');
                        }
                    }
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {;

            // Manually return validation response
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {


            return response()->json([
                'message' => 'Validation failed',
                'error' => $e->getMessage()
            ], 500);
        }

        try {
            $book = Book::findOrFail($validated['book_id']);

            // ... rest of your existing code (checks and database operations)
            // Check if user has an active/pending borrow request for this book
            $hasActiveBorrowRequest = BorrowEvent::where('borrower_id', auth()->id())
                ->where('book_id', $validated['book_id'])
                ->whereHas('borrowStatus', function ($query) {
                    $query->whereIn('borrow_status_id', [1, 2, 4, 7, 8]);
                })
                ->exists();

            if ($hasActiveBorrowRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have an active borrow request for this book. Please wait until your current request is completed, cancelled, or rejected before making a new one.'
                ], 400);
            }

            $activeBorrowsCount = BorrowEvent::where('borrower_id', auth()->id())
                ->whereHas('borrowStatus', function ($query) {
                    $query->whereIn('borrow_status_id', [1, 2, 4, 7, 8]);
                })
                ->count();

            if ($activeBorrowsCount >= 3) {
                return response()->json([
                    'success' => false,
                    'message' => 'You can only have 3 active borrow requests at a time. Please complete or cancel existing requests first.'
                ], 400);
            }

            if ($book->availability->availability_id !== 1) {
                return response()->json([
                    'success' => false,
                    'message' => 'The book is not available for borrowing'
                ], 400);
            }

            if (auth()->id() === $book->user_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot borrow your own book'
                ], 400);
            }

            DB::beginTransaction();

            $borrowEvent = BorrowEvent::create([
                'borrower_id' => auth()->id(),
                'lender_id' => $book->user_id,
                'book_id' => $validated['book_id'],
            ]);

            $requestedStatus = BorrowStatus::where('status', 'Pending')->firstOrFail();

            BorrowEventBorrowStatus::create([
                'borrow_event_id' => $borrowEvent->id,
                'borrow_status_id' => $requestedStatus->id,
            ]);

            $meetUpDetail = MeetUpDetail::create([
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'borrow_event_id' => $borrowEvent->id,
            ]);

            MeetUpDetailMeetUpStatus::create([
                'meet_up_detail_id' => $meetUpDetail->id,
                'meet_up_status_id' => 1,
            ]);

            $returnDetail = ReturnDetail::create([
                'borrow_event_id' => $borrowEvent->id,
                'return_date' => $validated['end_date'],
            ]);

            ReturnDetailReturnStatus::create([
                'return_detail_id' => $returnDetail->id,
                'return_status_id' => 1,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Borrow Successfully!',
                'borrow_event' => $borrowEvent->load('borrower', 'lender', 'book', 'borrowStatus', 'meetUpDetail', 'meetUpDetail.meetUpStatus', 'returnDetail', 'returnDetail.returnStatus'),
            ], 201);
        } catch (\Exception $e) {
            if (isset($borrowEvent)) {
                DB::rollback();
            }



            return response()->json([
                'message' => 'Failed to create borrow request',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function cancelBorrowEvent(Request $request, $id)
    {
        $request = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        try {
            $borrowEvent = BorrowEvent::findOrFail($id);
            $userId = auth()->id();
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            if ($borrowEvent->borrower_id !== $userId && $borrowEvent->lender_id !== $userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to cancel this borrow event'
                ], 403);
            }
            DB::beginTransaction();
            $book = Book::find($borrowEvent->book_id);
            if ($book) {
                $book->availability()->update(['availability_id' => 1]);
            }
            $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)->update(['borrow_status_id' => 6]);
            BorrowEventCancelReason::create([
                'borrow_event_id' => $borrowEvent->id,
                'cancelled_by' => $userId,
                'reason' => $request['reason'],
            ]);
            BookAvailability::where('book_id', $borrowEvent->book_id)->update(['availability_id' => 1]);
            MeetUpDetailMeetUpStatus::where('meet_up_detail_id', $borrowEvent->meetUpDetail->id)->delete();
            ReturnDetailReturnStatus::where('return_detail_id', $borrowEvent->returnDetail->id)->delete();
            MeetUpDetail::where('borrow_event_id', $borrowEvent->id)->delete();
            ReturnDetail::where('borrow_event_id', $borrowEvent->id)->delete();
            DB::commit();
            return response()->json([
                'success' => true,
                'message' => 'Borrow event cancelled successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to cancel borrow event', [
                'borrow_event_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel borrow event'
            ], 500);
        }
    }

    public function confirmReceivedBook($id)
    {
        try {
            $userId = auth()->id();
            $borrowEvent = BorrowEvent::findOrFail($id);
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            if ($userId !== $borrowEvent->lender_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to confirm the received book'
                ], 403);
            }
            DB::beginTransaction();
            $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)->update(['borrow_status_id' => 5]);
            $borrowEvent->book->availability()->update(['availability_id' => 1]);
            return response()->json([
                'success' => true,
                'message' => 'Book received successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to confirm received book', [
                'borrow_event_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to confirm received book'
            ], 500);
        }
    }

    public function reportBorrowEvent(Request $request, $id)
    {
        // Add comprehensive debugging at the start
        Log::info('reportBorrowEvent called', [
            'route_parameter_id' => $id,
            'id_type' => gettype($id),
            'request_body' => $request->all(),
            'request_method' => $request->method(),
            'request_url' => $request->fullUrl(),
            'request_headers' => $request->headers->all(),
        ]);

        // Check for parameter swapping issue
        if (is_string($id) && (
            str_contains($id, "didn't show up") ||
            str_contains($id, "Lender") ||
            str_contains($id, "Borrower")
        )) {
            Log::error('Parameter swap detected in reportBorrowEvent', [
                'received_id' => $id,
                'request_body' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Parameter error: ID parameter appears to contain reason text',
                'debug' => [
                    'received_id' => $id,
                    'expected' => 'numeric borrow event ID',
                ]
            ], 400);
        }

        try {
            $borrowEvent = BorrowEvent::findOrFail($id);
            $userId = auth()->id();

            if (!$userId) {
                Log::warning('Unauthorized attempt to report borrow event', [
                    'borrow_event_id' => $id,
                    'ip' => $request->ip()
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            Log::info('Attempting to report borrow event', [
                'borrow_event_id' => $id,
                'user_id' => $userId,
                'reason' => $request->input('reason')
            ]);

            $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)->update([
                'borrow_status_id' => 8
            ]);

            BorrowEventReport::create([
                'borrow_event_id' => $borrowEvent->id,
                'reported_by' => $userId,
                'reason' => $request->input('reason'),
                'status' => false,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Borrow event reported successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to report borrow event', [
                'borrow_event_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to report borrow event'
            ], 500);
        }
    }

    public function viewBorrowRequests()
    {
        try {
            $userId = auth()->id();
            $query = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.availability',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpStatus',
                'returnDetail',
                'returnDetail.returnStatus'
            ])
                ->where('lender_id', $userId)->whereHas('borrowStatus', function ($q) {
                    $q->where('borrow_status_id', 1);
                });
            $borrowEvents = $query->orderBy('created_at', 'desc')->get();
            if ($borrowEvents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No borrow events found'
                ], 404);
            }
            return response()->json([
                'success' => true,
                'data' => $borrowEvents
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve user borrow requests', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve borrow requests'
            ], 500);
        }
    }

    public function viewBorrowEvents(Request $request)
    {
        try {
            $userId = auth()->id();
            $borrowStatusId = $request->input('borrow_status_id');
            if ($borrowStatusId) {
                if ($borrowStatusId == 1) {
                    $query = BorrowEvent::with([
                        'borrower',
                        'lender',
                        'book',
                        'book.pictures',
                        'borrowStatus',
                        'meetUpDetail',
                        'meetUpDetail.meetUpStatus',
                        'returnDetail',
                        'returnDetail.returnStatus'
                    ])->where(function ($q) use ($userId) {
                        $q->where('borrower_id', $userId);
                    });
                    $borrowEvents = $query->whereHas('borrowStatus', function ($q) use ($borrowStatusId) {
                        $q->where('borrow_status_id', $borrowStatusId);
                    })->orderBy('created_at', 'desc')->get();
                    if ($borrowEvents->isEmpty()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No borrow events found'
                        ], 404);
                    } else {
                        return response()->json([
                            'success' => true,
                            'data' => $borrowEvents
                        ]);
                    }
                } else if ($borrowStatusId == 2) {
                    $query = BorrowEvent::with([
                        'borrower',
                        'lender',
                        'book',
                        'book.pictures',
                        'borrowStatus',
                        'meetUpDetail',
                        'meetUpDetail.meetUpStatus',
                        'returnDetail',
                        'returnDetail.returnStatus'
                    ])->where(function ($q) use ($userId) {
                        $q->where('borrower_id', $userId)
                            ->orWhere('lender_id', $userId);
                    });
                    $borrowEvents = $query->whereHas('borrowStatus', function ($q) use ($borrowStatusId) {
                        $q->where('borrow_status_id', $borrowStatusId);
                    })->orderBy('created_at', 'desc')->get();
                    $lenderBorrowEvent = $borrowEvents->where('lender_id', $userId)->first();
                    $borrowerBorrowEvent = $borrowEvents->where('borrower_id', $userId)->first();
                    if ($borrowEvents->isEmpty()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No borrow events found'
                        ], 404);
                    } else {
                        return response()->json([
                            'success' => true,
                            'data' => [
                                'lender_borrow_event' => $lenderBorrowEvent,
                                'borrower_borrow_event' => $borrowerBorrowEvent
                            ]
                        ]);
                    }
                } else if ($borrowStatusId == 4) {
                    $query = BorrowEvent::with([
                        'borrower',
                        'lender',
                        'book',
                        'book.pictures',
                        'borrowStatus',
                        'meetUpDetail',
                        'meetUpDetail.meetUpStatus',
                        'returnDetail',
                        'returnDetail.returnStatus'
                    ])->where(function ($q) use ($userId) {
                        $q->where('borrower_id', $userId)
                            ->orWhere('lender_id', $userId);
                    });
                    $borrowEvents = $query->whereHas('borrowStatus', function ($q) use ($borrowStatusId) {
                        $q->where('borrow_status_id', $borrowStatusId);
                    })->orderBy('created_at', 'desc')->get();
                    $lenderBorrowEvent = $borrowEvents->where('lender_id', $userId)->first();
                    $borrowerBorrowEvent = $borrowEvents->where('borrower_id', $userId)->first();
                    if ($borrowEvents->isEmpty()) {
                        return response()->json([
                            'success' => false,
                            'message' => 'No borrow events found'
                        ], 404);
                    } else {
                        return response()->json([
                            'success' => true,
                            'data' => [
                                'lender_borrow_event' => $lenderBorrowEvent,
                                'borrower_borrow_event' => $borrowerBorrowEvent
                            ]
                        ]);
                    }
                } else {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid borrow status ID'
                    ], 400);
                }
            }
            $borrowerEvents = BorrowEvent::with([
                'lender',
                'borrower',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpStatus',
                'returnDetail',
                'returnDetail.returnStatus'
            ])->where('borrower_id', $userId)->get();
            $filteredBorrowerEvents = $borrowerEvents->filter(function ($borrowEvent) {
                return in_array($borrowEvent->borrowStatus->borrow_status_id ?? null, [1, 2, 4, 7, 8]);
            })->values();
            $lenderEvents = BorrowEvent::with([
                'lender',
                'borrower',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpStatus',
                'returnDetail',
                'returnDetail.returnStatus'
            ])->where('lender_id', $userId)->get();
            $filteredLenderEvents = $lenderEvents->filter(function ($borrowEvent) {
                return in_array($borrowEvent->borrowStatus->borrow_status_id ?? null, [2, 4, 7, 8]);
            })->values();
            if ($filteredBorrowerEvents->isEmpty() && $filteredLenderEvents->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No borrow events found'
                ], 404);
            } else {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'borrower_borrow_events' => $filteredBorrowerEvents,
                        'lender_borrow_events' => $filteredLenderEvents
                    ]
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to retrieve user borrow events', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve borrow events'
            ], 500);
        }
    }

    public function viewBorrowRequest($id)
    {
        try {
            $userId = auth()->id();
            $borrowEvent = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.availability',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpDetailMeetUpStatus',
                'meetUpDetail.suggestions.user',
                'meetUpDetail.suggestions.suggestionStatus',
                'returnDetail',
                'returnDetail.returnDetailReturnStatus',
                'borrowEventRejectReason',
                'borrowEventCancelReason',
                'borrowEventReport',
            ])
                ->where('id', $id)
                ->where(function ($query) use ($userId) {
                    $query->where('borrower_id', $userId)
                        ->orWhere('lender_id', $userId);
                })
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $borrowEvent
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve borrow event', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve borrow event'
            ], 404);
        }
    }

    public function rejectBorrowRequest(Request $request, $id)
    {
        try {
            $userId = auth()->id();
            $borrowEvent = BorrowEvent::findOrFail($id);
            if (!$borrowEvent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Borrow event not found'
                ], 404);
            }
            if (!$userId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            if ($userId !== $borrowEvent->lender_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not authorized to reject this borrow request'
                ], 403);
            }
            $request->validate([
                'reason' => 'required|string|max:255',
            ]);
            DB::beginTransaction();
            $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)->update(['borrow_status_id' => 3]);
            $borrowEvent->book->availability()->update(['availability_id' => 1]);
            BorrowEventRejectReason::create([
                'borrow_event_id' => $borrowEvent->id,
                'rejected_by' => $userId,
                'reason' => $request['reason'],
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Borrow request rejected successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to reject borrow request', [
                'id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to reject borrow request'
            ], 500);
        }
    }

    public function getAllHistoryBorrowEvent()
    {
        try {
            $userId = auth()->id();
            Log::info('Fetching history borrow events', ['user_id' => $userId]);

            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'returnDetail',
            ])->where(function ($q) use ($userId) {
                $q->where('borrower_id', $userId)
                    ->orWhere('lender_id', $userId);
            })
                ->whereHas('borrowStatus', function ($q) {
                    $q->whereIn('borrow_status_id', [3, 5, 6]);
                })
                ->orderBy('created_at', 'desc')->get();

            Log::info('History borrow events retrieved', [
                'user_id' => $userId,
                'count' => $borrowEvents->count()
            ]);

            // Empty history is a valid state, not an error
            if ($borrowEvents->isEmpty()) {
                Log::info('No history borrow events found - returning empty result', ['user_id' => $userId]);
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No history borrow events found'
                ], 200);
            }

            return response()->json([
                'success' => true,
                'data' => $borrowEvents
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to retrieve history borrow events', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve history borrow events',
                'data' => []
            ], 500);
        }
    }

    public function checkForReturnBorrowEvent()
    {
        try {
            $userId = auth()->id();
            Log::info('Checking for return borrow events', ['user_id' => $userId]);

            if (!$userId) {
                Log::warning('Unauthorized access attempt');
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'returnDetail',
            ])->where(function ($q) use ($userId) {
                $q->where('borrower_id', $userId)
                    ->orWhere('lender_id', $userId);
            })->whereHas('borrowStatus', function ($q) {
                $q->where('borrow_status_id', 4); // Status ID 4 is "In Progress"
            })->get();

            Log::info('Found borrow events', ['count' => $borrowEvents->count()]);

            if ($borrowEvents->isEmpty()) {
                Log::info('No in-progress borrow events found', ['user_id' => $userId]);
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no in-progress borrow events found',
                    'data' => [],
                    'updated_count' => 0
                ], 200); // Changed to 200
            }

            $today = now()->format('Y-m-d');
            Log::info('Checking for return dates', ['today' => $today]);

            $returnDueEvents = [];
            foreach ($borrowEvents as $borrowEvent) {
                Log::info('Checking borrow event', [
                    'id' => $borrowEvent->id,
                    'return_date' => $borrowEvent->returnDetail ? $borrowEvent->returnDetail->return_date : null
                ]);

                if ($borrowEvent->returnDetail && $borrowEvent->returnDetail->return_date === $today) {
                    Log::info('Found event due for return today', ['borrow_event_id' => $borrowEvent->id]);
                    $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)
                        ->update(['borrow_status_id' => 7]);
                    $borrowEvent->refresh();
                    $returnDueEvents[] = $borrowEvent;
                }
            }

            if (empty($returnDueEvents)) {
                Log::info('No borrow events due for return today', ['user_id' => $userId]);
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no events due for return today',
                    'data' => [],
                    'updated_count' => 0
                ], 200); // Changed to 200
            }

            $borrowEvents = collect($returnDueEvents);
            Log::info('Returning due events', ['count' => $borrowEvents->count()]);

            return response()->json([
                'success' => true,
                'message' => 'Check completed - events updated successfully',
                'data' => $borrowEvents,
                'updated_count' => $borrowEvents->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check for return borrow events', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Failed to check for return borrow events'
            ], 500);
        }
    }

    public function checkForUnconfirmedMeetups()
    {
        try {
            Log::info('Checking for unconfirmed meetup details');
            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpDetailMeetUpStatus',
                'returnDetail',
            ])->whereHas('borrowStatus', function ($q) {
                $q->where('borrow_status_id', 2); // Status 2 = Accepted (meetup details set)
            })->whereHas('meetUpDetail', function ($q) {
                $q->where('start_date', '<=', now()->format('Y-m-d')); // Today is the start date
            })->whereHas('meetUpDetail.meetUpDetailMeetUpStatus', function ($q) {
                $q->where('meet_up_status_id', 1); // Status 1 = Pending (not confirmed by borrower)
            })->get();

            Log::info('Found unconfirmed meetup events', ['count' => $borrowEvents->count()]);

            if ($borrowEvents->isEmpty()) {
                Log::info('No unconfirmed meetup events found for today');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no unconfirmed meetups found for today',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = [];
            $today = now()->format('Y-m-d');
            Log::info('Processing unconfirmed meetups for date', ['date' => $today]);

            foreach ($borrowEvents as $borrowEvent) {
                try {
                    Log::info('Processing borrow event for auto-cancellation', [
                        'borrow_event_id' => $borrowEvent->id,
                        'borrower' => $borrowEvent->borrower->name,
                        'lender' => $borrowEvent->lender->name,
                        'book' => $borrowEvent->book->title,
                        'start_date' => $borrowEvent->meetUpDetail->start_date,
                        'meetup_status' => $borrowEvent->meetUpDetail->meetUpDetailMeetUpStatus->meet_up_status_id
                    ]);

                    DB::beginTransaction();

                    // Update book availability back to available
                    $book = Book::find($borrowEvent->book_id);
                    if ($book) {
                        $book->availability()->update(['availability_id' => 1]);
                    }

                    // Update borrow status to cancelled (status_id = 6)
                    $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)
                        ->update(['borrow_status_id' => 6]);

                    // Create cancellation reason - system cancelled due to unconfirmed meetup
                    BorrowEventCancelReason::create([
                        'borrow_event_id' => $borrowEvent->id,
                        'cancelled_by' => $borrowEvent->lender_id, // System cancellation attributed to lender
                        'reason' => 'Borrow event cancelled due to borrower not accepting meetup details on the scheduled start date.',
                    ]);

                    // Clean up related records
                    BookAvailability::where('book_id', $borrowEvent->book_id)
                        ->update(['availability_id' => 1]);

                    // Delete meetup and return detail statuses
                    if ($borrowEvent->meetUpDetail) {
                        MeetUpDetailMeetUpStatus::where('meet_up_detail_id', $borrowEvent->meetUpDetail->id)->delete();
                    }

                    if ($borrowEvent->returnDetail) {
                        ReturnDetailReturnStatus::where('return_detail_id', $borrowEvent->returnDetail->id)->delete();
                    }

                    // Delete meetup and return details
                    MeetUpDetail::where('borrow_event_id', $borrowEvent->id)->delete();
                    ReturnDetail::where('borrow_event_id', $borrowEvent->id)->delete();

                    DB::commit();

                    $cancelledEvents[] = $borrowEvent;

                    Log::info('Successfully auto-cancelled borrow event', [
                        'borrow_event_id' => $borrowEvent->id,
                        'reason' => 'Unconfirmed meetup on start date'
                    ]);
                } catch (\Exception $e) {
                    DB::rollback();
                    Log::error('Failed to auto-cancel individual borrow event', [
                        'borrow_event_id' => $borrowEvent->id,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    // Continue with next event even if one fails
                    continue;
                }
            }

            if (empty($cancelledEvents)) {
                Log::warning('No events were successfully cancelled despite finding unconfirmed meetups');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no events could be cancelled',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = collect($cancelledEvents);
            Log::info('Auto-cancellation completed successfully', [
                'cancelled_count' => $cancelledEvents->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check completed - unconfirmed meetups cancelled successfully',
                'data' => $cancelledEvents->map(function ($event) {
                    return [
                        'borrow_event_id' => $event->id,
                        'borrower' => $event->borrower->name,
                        'lender' => $event->lender->name,
                        'book_title' => $event->book->title,
                        'start_date' => $event->meetUpDetail->start_date ?? null,
                    ];
                }),
                'cancelled_count' => $cancelledEvents->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check for unconfirmed meetups', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for unconfirmed meetups'
            ], 500);
        }
    }

    public function checkForUnacceptedRequests()
    {
        try {
            Log::info('Checking for unaccepted borrow requests');
            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'borrowStatus',
                'meetUpDetail',
                'returnDetail',
            ])->whereHas('borrowStatus', function ($q) {
                $q->where('borrow_status_id', 1); // Status 1 = Pending (not accepted by lender)
            })->whereHas('meetUpDetail', function ($q) {
                $q->where('start_date', '<=', now()->format('Y-m-d')); // Today is the start date
            })->get();

            Log::info('Found unaccepted borrow request events', ['count' => $borrowEvents->count()]);

            if ($borrowEvents->isEmpty()) {
                Log::info('No unaccepted borrow request events found for today');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no unaccepted borrow requests found for today',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = [];
            $today = now()->format('Y-m-d');
            Log::info('Processing unaccepted borrow requests for date', ['date' => $today]);

            foreach ($borrowEvents as $borrowEvent) {
                try {
                    Log::info('Processing borrow event for auto-cancellation due to unaccepted request', [
                        'borrow_event_id' => $borrowEvent->id,
                        'borrower' => $borrowEvent->borrower->name,
                        'lender' => $borrowEvent->lender->name,
                        'book' => $borrowEvent->book->title,
                        'start_date' => $borrowEvent->meetUpDetail->start_date ?? null,
                        'current_status' => $borrowEvent->borrowStatus->borrow_status_id
                    ]);

                    DB::beginTransaction();

                    // Update book availability back to available
                    $book = Book::find($borrowEvent->book_id);
                    if ($book) {
                        $book->availability()->update(['availability_id' => 1]);
                    }

                    // Update borrow status to cancelled (status_id = 6)
                    $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)
                        ->update(['borrow_status_id' => 6]);

                    // Create cancellation reason - system cancelled due to lender not accepting request
                    BorrowEventCancelReason::create([
                        'borrow_event_id' => $borrowEvent->id,
                        'cancelled_by' => $borrowEvent->borrower_id, // System cancellation attributed to borrower
                        'reason' => 'Borrow request has been cancelled because the lender didn\'t accept the request.',
                    ]);

                    // Clean up related records
                    BookAvailability::where('book_id', $borrowEvent->book_id)
                        ->update(['availability_id' => 1]);

                    // Delete meetup and return detail statuses if they exist
                    if ($borrowEvent->meetUpDetail) {
                        MeetUpDetailMeetUpStatus::where('meet_up_detail_id', $borrowEvent->meetUpDetail->id)->delete();
                    }

                    if ($borrowEvent->returnDetail) {
                        ReturnDetailReturnStatus::where('return_detail_id', $borrowEvent->returnDetail->id)->delete();
                    }

                    // Delete meetup and return details if they exist
                    MeetUpDetail::where('borrow_event_id', $borrowEvent->id)->delete();
                    ReturnDetail::where('borrow_event_id', $borrowEvent->id)->delete();

                    DB::commit();

                    $cancelledEvents[] = $borrowEvent;

                    Log::info('Successfully auto-cancelled borrow event due to unaccepted request', [
                        'borrow_event_id' => $borrowEvent->id,
                        'reason' => 'Lender did not accept request by start date'
                    ]);
                } catch (\Exception $e) {
                    DB::rollback();
                    Log::error('Failed to auto-cancel individual borrow event for unaccepted request', [
                        'borrow_event_id' => $borrowEvent->id,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    // Continue with next event even if one fails
                    continue;
                }
            }

            if (empty($cancelledEvents)) {
                Log::warning('No events were successfully cancelled despite finding unaccepted requests');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no events could be cancelled',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = collect($cancelledEvents);
            Log::info('Auto-cancellation for unaccepted requests completed successfully', [
                'cancelled_count' => $cancelledEvents->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check completed - unaccepted borrow requests cancelled successfully',
                'data' => $cancelledEvents->map(function ($event) {
                    return [
                        'borrow_event_id' => $event->id,
                        'borrower' => $event->borrower->name,
                        'lender' => $event->lender->name,
                        'book_title' => $event->book->title,
                        'start_date' => $event->meetUpDetail->start_date ?? null,
                    ];
                }),
                'cancelled_count' => $cancelledEvents->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check for unaccepted borrow requests', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for unaccepted borrow requests'
            ], 500);
        }
    }

    public function checkForOverdueAcceptedEvents()
    {
        try {
            Log::info('Checking for overdue accepted borrow events');
            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'borrowStatus',
                'meetUpDetail',
                'returnDetail',
            ])->whereHas('borrowStatus', function ($q) {
                $q->where('borrow_status_id', 2); // Status 2 = Accepted
            })->whereHas('meetUpDetail', function ($q) {
                $q->where('start_date', '<', now()->format('Y-m-d')); // Start date is before today
            })->get();

            Log::info('Found overdue accepted borrow events', ['count' => $borrowEvents->count()]);

            if ($borrowEvents->isEmpty()) {
                Log::info('No overdue accepted borrow events found');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no overdue accepted events found',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = [];
            $today = now()->format('Y-m-d');
            Log::info('Processing overdue accepted borrow events for date', ['date' => $today]);

            foreach ($borrowEvents as $borrowEvent) {
                try {
                    Log::info('Processing borrow event for auto-cancellation due to overdue acceptance', [
                        'borrow_event_id' => $borrowEvent->id,
                        'borrower' => $borrowEvent->borrower->name,
                        'lender' => $borrowEvent->lender->name,
                        'book' => $borrowEvent->book->title,
                        'start_date' => $borrowEvent->meetUpDetail->start_date ?? null,
                        'current_status' => $borrowEvent->borrowStatus->borrow_status_id
                    ]);

                    DB::beginTransaction();

                    // Update book availability back to available
                    $book = Book::find($borrowEvent->book_id);
                    if ($book) {
                        $book->availability()->update(['availability_id' => 1]);
                    }

                    // Update borrow status to cancelled (status_id = 6)
                    $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)
                        ->update(['borrow_status_id' => 6]);

                    // Create cancellation reason - system cancelled due to overdue acceptance
                    BorrowEventCancelReason::create([
                        'borrow_event_id' => $borrowEvent->id,
                        'cancelled_by' => $borrowEvent->lender_id, // System cancellation attributed to lender
                        'reason' => 'Borrow event has been cancelled because both lender and borrower did not come.',
                    ]);

                    // Clean up related records
                    BookAvailability::where('book_id', $borrowEvent->book_id)
                        ->update(['availability_id' => 1]);

                    // Delete meetup and return detail statuses if they exist
                    if ($borrowEvent->meetUpDetail) {
                        MeetUpDetailMeetUpStatus::where('meet_up_detail_id', $borrowEvent->meetUpDetail->id)->delete();
                    }

                    if ($borrowEvent->returnDetail) {
                        ReturnDetailReturnStatus::where('return_detail_id', $borrowEvent->returnDetail->id)->delete();
                    }

                    // Delete meetup and return details if they exist
                    MeetUpDetail::where('borrow_event_id', $borrowEvent->id)->delete();
                    ReturnDetail::where('borrow_event_id', $borrowEvent->id)->delete();

                    DB::commit();

                    $cancelledEvents[] = $borrowEvent;

                    Log::info('Successfully auto-cancelled borrow event due to overdue acceptance', [
                        'borrow_event_id' => $borrowEvent->id,
                        'reason' => 'Start date passed without meetup confirmation'
                    ]);
                } catch (\Exception $e) {
                    DB::rollback();
                    Log::error('Failed to auto-cancel individual borrow event for overdue acceptance', [
                        'borrow_event_id' => $borrowEvent->id,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    // Continue with next event even if one fails
                    continue;
                }
            }

            if (empty($cancelledEvents)) {
                Log::warning('No events were successfully cancelled despite finding overdue accepted events');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no events could be cancelled',
                    'data' => [],
                    'cancelled_count' => 0
                ], 200);
            }

            $cancelledEvents = collect($cancelledEvents);
            Log::info('Auto-cancellation for overdue accepted events completed successfully', [
                'cancelled_count' => $cancelledEvents->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check completed - overdue accepted borrow events cancelled successfully',
                'data' => $cancelledEvents->map(function ($event) {
                    return [
                        'borrow_event_id' => $event->id,
                        'borrower' => $event->borrower->name,
                        'lender' => $event->lender->name,
                        'book_title' => $event->book->title,
                        'start_date' => $event->meetUpDetail->start_date ?? null,
                    ];
                }),
                'cancelled_count' => $cancelledEvents->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check for overdue accepted borrow events', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for overdue accepted borrow events'
            ], 500);
        }
    }

    public function checkForOverdueReturnEvents()
    {
        try {
            Log::info('Checking for overdue return events');

            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'borrowStatus',
                'meetUpDetail',
                'returnDetail',
                'returnDetail.returnDetailReturnStatus',
            ])->whereHas('borrowStatus', function ($q) {
                $q->where('borrow_status_id', 7);
            })->whereHas('returnDetail', function ($q) {
                $q->where('return_date', '<', now()->format('Y-m-d'));
            })->whereHas('returnDetail.returnDetailReturnStatus', function ($q) {
                $q->where('return_status_id', 3);
            })->get();

            Log::info('Found overdue return events', ['count' => $borrowEvents->count()]);

            if ($borrowEvents->isEmpty()) {
                Log::info('No overdue return events found');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no overdue return events found',
                    'data' => [],
                    'updated_count' => 0
                ], 200);
            }

            $updatedEvents = [];
            $today = now()->format('Y-m-d');
            Log::info('Processing overdue returns for date', ['date' => $today]);

            foreach ($borrowEvents as $borrowEvent) {
                try {
                    Log::info('Processing borrow event for conversion to deposit status', [
                        'borrow_event_id' => $borrowEvent->id,
                        'borrower' => $borrowEvent->borrower->name,
                        'lender' => $borrowEvent->lender->name,
                        'book' => $borrowEvent->book->title,
                        'return_date' => $borrowEvent->returnDetail->return_date ?? null,
                        'days_overdue' => now()->diffInDays($borrowEvent->returnDetail->return_date),
                    ]);

                    DB::beginTransaction();
                    // Update borrow status to deposit (status_id = 8)
                    $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)
                        ->update(['borrow_status_id' => 8]);
                    DB::commit();

                    BorrowEventReport::create([
                        'borrow_event_id' => $borrowEvent->id,
                        'reported_by' => null, // Null = system
                        'reason' => 'Borrow event has been converted to deposit status due to overdue return.',
                        'status' => false,
                    ]);

                    // Refresh the model to get updated status
                    $borrowEvent->refresh();
                    $updatedEvents[] = $borrowEvent;

                    Log::info('Successfully converted borrow event to deposit status', [
                        'borrow_event_id' => $borrowEvent->id,
                        'days_overdue' => now()->diffInDays($borrowEvent->returnDetail->return_date)
                    ]);
                } catch (\Exception $e) {
                    DB::rollback();
                    Log::error('Failed to convert individual borrow event to deposit status', [
                        'borrow_event_id' => $borrowEvent->id,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine()
                    ]);
                    // Continue with next event even if one fails
                    continue;
                }
            }

            if (empty($updatedEvents)) {
                Log::warning('No events were successfully converted despite finding overdue returns');
                return response()->json([
                    'success' => true,
                    'message' => 'Check completed - no events could be converted to deposit',
                    'data' => [],
                    'updated_count' => 0
                ], 200);
            }

            $updatedEvents = collect($updatedEvents);
            Log::info('Conversion to deposit status for overdue returns completed successfully', [
                'updated_count' => $updatedEvents->count()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Check completed - overdue return events converted to deposit successfully',
                'data' => $updatedEvents->map(function ($event) {
                    return [
                        'borrow_event_id' => $event->id,
                        'borrower' => $event->borrower->name,
                        'lender' => $event->lender->name,
                        'book_title' => $event->book->title,
                        'return_date' => $event->returnDetail->return_date ?? null,
                        'days_overdue' => now()->diffInDays($event->returnDetail->return_date),
                        'new_status' => 'Deposit'
                    ];
                }),
                'updated_count' => $updatedEvents->count()
            ], 200);
        } catch (\Exception $e) {
            Log::error('Failed to check for overdue return borrow events', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for overdue return borrow events'
            ], 500);
        }
    }
}
