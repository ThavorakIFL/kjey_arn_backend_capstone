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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BorrowEventController extends Controller
{
    public function borrowBook(Request $request)
    {
        try {
            $validated = $request->validate([
                'book_id' => 'required|exists:books,id',
                'start_date' => 'required|date|after:today',
                'end_date' => 'required|date|after:start_date',
            ]);
            $book = Book::findOrFail($validated['book_id']);
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

            try {
                DB::beginTransaction();

                $borrowEvent = BorrowEvent::create([
                    'borrower_id' => auth()->id(),
                    'lender_id' => $book->user_id,
                    'book_id' => $validated['book_id'],
                ]);

                $requestedStatus = BorrowStatus::where('status', 'Pending')->firstOrFail();

                $book->availability()->update(['availability_id' => 2]);

                BorrowEventBorrowStatus::create([
                    'borrow_event_id' => $borrowEvent->id,
                    'borrow_status_id' => $requestedStatus->id,
                ]);

                MeetUpDetail::create([
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'borrow_event_id' => $borrowEvent->id,
                ]);

                MeetUpDetailMeetUpStatus::create([
                    'meet_up_detail_id' => $borrowEvent->meetUpDetail->id,
                    'meet_up_status_id' => 1,
                ]);

                ReturnDetail::create([
                    'borrow_event_id' => $borrowEvent->id,
                    'return_date' => $validated['end_date'],
                ]);

                ReturnDetailReturnStatus::create([
                    'return_detail_id' => $borrowEvent->returnDetail->id,
                    'return_status_id' => 1,
                ]);

                DB::commit();

                return response()->json([
                    'message' => 'Borrow event created successfully',
                    'borrow_event' => $borrowEvent->load('borrower', 'lender', 'book', 'borrowStatus', 'meetUpDetail', 'meetUpDetail.meetUpStatus', 'returnDetail', 'returnDetail.returnStatus'),
                ], 201);
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
        } catch (\Exception $e) {
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

    public function viewBorrowRequests()
    {
        try {
            $userId = auth()->id();
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
            $borrowEvents = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpStatus',
                'returnDetail',
                'returnDetail.returnStatus'
            ])
                ->where(function ($q) use ($userId) {
                    $q->where('borrower_id', $userId)
                        ->orWhere('lender_id', $userId);
                })
                ->whereHas('borrowStatus', function ($q) {
                    $q->whereIn('borrow_status_id', [1, 2, 4]);
                })
                ->orderBy('created_at', 'desc')
                ->get();
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

    public function viewBorrowEvent($id)
    {
        try {
            $userId = auth()->id();
            $borrowEvent = BorrowEvent::with([
                'borrower',
                'lender',
                'book',
                'book.pictures',
                'borrowStatus',
                'meetUpDetail',
                'meetUpDetail.meetUpDetailMeetUpStatus',
                'meetUpDetail.suggestions.user',
                'returnDetail',
                'returnDetail.returnDetailReturnStatus'
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

            if ($borrowEvents->isEmpty()) {
                Log::info('No history borrow events found', ['user_id' => $userId]);
                return response()->json([
                    'success' => false,
                    'message' => 'No history borrow events found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $borrowEvents
            ]);
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
                'message' => 'Failed to retrieve history borrow events'
            ], 500);
        }
    }
}
