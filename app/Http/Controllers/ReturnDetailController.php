<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\BorrowEvent;
use App\Models\ReturnDetail;
use App\Models\ReturnDetailReturnStatus;
use App\Models\ReturnSuggestion;
use App\Models\BorrowEventBorrowStatus;
use App\Models\ReturnSuggestionStatus;
use Illuminate\Support\Facades\Log;



class ReturnDetailController extends Controller
{
    public function receiveBookAndSetReturnDetail(Request $request, $borrowEventId)
    {
        $borrowEvent = BorrowEvent::find($borrowEventId);
        if (!$borrowEvent) {
            Log::error('Borrow event not found for ID: ' . $borrowEventId);
            return response()->json(['message' => 'Borrow event not found.'], 404);
        }
        if ($borrowEvent->borrowStatus->borrow_status_id !== 2) {
            Log::warning('Cannot set return details - incorrect status: ' . $borrowEvent->borrowStatus->borrow_status_id);
            return response()->json([
                'message' => 'Cannot set return details because the status is not approved.'
            ], 500);
        } else {
            try {
                $validated = $request->validate([
                    'return_time' => 'required|string',
                    'return_location' => 'required|string',
                ]);

                $returnDetail = ReturnDetail::where('borrow_event_id', $borrowEventId)->first();
                if (!$returnDetail) {
                    Log::error('Return detail not found for borrowEventId: ' . $borrowEventId);
                    return response()->json(['message' => 'Return detail not found.'], 404);
                }

                if (auth()->id() !== $borrowEvent->borrower_id) {
                    Log::warning('Unauthorized attempt to set return details. User: ' . auth()->id() . ', Borrower: ' . $borrowEvent->borrower_id);
                    return response()->json(['message' => 'Unauthorized. Only the Borrower can set the return time and location.'], 403);
                } else {
                    $pivot = $returnDetail->returnDetailReturnStatus()->first();
                    if ($pivot->return_status_id !== 1) {
                        Log::error('Failed to update return detail - incorrect status: ' . $pivot->return_status_id);
                        return response()->json([
                            'message' => 'Failed to update the return detail status.'
                        ], 500);
                    } else {
                        $returnDetail->return_time = $validated['return_time'];
                        $returnDetail->return_location = $validated['return_location'];
                        $returnDetail->save();
                        Log::info('Updating borrow status to 4 for borrowEventId: ' . $borrowEvent->id);
                        $borrowEvent->borrowStatus->where('borrow_event_id', $borrowEvent->id)->update(['borrow_status_id' => 4]);
                        $pivot->return_status_id = 3;
                        $pivot->save();
                        Log::info('Return detail successfully updated for borrowEventId: ' . $borrowEventId);
                        return response()->json([
                            'message' => 'Return detail updated successfully.',
                            'return_detail' => $returnDetail,
                        ]);
                    }
                }
            } catch (\Exception $e) {
                Log::error('Exception in setReturnDetail: ' . $e->getMessage());
                return response()->json([
                    'message' => 'Failed to update return details.',
                    'error' => $e->getMessage()
                ], 500);
            }
        }
    }

    public function suggestReturnDetail(Request $request, $borrowEventId)
    {
        $borrowEvent = BorrowEvent::find($borrowEventId);
        $returnDetail = ReturnDetail::where('borrow_event_id', $borrowEventId)->first();

        if (!$borrowEvent) {
            return response()->json([
                'message' => 'Borrow Event Not Found'
            ]);
        } else {
            $validated = $request->validate([
                'suggested_time' => 'required|string',
                'suggested_location' => 'required|string',
            ]);

            if (!$returnDetail) {
                return response()->json(['message' => 'Return detail not found.'], 404);
            } else {
                $existingSuggestion = ReturnSuggestion::where('return_detail_id', $returnDetail->id)->where('suggested_by', auth()->id())->first();
                if ($existingSuggestion) {
                    return response()->json([
                        'message' => 'You have already suggested a return detail.',
                    ], 400);
                } else {
                    try {
                        $returnSuggestion = ReturnSuggestion::create([
                            'return_detail_id' => $returnDetail->id,
                            'suggested_by' => auth()->id(),
                            'suggested_time' => $validated['suggested_time'],
                            'suggested_location' => $validated['suggested_location'],
                        ]);
                        ReturnSuggestionStatus::create([
                            'return_suggestion_id' => $returnSuggestion->id,
                            'suggestion_status_id' => 1,
                        ]);
                        return response()->json([
                            'message' => 'Return suggestion created successfully.',
                            'return_suggestion' => $returnSuggestion,
                        ]);
                    } catch (\Exception $e) {
                        return response()->json([
                            'message' => 'Failed to create return suggestion.',
                            'error' => $e->getMessage(),
                        ], 500);
                    }
                }
            }
        }
    }

    public function confirmReturnDetailSuggestion(Request $request, $borrowEventId)
    {
        $borrowEvent = BorrowEvent::find($borrowEventId);
        $returnDetail = ReturnDetail::where('borrow_event_id', $borrowEventId)->first();
        $returnDetailStatus = $returnDetail->returnDetailReturnStatus()->first();
        if (!$borrowEvent) {
            return response()->json(['message' => 'Borrow event not found.'], 404);
        } else {
            if (auth()->id() == $returnDetail->suggestions()->latest()->first()->suggested_by) {
                return response()->json([
                    'message' => 'You cannot confirm your own suggestion.'
                ], 403);
            } else {
                $validated = $request->validate([
                    'suggestion_status_id' => 'required|integer|exists:suggestion_statuses,id',
                ]);
                try {
                    $returnSuggestions = ReturnSuggestion::where('return_detail_id', $returnDetail->id)->get();
                    foreach ($returnSuggestions as $suggestion) {
                        $suggestionStatus = ReturnSuggestionStatus::where('return_suggestion_id', $suggestion->id)->first();
                        if ($suggestionStatus) {
                            $suggestionStatus->update([
                                'suggestion_status_id' => $validated['suggestion_status_id'],
                            ]);
                            $returnDetailStatus->update([
                                'return_status_id' => 3,
                            ]);
                        }
                    }
                    if ($validated['suggestion_status_id'] == 2) {
                        $latestSuggestion = $returnSuggestions->sortByDesc('created_at')->first();
                        if ($latestSuggestion) {
                            $returnDetail->update([
                                'return_time' => $latestSuggestion->suggested_time,
                                'return_location' => $latestSuggestion->suggested_location,
                            ]);
                        }
                    } else if ($validated['suggestion_status_id'] == 3) {
                        $returnDetailStatus->update([
                            'return_status_id' => 4,
                        ]);
                    }
                    return response()->json([
                        'message' => 'Return suggestion confirmed successfully.',
                        'return_detail' => $returnDetail,
                    ]);
                } catch (\Exception $e) {
                    return response()->json([
                        'message' => 'Failed to fetch return suggestions.',
                        'error' => $e->getMessage(),
                    ], 500);
                }
            }
        }
    }
}
