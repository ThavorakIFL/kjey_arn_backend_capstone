<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookAvailability extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'book_id',
        'availability_id',
    ];

    /**
     * Get the book that owns the availability.
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    public function availability(): BelongsTo
    {
        return $this->belongsTo(Availability::class);
    }
}
