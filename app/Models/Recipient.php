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
        // 現在の参加状況をDBで確認するため、共有を解除された会員は次のリクエストから閲覧できない。
        // 管理者・参加者以外には、相手が存在しない場合と同じ404を返す。
        abort_unless($this->owner_id === $user->id || $this->members()->whereKey($user->id)->exists(), 404);
    }

    public function checkOwner(User $user): void
    {
        $this->checkAccess($user);
        // 閲覧は許可されるが管理操作は許可されない参加者に、403を返す。
        abort_unless($this->owner_id === $user->id, 403);
    }
}
