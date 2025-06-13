<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowEventReport extends Model
{
    use HasFactory;
    protected $table = 'borrow_event_report';
    protected $fillable = [
        'borrow_event_id',
        'reported_by',
        'reason',
    ];

    public function borrowEvent()
    {
        return $this->belongsTo(BorrowEvent::class);
    }
    public function reporter()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
