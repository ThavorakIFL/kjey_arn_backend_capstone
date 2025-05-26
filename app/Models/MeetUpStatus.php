<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetUpStatus extends Model
{
    use HasFactory;

    protected $table = 'meet_up_statuses';

    protected $fillable = ['status'];

    public function meetUpDetailMeetUpStatus()
    {
        return $this->hasMany(MeetUpDetailMeetUpStatus::class);
    }
}
