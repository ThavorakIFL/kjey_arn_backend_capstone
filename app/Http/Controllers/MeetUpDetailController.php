<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BorrowEvent;
use App\Models\BorrowStatus;
use App\Models\BorrowEventBorrowStatus;
use App\Models\BorrowEventRejectReason;
use App\Models\MeetUpDetail;
use App\Models\MeetUpSuggestion;
use App\Models\MeetUpSuggestionStatus;
use App\Models\ReturnDetail;
use Illuminate\Support\Facades\Log;

class MeetUpDetailController extends Controller
{
    public function setMeetUp(Request $request, $borrowEventId)
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
            \Illuminate\Support\Facades\Log::error('Error setting meet up: ' . $e->getMessage());
            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }
    }
    public function confirmMeetUp(Request $request, $borrowEventId)
    {
        Log::info('confirmMeetUp method called for borrow event: ' . $borrowEventId);
        try {
            $borrowEvent = BorrowEvent::find($borrowEventId);
            if (!$borrowEvent) {
                Log::warning('Borrow event not found: ' . $borrowEventId);
                return response()->json(['message' => 'Borrow event not found.'], 404);
            }

            Log::info('User ID: ' . auth()->id() . ', Borrower ID: ' . $borrowEvent->borrower_id);
            if (auth()->id() !== $borrowEvent->borrower_id) {
                Log::warning('Unauthorized access attempt by user: ' . auth()->id());
                return response()->json(['message' => 'Unauthorized. Only the borrower can confirm the Meet Up.'], 403);
            }
            $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();
            if (!$meetUpDetail) {
                Log::error('Meet up detail not found for borrow event: ' . $borrowEventId);
                return response()->json(['message' => 'Meet up detail not found.'], 404);
            }
            $returnDetail = ReturnDetail::where('borrow_event_id', $borrowEventId)->first();
            $meetUpPivot = $meetUpDetail->meetUpDetailMeetUpStatus()->first();
            if (!$meetUpPivot) {
                Log::error('Meet up pivot not found for meet up detail: ' . $meetUpDetail->id);
                return response()->json(['message' => 'Meet up status not found.'], 404);
            }
            $validated = $request->validate([
                'meet_up_status_id' => 'required|integer|exists:meet_up_statuses,id',
            ]);
            Log::info('Requested meet_up_status_id: ' . $validated['meet_up_status_id']);
            if ($validated['meet_up_status_id'] !== 2 && $meetUpPivot) {
                Log::warning('Invalid meet up status ID: ' . $validated['meet_up_status_id']);
                return response()->json(['message' => 'Error Confirming Meet Up'], 400);
            }
            $meetUpPivot->meet_up_status_id = 2;
            $meetUpPivot->save();
            Log::info('Meet up status successfully updated to 2 for borrow event: ' . $borrowEventId);
            return response()->json([
                'message' => 'Meet up status updated successfully.',
                'meet_up_status_id' => $validated['meet_up_status_id'],
            ]);
        } catch (\Exception $e) {
            Log::error('Error in confirmMeetUp: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(['message' => 'An error occurred while confirming meet up.'], 500);
        }
    }

    public function suggestMeetUp(Request $request, $borrowEventId)
    {
        Log::info('suggestMeetUp method called for borrow event: ' . $borrowEventId);
        $borrowEvent = BorrowEvent::find($borrowEventId);
        $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();

        try {
            if (!$borrowEvent) {
                Log::warning('Borrow event not found: ' . $borrowEventId);
                return response()->json(['message' => 'Borrow event not found.'], 404);
            }

            if ($borrowEvent->borrowStatus?->borrow_status_id !== 2) {
                Log::warning('Invalid borrow status for event: ' . $borrowEventId . ', status: ' . $borrowEvent->borrowStatus?->borrow_status_id);
                return response()->json(['message' => 'Cannot set meet up details. The borrow event status is not approved.'], 400);
            }

            $existingSuggestion = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->where('suggested_by', auth()->id())->first();
            if ($existingSuggestion) {
                Log::info('User already made a suggestion for meet-up detail: ' . $meetUpDetail->id);
                return response()->json(['message' => 'You have already made a suggestion for this meet-up.'], 400);
            }

            $suggestionCount = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->count();
            if ($suggestionCount < 0 && auth()->id() == $borrowEvent->lender_id) {
                Log::warning('Lender attempted to suggest meet-up: user ' . auth()->id());
                return response()->json(['message' => 'You cannot suggest a meet-up. You are the lender.'], 400);
            }

            if (!$meetUpDetail) {
                Log::error('Meet up detail not found for borrow event: ' . $borrowEventId);
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

            Log::info('Meet up suggestion created successfully for borrow event: ' . $borrowEventId);
            return response()->json([
                'message' => 'Meet up suggestion created successfully.',
                'meet_up_suggestion' => $meetUpSuggestion->load('user', 'suggestionStatus'),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in suggestMeetUp: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    public function confirmMeetUpSuggestion($borrowEventId)
    {
        Log::info('confirmMeetUpSuggestion method called for borrow event: ' . $borrowEventId);
        $borrowEvent = BorrowEvent::find($borrowEventId);

        if (!$borrowEvent) {
            Log::warning('Borrow event not found: ' . $borrowEventId);
            return response()->json(['message' => 'Borrow event not found.'], 404);
        }

        $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();
        if (!$meetUpDetail) {
            Log::error('Meet up detail not found for borrow event: ' . $borrowEventId);
            return response()->json(['message' => 'Meet up detail not found.'], 404);
        }

        $meetUpDetailStatus = $meetUpDetail->meetUpDetailMeetUpStatus()->first();
        if (!$meetUpDetailStatus) {
            Log::error('Meet up detail status not found for meet up detail: ' . $meetUpDetail->id);
            return response()->json(['message' => 'Meet up detail status not found.'], 404);
        }

        try {
            Log::info('Retrieving meet up suggestions for meet up detail: ' . $meetUpDetail->id);
            $meetUpSuggestions = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->get();

            if ($meetUpSuggestions->isEmpty()) {
                Log::warning('No meet up suggestions found for meet up detail: ' . $meetUpDetail->id);
            }

            foreach ($meetUpSuggestions as $suggestion) {
                Log::info('Processing suggestion ID: ' . $suggestion->id);
                $suggestionStatus = MeetUpSuggestionStatus::where('meet_up_suggestion_id', $suggestion->id)->first();
                if ($suggestionStatus) {
                    $suggestionStatus->update([
                        'suggestion_status_id' => 2
                    ]);
                    $meetUpDetailStatus->update([
                        'meet_up_status_id' => 2,
                    ]);
                    Log::info('Updated suggestion status for suggestion ID: ' . $suggestion->id);
                } else {
                    Log::warning('Suggestion status not found for suggestion ID: ' . $suggestion->id);
                }
            }

            $latestSuggestion = $meetUpSuggestions->sortByDesc('created_at')->first();
            if ($latestSuggestion) {
                Log::info('Updating meet up detail with latest suggestion (ID: ' . $latestSuggestion->id . ')');
                $meetUpDetail->update([
                    'final_time' => $latestSuggestion->suggested_time,
                    'final_location' => $latestSuggestion->suggested_location,
                ]);
            } else {
                Log::warning('No latest suggestion found to update meet up details');
            }

            Log::info('Meet up suggestion confirmation completed successfully for borrow event: ' . $borrowEventId);
            return response()->json([
                'message' => 'Meet up suggestion status updated successfully.',
                'suggestion_status_id' => 2,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to confirm meet up suggestion: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json([
                'message' => 'Something went wrong.',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    // public function confirmMeetUpSuggestion($borrowEventId)
    // {
    //     $borrowEvent = BorrowEvent::find($borrowEventId);
    //     $meetUpDetail = MeetUpDetail::where('borrow_event_id', $borrowEventId)->first();
    //     $meetUpDetailStatus = $meetUpDetail->meetUpDetailMeetUpStatus()->first();
    //     if (!$borrowEvent) {
    //         return response()->json(['message' => 'Borrow event not found.'], 404);
    //     }
    //     try {
    //         $meetUpSuggestions = MeetUpSuggestion::where('meet_up_detail_id', $meetUpDetail->id)->get();
    //         foreach ($meetUpSuggestions as $suggestion) {
    //             $suggestionStatus = MeetUpSuggestionStatus::where('meet_up_suggestion_id', $suggestion->id)->first();
    //             if ($suggestionStatus) {
    //                 $suggestionStatus->update([
    //                     'suggestion_status_id' => $validated['suggestion_status_id'],
    //                 ]);

    //                 $meetUpDetailStatus->update([
    //                     'meet_up_status_id' => 2,
    //                 ]);
    //             }
    //         }
    //         if ($validated['suggestion_status_id'] == 2) {
    //             $latestSuggestion = $meetUpSuggestions->sortByDesc('created_at')->first();

    //             if ($latestSuggestion) {
    //                 $meetUpDetail->update([
    //                     'final_time' => $latestSuggestion->suggested_time,
    //                     'final_location' => $latestSuggestion->suggested_location,
    //                 ]);
    //             }
    //         } else if ($validated['suggestion_status_id'] == 3) {
    //             $meetUpDetailStatus->update([
    //                 'meet_up_status_id' => 3,
    //             ]);
    //         }

    //         return response()->json([
    //             'message' => 'Meet up suggestion status updated successfully.',
    //             'suggestion_status_id' => $validated['suggestion_status_id'],
    //         ]);
    //     } catch (\Exception $e) {
    //         Log::error('Failed to confirm meet up suggestion: ' . $e->getMessage());

    //         return response()->json([
    //             'message' => 'Something went wrong.',
    //             'error' => $e->getMessage()
    //         ], 500);
    //     }
    // }
}
