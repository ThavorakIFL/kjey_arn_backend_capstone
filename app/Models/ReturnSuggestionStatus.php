<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnSuggestionStatus extends Model
{
    use HasFactory;

    protected $table = 'return_suggestion_status';
    protected $fillable = [
        'return_suggestion_id',
        'suggestion_status_id',
    ];
    public function returnSuggestion()
    {
        return $this->belongsTo(ReturnSuggestion::class);
    }
    public function suggestionStatus()
    {
        return $this->belongsTo(SuggestionStatus::class);
    }
}
