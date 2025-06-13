<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetUpDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_event_id',
        'start_date',
        'end_date',
        'final_time',
        'final_location',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }

    public function meetUpStatus()
    {
        return $this->belongsToMany(MeetUpStatus::class, 'meet_up_detail_meet_up_status', 'meet_up_detail_id', 'meet_up_status_id')->withTimestamps();
    }
    public function meetUpDetailMeetUpStatus()
    {
        return $this->hasOne(MeetUpDetailMeetUpStatus::class);
    }
    public function suggestions()
    {
        return $this->hasMany(MeetUpSuggestion::class)->orderBy('id', 'asc');
    }
    public function latestSuggestion()
    {
        return $this->hasOne(MeetUpSuggestion::class)->latestOfMany();
    }
}
