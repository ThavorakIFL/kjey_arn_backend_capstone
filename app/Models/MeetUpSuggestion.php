<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetUpSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'meet_up_detail_id',
        'suggested_by',
        'suggested_time',
        'suggested_location',
        'suggested_reason',
    ];

    public function meetUpDetail()
    {
        return $this->belongsTo(MeetUpDetail::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class, 'suggested_by');
    }
    public function suggestionStatus()
    {
        return $this->hasMany(MeetUpSuggestionStatus::class);
    }
}
