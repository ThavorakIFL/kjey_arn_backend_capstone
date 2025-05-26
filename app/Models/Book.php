<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Book extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'title',
        'author',
        'condition',
        'description',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pictures(): HasMany
    {
        return $this->hasMany(BookPicture::class);
    }

    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class, 'book_genre', 'book_id', 'genre_id');
    }

    public function availability(): HasOne
    {
        return $this->hasOne(BookAvailability::class);
    }
}

    // public function getAvailabilityStatusAttribute()
    // {
    //     if (!$this->availability) {
    //         return null;
    //     }

    //     return match ($this->availability->availability) {
    //         1 => 'Unavailable',
    //         2 => 'Available',
    //         3 => 'Suspended',
    //         default => 'Unknown',
    //     };
    // }
