<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BorrowStatus extends Model
{
    use HasFactory;

    protected $table = 'borrow_statuses';

    protected $fillable = [
        'status',
    ];

    public function borrowEventBorrowStatus()
    {
        return $this->hasMany(BorrowEventBorrowStatus::class);
    }
}
