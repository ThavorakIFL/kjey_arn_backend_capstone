<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReturnStatus extends Model
{
    use HasFactory;

    protected $table = 'return_statuses';

    protected $fillable = [
        'status',
    ];

    public function returnDetailReturnStatuses()
    {
        return $this->hasMany(ReturnDetailReturnStatus::class);
    }
}
