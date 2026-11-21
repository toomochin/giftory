<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Recipient extends Model
{
    protected $fillable = ['name', 'relationship', 'preferences'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'recipient_members')->withPivot('joined_at');
    }

    public function gifts(): HasMany
    {
        return $this->hasMany(Gift::class);
    }

    public function checkAccess(User $user): void
    {
        abort_unless($this->owner_id === $user->id || $this->members()->whereKey($user->id)->exists(), 404);
    }

    public function checkOwner(User $user): void
    {
        $this->checkAccess($user);
        abort_unless($this->owner_id === $user->id, 403);
    }
}
