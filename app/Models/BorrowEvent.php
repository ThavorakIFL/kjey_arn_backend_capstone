<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowEvent extends Model
{
    use HasFactory;
    protected $table = 'borrow_events';

    protected $fillable = [
        'borrower_id',
        'lender_id',
        'book_id',
    ];
    public function book()
    {
        return $this->belongsTo(Book::class);
    }
    public function borrower()
    {
        return $this->belongsTo(User::class, 'borrower_id');
    }
    public function lender()
    {
        return $this->belongsTo(User::class, 'lender_id');
    }
    public function borrowStatus()
    {
        return $this->hasOne(BorrowEventBorrowStatus::class);
    }
    public function meetUpDetail()
    {
        return $this->hasOne(MeetUpDetail::class);
    }
    public function returnDetail()
    {
        return $this->hasOne(ReturnDetail::class);
    }
    public function borrowEventRejectReason()
    {
        return $this->hasOne(BorrowEventRejectReason::class);
    }
    public function borrowEventCancelReason()
    {
        return $this->hasOne(BorrowEventCancelReason::class);
    }
    public function borrowEventReport()
    {
        return $this->hasOne(BorrowEventReport::class);
    }
}
