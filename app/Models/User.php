<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;
    protected $fillable = [
        'name',
        'email',
        'sub',
        'picture',
        'bio',
    ];

    public function borrowedEvents()
    {
        return $this->hasMany(BorrowEvent::class, 'borrower_id');
    }

    public function lentEvents()
    {
        return $this->hasMany(BorrowEvent::class, 'lender_id');
    }

    // public function changeReturnDetails()
    // {
    //     return $this->hasMany(ChangeReturnDetail::class, 'changed_by_user_id');
    // }
}
