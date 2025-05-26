<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuggestionStatus extends Model
{
    use HasFactory;

    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string, string>
     */
    protected $fillable = [
        'status',
    ];
    public function returnSuggestionStatuses()
    {
        return $this->hasMany(ReturnSuggestionStatus::class);
    }
    public function meetUpSuggestionStatuses()
    {
        return $this->hasMany(MeetUpSuggestionStatus::class);
    }
}
