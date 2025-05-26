<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetUpSuggestionStatus extends Model
{
    use HasFactory;
    protected $table = 'meet_up_suggestion_status';
    protected $fillable = [
        'meet_up_suggestion_id',
        'suggestion_status_id',
    ];
    public function meetUpSuggestion()
    {
        return $this->belongsTo(MeetUpSuggestion::class);
    }
    public function suggestionStatus()
    {
        return $this->belongsTo(SuggestionStatus::class);
    }
}
