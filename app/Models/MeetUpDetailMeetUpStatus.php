<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetUpDetailMeetUpStatus extends Model
{
    use HasFactory;

    protected $table = 'meet_up_detail_meet_up_status';

    protected $fillable = [
        'meet_up_detail_id',
        'meet_up_status_id',
    ];
    public function meetUpDetail()
    {
        return $this->belongsTo(MeetUpDetail::class);
    }

    public function meetUpStatus()
    {
        return $this->belongsTo(MeetUpStatus::class);
    }
}
