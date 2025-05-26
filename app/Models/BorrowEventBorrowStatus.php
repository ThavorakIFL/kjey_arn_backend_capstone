<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowEventBorrowStatus extends Model
{
    use HasFactory;

    protected $table = 'borrow_event_borrow_status';

    protected $fillable = [
        'borrow_event_id',
        'borrow_status_id',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }

    public function borrowStatus()
    {
        return $this->belongsTo(BorrowStatus::class);
    }
}
