<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BorrowEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class ActivityController extends Controller
{
    public function index()
    {
        $userId = auth()->id();
        $activities = $this->getUserActivities($userId, 10);
        $formattedActivities = $activities->map(function ($activity) {
            return [
                'id' => $activity['id'],
                'type' => $activity['type'],
                'message' => $activity['message'],
                'actor_name' => $activity['actor_name'],
                'book_title' => $activity['book_title'],
                'created_at' => $activity['timestamp'],
                'time_ago' => $activity['timestamp']->diffForHumans(),
                'borrow_event_id' => $activity['borrow_event_id'],
                'additional_data' => $activity['additional_data']
            ];
        });
        return response()->json([
            'success' => true,
            'activities' => $formattedActivities
        ]);
    }

    private function getUserActivities(int $userId, int $limit = 10): Collection
    {
        $activities = collect();
        $borrowEvents = BorrowEvent::with([
            'borrower',
            'lender',
            'book',
            'borrowStatus',
            'meetUpDetail',
            'meetUpDetail.suggestions.user',
            'meetUpDetail.suggestions.suggestionStatus',
            'meetUpDetail.meetUpDetailMeetUpStatus',
            'borrowEventRejectReason',
            'borrowEventCancelReason'
        ])
            ->where(function ($query) use ($userId) {
                $query->where('borrower_id', $userId)
                    ->orWhere('lender_id', $userId);
            })->orderBy('created_at', 'desc')
            ->limit($limit * 2)
            ->get();

        foreach ($borrowEvents as $borrowEvent) {
            $activities = $activities->merge($this->extractActivitiesFromBorrowEvent($borrowEvent, $userId));
        }

        return $activities->sortByDesc('timestamp')->take($limit)->values();
    }

    private function extractActivitiesFromBorrowEvent(BorrowEvent $borrowEvent, int $currentUserId): Collection
    {
        $activities = collect();

        // 1. Borrow Request Created
        if ($borrowEvent->lender_id === $currentUserId) {
            $activities->push([
                'id' => "borrow_request_{$borrowEvent->id}",
                'type' => 'borrow_request',
                'message' => "{$borrowEvent->borrower->name} wants to borrow your book \"{$borrowEvent->book->title}\"",
                'actor_name' => $borrowEvent->borrower->name,
                'target_user_id' => $borrowEvent->lender_id,
                'book_title' => $borrowEvent->book->title,
                'borrow_event_id' => $borrowEvent->id,
                'timestamp' => $borrowEvent->created_at,
                'additional_data' => []
            ]);
        }

        // 2. Borrow Accepted (Status = 2)
        if ($borrowEvent->borrowStatus && $borrowEvent->borrowStatus->borrow_status_id == 2 && $borrowEvent->borrower_id === $currentUserId) {
            $activities->push([
                'id' => "borrow_accepted_{$borrowEvent->id}",
                'type' => 'borrow_accepted',
                'message' => "{$borrowEvent->lender->name} accepted your borrow request for \"{$borrowEvent->book->title}\"",
                'actor_name' => $borrowEvent->lender->name,
                'target_user_id' => $borrowEvent->borrower_id,
                'book_title' => $borrowEvent->book->title,
                'borrow_event_id' => $borrowEvent->id,
                'timestamp' => $borrowEvent->borrowStatus->updated_at ?? $borrowEvent->updated_at,
                'additional_data' => [
                    'location' => $borrowEvent->meetUpDetail->final_location ?? null,
                    'time' => $borrowEvent->meetUpDetail->final_time ?? null,
                ]
            ]);
        }

        // 3. Borrow Rejected
        if ($borrowEvent->borrowEventRejectReason && $borrowEvent->borrower_id === $currentUserId) {
            $activities->push([
                'id' => "borrow_rejected_{$borrowEvent->id}",
                'type' => 'borrow_rejected',
                'message' => "{$borrowEvent->lender->name} rejected your borrow request for \"{$borrowEvent->book->title}\"",
                'actor_name' => $borrowEvent->lender->name,
                'target_user_id' => $borrowEvent->borrower_id,
                'book_title' => $borrowEvent->book->title,
                'borrow_event_id' => $borrowEvent->id,
                'timestamp' => $borrowEvent->borrowEventRejectReason->created_at,
                'additional_data' => ['reason' => $borrowEvent->borrowEventRejectReason->reason]
            ]);
        }

        // 4. Cancellation
        if ($borrowEvent->borrowEventCancelReason) {
            $cancelReason = $borrowEvent->borrowEventCancelReason;
            $targetUserId = ($cancelReason->cancelled_by === $borrowEvent->borrower_id)
                ? $borrowEvent->lender_id : $borrowEvent->borrower_id;

            if ($targetUserId === $currentUserId) {
                $actorName = ($cancelReason->cancelled_by === $borrowEvent->borrower_id)
                    ? $borrowEvent->borrower->name : $borrowEvent->lender->name;

                $activities->push([
                    'id' => "borrow_cancelled_{$borrowEvent->id}",
                    'type' => 'borrow_cancelled',
                    'message' => "{$actorName} cancelled the borrow request for \"{$borrowEvent->book->title}\"",
                    'actor_name' => $actorName,
                    'target_user_id' => $targetUserId,
                    'book_title' => $borrowEvent->book->title,
                    'borrow_event_id' => $borrowEvent->id,
                    'timestamp' => $cancelReason->created_at,
                    'additional_data' => ['reason' => $cancelReason->reason]
                ]);
            }
        }

        // 5. Meetup Details Set (Lender sets initial meetup)
        if ($borrowEvent->meetUpDetail && $borrowEvent->meetUpDetail->final_location && $borrowEvent->borrower_id === $currentUserId) {
            $activities->push([
                'id' => "meetup_set_{$borrowEvent->id}",
                'type' => 'meetup_set',
                'message' => "{$borrowEvent->lender->name} set meetup details for \"{$borrowEvent->book->title}\"",
                'actor_name' => $borrowEvent->lender->name,
                'target_user_id' => $borrowEvent->borrower_id,
                'book_title' => $borrowEvent->book->title,
                'borrow_event_id' => $borrowEvent->id,
                'timestamp' => $borrowEvent->meetUpDetail->updated_at,
                'additional_data' => [
                    'location' => $borrowEvent->meetUpDetail->final_location,
                    'time' => $borrowEvent->meetUpDetail->final_time,
                ]
            ]);
        }

        // 6. Borrower Confirms Meetup Details
        if ($borrowEvent->meetUpDetail && $borrowEvent->meetUpDetail->meetUpDetailMeetUpStatus) {
            $meetupStatus = $borrowEvent->meetUpDetail->meetUpDetailMeetUpStatus;

            // Status 2 = Confirmed by borrower
            if ($meetupStatus->meet_up_status_id == 2 && $borrowEvent->lender_id === $currentUserId) {
                $activities->push([
                    'id' => "meetup_confirmed_{$borrowEvent->id}",
                    'type' => 'meetup_confirmed',
                    'message' => "{$borrowEvent->borrower->name} confirmed the meetup details for \"{$borrowEvent->book->title}\"",
                    'actor_name' => $borrowEvent->borrower->name,
                    'target_user_id' => $borrowEvent->lender_id,
                    'book_title' => $borrowEvent->book->title,
                    'borrow_event_id' => $borrowEvent->id,
                    'timestamp' => $meetupStatus->updated_at,
                    'additional_data' => [
                        'location' => $borrowEvent->meetUpDetail->final_location,
                        'time' => $borrowEvent->meetUpDetail->final_time,
                    ]
                ]);
            }
        }

        // 7. Meetup Suggestions by Borrower
        if ($borrowEvent->meetUpDetail && $borrowEvent->meetUpDetail->suggestions) {
            foreach ($borrowEvent->meetUpDetail->suggestions as $suggestion) {
                // Show suggestion to the lender when borrower suggests
                if ($suggestion->suggested_by === $borrowEvent->borrower_id && $borrowEvent->lender_id === $currentUserId) {
                    $activities->push([
                        'id' => "meetup_suggested_{$suggestion->id}",
                        'type' => 'meetup_suggested',
                        'message' => "{$suggestion->user->name} suggested new meetup details for \"{$borrowEvent->book->title}\"",
                        'actor_name' => $suggestion->user->name,
                        'target_user_id' => $borrowEvent->lender_id,
                        'book_title' => $borrowEvent->book->title,
                        'borrow_event_id' => $borrowEvent->id,
                        'timestamp' => $suggestion->created_at,
                        'additional_data' => [
                            'suggested_location' => $suggestion->suggested_location,
                            'suggested_time' => $suggestion->suggested_time,
                            'reason' => $suggestion->suggested_reason,
                        ]
                    ]);
                }

                // 8. Lender Accepts/Rejects Meetup Suggestion (CORRECTED!)
                if ($suggestion->suggestionStatus && $suggestion->suggestionStatus->isNotEmpty()) {
                    // Get the latest status for this suggestion
                    $latestStatus = $suggestion->suggestionStatus->last();

                    if ($latestStatus->suggestion_status_id == 2) {
                        // Status 2 = Accepted
                        if ($suggestion->suggested_by === $borrowEvent->borrower_id && $borrowEvent->borrower_id === $currentUserId) {
                            $activities->push([
                                'id' => "meetup_suggestion_accepted_{$suggestion->id}",
                                'type' => 'meetup_suggestion_accepted',
                                'message' => "{$borrowEvent->lender->name} accepted your meetup suggestion for \"{$borrowEvent->book->title}\"",
                                'actor_name' => $borrowEvent->lender->name,
                                'target_user_id' => $borrowEvent->borrower_id,
                                'book_title' => $borrowEvent->book->title,
                                'borrow_event_id' => $borrowEvent->id,
                                'timestamp' => $latestStatus->updated_at,
                                'additional_data' => [
                                    'accepted_location' => $suggestion->suggested_location,
                                    'accepted_time' => $suggestion->suggested_time,
                                    'original_reason' => $suggestion->suggested_reason,
                                ]
                            ]);
                        }
                    } elseif ($latestStatus->suggestion_status_id == 3) {
                        // Status 3 = Rejected
                        if ($suggestion->suggested_by === $borrowEvent->borrower_id && $borrowEvent->borrower_id === $currentUserId) {
                            $activities->push([
                                'id' => "meetup_suggestion_rejected_{$suggestion->id}",
                                'type' => 'meetup_suggestion_rejected',
                                'message' => "{$borrowEvent->lender->name} declined your meetup suggestion for \"{$borrowEvent->book->title}\"",
                                'actor_name' => $borrowEvent->lender->name,
                                'target_user_id' => $borrowEvent->borrower_id,
                                'book_title' => $borrowEvent->book->title,
                                'borrow_event_id' => $borrowEvent->id,
                                'timestamp' => $latestStatus->updated_at,
                                'additional_data' => [
                                    'rejected_location' => $suggestion->suggested_location,
                                    'rejected_time' => $suggestion->suggested_time,
                                    'original_reason' => $suggestion->suggested_reason,
                                ]
                            ]);
                        }
                    }
                }
            }
        }

        // 10. Book Return Confirmed (NEW!)
        if ($borrowEvent->borrowStatus && $borrowEvent->borrowStatus->borrow_status_id == 5) {
            // Status 5 = Completed/Returned
            if ($borrowEvent->borrower_id === $currentUserId) {
                $activities->push([
                    'id' => "book_returned_{$borrowEvent->id}",
                    'type' => 'book_returned',
                    'message' => "{$borrowEvent->lender->name} confirmed receiving the returned book \"{$borrowEvent->book->title}\"",
                    'actor_name' => $borrowEvent->lender->name,
                    'target_user_id' => $borrowEvent->borrower_id,
                    'book_title' => $borrowEvent->book->title,
                    'borrow_event_id' => $borrowEvent->id,
                    'timestamp' => $borrowEvent->borrowStatus->updated_at,
                    'additional_data' => []
                ]);
            }
        }

        return $activities;
    }
}
