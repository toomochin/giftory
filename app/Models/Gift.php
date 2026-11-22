<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gift extends Model
{
    protected $fillable = ['name', 'gifted_on', 'giver', 'reaction', 'price', 'event', 'direction'];

    protected $attributes = ['direction' => 'given'];

    protected $hidden = ['photo_path'];

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Recipient::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
