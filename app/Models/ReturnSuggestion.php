<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnSuggestion extends Model
{
    use HasFactory;

    protected $table = 'return_suggestions';
    protected $fillable = [
        'return_detail_id',
        'suggested_by',
        'suggested_time',
        'suggested_location',
    ];

    public function returnDetail()
    {
        return $this->belongsTo(ReturnDetail::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }

    public function suggestionStatuses()
    {
        return $this->hasMany(ReturnSuggestionStatus::class);
    }
}
