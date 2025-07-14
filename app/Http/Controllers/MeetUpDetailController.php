<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BorrowEvent;
use App\Models\MeetUpDetail;
use App\Models\MeetUpSuggestion;
use App\Models\MeetUpSuggestionStatus;
use App\Models\ReturnDetail;
use Illuminate\Support\Facades\Log;

class MeetUpDetailController extends Controller
{
    public function confirmAndSetMeetUp(Request $request, $borrowEventId)
    {
        $borrowEvent = BorrowEvent::findOrFail($borrowEventId);
        if ($borrowEvent->borrowStatus?->borrow_status_id !== 1) {
            return response()->json(['message' => 'Cannot set meet up details. The borrow event status is not pending.'], 400);
        }
        if (auth()->id() !== $borrowEvent->lender_id) {
            return response()->json(['message' => 'Only the owner of the book can set the meet up details.'], 403);
        }
        try {
            $validated = $request->validate([
                'final_time' => [
                    'required',
                    'string',
                    'date_format:H:i',
                    'after_or_equal:07:00:',
                    'before_or_equal:17:00:',
                ],
                'final_location' => 'required|string|max:255',
            ], [
                'final_time.required' => 'The time is required.',
                'final_time.date_format' => 'The time must be in the format HH:MM.',
                'final_time.after_or_equal' => 'The time must be after or at 07:00 AM.',
                'final_time.before_or_equal' => 'The time must be before or at 05:00 PM.',
                'final_location.required' => 'The location is required.',
                'final_location.max' => 'The location cannot exceed 255 characters.',
            ]);
            $book = $borrowEvent->book;
            if (!$book) {
                return response()->json(['message' => 'Book not found for this borrow event.'], 404);
            }
            $book->availability()->update(['availability_id' => 2]);
            $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->firstOrFail();
            $meetUpDetail->update([
                'final_time' => $validated['final_time'],
                'final_location' => $validated['final_location'],
            ]);
            $borrowEvent->borrowStatus()->where('borrow_event_id', $borrowEvent->id)->update(['borrow_status_id' => 2]);
            return response()->json([
                'message' => 'Meet up details updated successfully.',
                'meet_up_detail' => $meetUpDetail,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Borrow event or meet up detail not found.'], 404);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['errors' => $e->errors()], 422);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }
    }
    public function confirmMeetUp(Request $request, $borrowEventId)
    {
        try {
            $borrowEvent = BorrowEvent::find($borrowEventId);
            if (!$borrowEvent) {
                return response()->json(['message' => 'Borrow event not found.'], 404);
            }
            if (auth()->id() !== $borrowEvent->borrower_id) {
                return response()->json(['message' => 'Unauthorized. Only the borrower can confirm the Meet Up.'], 403);
            }
            $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();
            if (!$meetUpDetail) {
                return response()->json(['message' => 'Meet up detail not found.'], 404);
            }
            $returnDetail = ReturnDetail::where('borrow_event_id', $borrowEventId)->first();
            $meetUpPivot = $meetUpDetail->meetUpDetailMeetUpStatus()->first();
            if (!$meetUpPivot) {
                return response()->json(['message' => 'Meet up status not found.'], 404);
            }
            $validated = $request->validate([
                'meet_up_status_id' => 'required|integer|exists:meet_up_statuses,id',
            ]);
            if ($validated['meet_up_status_id'] !== 2 && $meetUpPivot) {
                return response()->json(['message' => 'Error Confirming Meet Up'], 400);
            }
            $meetUpPivot->meet_up_status_id = 2;
            $meetUpPivot->save();
            return response()->json([
                'message' => 'Meet up status updated successfully.',
                'meet_up_status_id' => $validated['meet_up_status_id'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while confirming meet up.'], 500);
        }
    }

    public function suggestMeetUp(Request $request, $borrowEventId)
    {
        $borrowEvent = BorrowEvent::find($borrowEventId);
        $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();

        try {
            if (!$borrowEvent) {
                return response()->json(['message' => 'Borrow event not found.'], 404);
            }

            if ($borrowEvent->borrowStatus?->borrow_status_id !== 2) {
                return response()->json(['message' => 'Cannot set meet up details. The borrow event status is not approved.'], 400);
            }

            $existingSuggestion = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->where('suggested_by', auth()->id())->first();
            if ($existingSuggestion) {
                return response()->json(['message' => 'You have already made a suggestion for this meet-up.'], 400);
            }

            $suggestionCount = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->count();
            if ($suggestionCount < 0 && auth()->id() == $borrowEvent->lender_id) {
                return response()->json(['message' => 'You cannot suggest a meet-up. You are the lender.'], 400);
            }

            if (!$meetUpDetail) {
                return response()->json(['message' => 'Meet up detail not found for this borrow event.'], 404);
            }

            $validated = $request->validate([
                'suggested_time' => [
                    'required',
                    'string',
                    'date_format:H:i',
                    'after_or_equal:07:00:',
                    'before_or_equal:17:00:',
                ],
                'suggested_location' => 'required|string',
                'suggested_reason' => 'required|string',
            ]);

            $meetUpSuggestion = MeetUpSuggestion::create([
                'meet_up_detail_id' => $meetUpDetail->id,
                'suggested_by' => auth()->id(),
                'suggested_time' => $validated['suggested_time'],
                'suggested_location' => $validated['suggested_location'],
                'suggested_reason' => $validated['suggested_reason'],
            ]);

            MeetUpSuggestionStatus::create([
                'meet_up_suggestion_id' => $meetUpSuggestion->id,
                'suggestion_status_id' => 1,
            ]);
            return response()->json([
                'message' => 'Meet up suggestion created successfully.',
                'meet_up_suggestion' => $meetUpSuggestion->load('user', 'suggestionStatus'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function confirmMeetUpSuggestion($borrowEventId)
    {
        $borrowEvent = BorrowEvent::find($borrowEventId);
        if (!$borrowEvent) {
            return response()->json(['message' => 'Borrow event not found.'], 404);
        }
        $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();
        if (!$meetUpDetail) {
            return response()->json(['message' => 'Meet up detail not found.'], 404);
        }
        $meetUpDetailStatus = $meetUpDetail->meetUpDetailMeetUpStatus()->first();
        if (!$meetUpDetailStatus) {
            return response()->json(['message' => 'Meet up detail status not found.'], 404);
        }
        try {
            $meetUpSuggestions = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->get();

            if ($meetUpSuggestions->isEmpty()) {
            }

            foreach ($meetUpSuggestions as $suggestion) {
                $suggestionStatus = MeetUpSuggestionStatus::where('meet_up_suggestion_id', $suggestion->id)->first();
                if ($suggestionStatus) {
                    $suggestionStatus->update([
                        'suggestion_status_id' => 2
                    ]);
                    $meetUpDetailStatus->update([
                        'meet_up_status_id' => 2,
                    ]);
                } else {
                    Log::warning('Suggestion status not found for suggestion ID: ' . $suggestion->id);
                }
            }

            $latestSuggestion = $meetUpSuggestions->sortByDesc('created_at')->first();
            if ($latestSuggestion) {
                $meetUpDetail->update([
                    'final_time' => $latestSuggestion->suggested_time,
                    'final_location' => $latestSuggestion->suggested_location,
                ]);
            } else {
                Log::warning('No latest suggestion found to update meet up details');
            }

            return response()->json([
                'message' => 'Meet up suggestion status updated successfully.',
                'suggestion_status_id' => 2,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
