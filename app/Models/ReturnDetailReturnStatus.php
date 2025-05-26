<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnDetailReturnStatus extends Model
{
    use HasFactory;

    protected $table = 'return_detail_return_status';

    protected $fillable = [
        'return_detail_id',
        'return_status_id',
    ];

    public function returnDetail()
    {
        return $this->belongsTo(ReturnDetail::class);
    }

    public function returnStatus()
    {
        return $this->belongsTo(ReturnStatus::class);
    }
}
