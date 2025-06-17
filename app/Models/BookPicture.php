<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookPicture extends Model
{
    use HasFactory;
    /** 
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'book_id',
        'picture',
        'order'
    ];
    /**
     * Get the book that owns the picture.
     */

    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }
}
