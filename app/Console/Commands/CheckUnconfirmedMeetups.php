<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\BorrowEvent;

class CheckUnconfirmedMeetups extends Command
{
    protected $signature = 'meetups:check-unconfirmed';
    protected $description = 'Cancel Borrow Request due to unconfirmed meetups';

    public function handle()
    {
        $this->info("Current time: " . Carbon::now());
        $this->info("Looking for unconfirmed meetups...");
        Log::info("CheckUnconfirmedMeetups started at " . Carbon::now());
        Log::info("Checking for unconfirmed meetups...");
        try {
            // Query using proper relationships
            $unconfirmedBorrowRequests = BorrowEvent::whereHas('meetUpDetail', function ($query) {
                $query->whereHas('meetUpDetailMeetUpStatus', function ($statusQuery) {
                    $statusQuery->where('meet_up_status_id', 1); // Unconfirmed status
                })
                    ->where('start_date', '<=', Carbon::now());
            })->get();

            $cancelledCount = 0;

            $allMeetups = DB::table('meet_up_details')->count();
            $this->info("Total meetups in database: " . $allMeetups);
            Log::info("Total meetups in database: " . $allMeetups);
            foreach ($unconfirmedBorrowRequests as $borrowEvent) {
                try {
                    DB::beginTransaction();
                    // Update borrow status
                    $borrowEvent->borrowStatus()->updateOrCreate(
                        ['borrow_event_id' => $borrowEvent->id],
                        ['borrow_status_id' => 6] // Cancelled
                    );
                    // Add cancellation reason
                    $borrowEvent->borrowEventCancelReason()->create([
                        'reason' => 'Meetup was not confirmed within the allowed time frame.'
                    ]);

                    if ($borrowEvent->meetUpDetail) {
                        $borrowEvent->meetUpDetail->meetUpDetailMeetUpStatus()->update(
                            ['meet_up_status_id' => 3]
                        );
                    }
                    // Update book availability (assuming you have this relationship)
                    if ($borrowEvent->book) {
                        $this->info("Updating book availability...");
                        $borrowEvent->book->availability()->updateOrCreate(
                            ['book_id' => $borrowEvent->book->id],
                            ['availability_id' => 1] // Assuming 1 is the ID for available status
                        );
                        $this->info("Book Availability updated Successfully");
                    }
                    DB::commit();
                    $this->info("Cancelled request ID: {$borrowEvent->id}");
                    $cancelledCount++;
                } catch (\Exception $e) {
                    DB::rollBack();

                    Log::error("Failed to cancel borrow event ID: {$borrowEvent->id}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);

                    $this->error("Failed to cancel request ID: {$borrowEvent->id}");
                }
            }

            $this->info("Successfully cancelled {$cancelledCount} out of {$unconfirmedBorrowRequests->count()} requests");

            Log::info("CheckUnconfirmedMeetups completed", [
                'total_found' => $unconfirmedBorrowRequests->count(),
                'cancelled' => $cancelledCount
            ]);
        } catch (\Exception $e) {
            Log::error('CheckUnconfirmedMeetups command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->error('Command failed: ' . $e->getMessage());
            return 1; // Return error code
        }

        return 0; // Success
    }
}
