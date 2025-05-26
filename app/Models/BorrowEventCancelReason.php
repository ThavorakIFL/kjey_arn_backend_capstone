<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowEventCancelReason extends Model
{
    use HasFactory;
    protected $fillable = [
        'borrow_event_id',
        'cancelled_by',
        'reason',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }
    public function canceledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
