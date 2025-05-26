<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnDetail extends Model
{
    use HasFactory;

    protected $table = 'return_details';

    protected $fillable = [
        'borrow_event_id',
        'return_date',
        'return_time',
        'return_location',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }
    public function returnStatus()
    {
        return $this->belongsToMany(ReturnStatus::class, 'return_detail_return_status', 'return_detail_id', 'return_status_id')->withTimestamps();
    }
    public function returnDetailReturnStatus()
    {
        return $this->hasOne(ReturnDetailReturnStatus::class);
    }
    public function suggestions()
    {
        return $this->hasMany(ReturnSuggestion::class);
    }
}
