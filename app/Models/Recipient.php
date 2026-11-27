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
        // 管理者と現在の参加者に閲覧を許可し、それ以外には相手の存在を知らせない。
        abort_unless($this->owner_id === $user->id || $this->members()->whereKey($user->id)->exists(), 404);
    }

    public function checkOwner(User $user): void
    {
        $this->checkAccess($user);
        // 閲覧できる参加者にも、相手の設定を変更する権限は与えない。
        abort_unless($this->owner_id === $user->id, 403);
    }
}
