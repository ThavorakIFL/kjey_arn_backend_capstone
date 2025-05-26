<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowEventRejectReason extends Model
{
    use HasFactory;

    protected $fillable = [
        'borrow_event_id',
        'rejected_by',
        'reason',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }
    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
