<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SharingController extends Controller
{
    public function members(Request $request, Recipient $recipient)
    {
        $recipient->checkOwner($request->user());

        return $recipient->members()->orderBy('users.id')->get(['users.id', 'users.name']);
    }

    public function remove(Request $request, Recipient $recipient, User $user)
    {
        $this->withRecipient($recipient, function (Recipient $locked) use ($request, $user) {
            $locked->checkOwner($request->user());
            abort_unless($locked->members()->detach($user->id), 404);
        });

        return response()->noContent();
    }

    public function leave(Request $request, Recipient $recipient)
    {
        $this->withRecipient($recipient, function (Recipient $locked) use ($request) {
            $locked->checkAccess($request->user());
            abort_if($locked->owner_id === $request->user()->id, 403);
            abort_unless($locked->members()->detach($request->user()->id), 404);
        });

        return response()->noContent();
    }

    public function current(Request $request, Recipient $recipient)
    {
        $recipient->checkOwner($request->user());

        return response()->json($this->active()->where('recipient_id', $recipient->id)->first(['expires_at']));
    }

    public function issue(Request $request, Recipient $recipient)
    {
        return $this->withRecipient($recipient, function (Recipient $locked) use ($request) {
            $locked->checkOwner($request->user());
            $now = now();
            $expires = $now->copy()->addDays(7);
            $token = bin2hex(random_bytes(32));
            $this->active()->where('recipient_id', $locked->id)->update(['revoked_at' => $now, 'updated_at' => $now]);
            DB::table('recipient_invitations')->insert([
                'recipient_id' => $locked->id,
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expires,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return response()->json(['token' => $token, 'expires_at' => $expires], 201)
                ->header('Cache-Control', 'private, no-store');
        });
    }

    public function revoke(Request $request, Recipient $recipient)
    {
        $this->withRecipient($recipient, function (Recipient $locked) use ($request) {
            $locked->checkOwner($request->user());
            $this->active()->where('recipient_id', $locked->id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        });

        return response()->noContent();
    }

    public function preview(Request $request)
    {
        $invite = $this->findInvite($request);
        $recipient = Recipient::findOrFail($invite->recipient_id);

        return response()->json(['recipient_name' => $recipient->name, 'expires_at' => $invite->expires_at])
            ->header('Cache-Control', 'private, no-store');
    }

    public function accept(Request $request)
    {
        $invite = $this->findInvite($request);
        $recipient = Recipient::findOrFail($invite->recipient_id);

        return $this->withRecipient($recipient, function (Recipient $locked) use ($invite, $request) {
            $user = $request->user();
            abort_if($locked->owner_id === $user->id || $locked->members()->whereKey($user->id)->exists(), 409, 'すでにこの相手の履歴を閲覧できます。');
            $now = now();
            $updated = $this->active()->where('id', $invite->id)->update(['used_at' => $now, 'updated_at' => $now]);
            abort_unless($updated === 1, 404, '有効な招待が見つかりません。');
            $locked->members()->attach($user->id, ['joined_at' => $now]);

            return response()->json(['recipient_id' => $locked->id], 201);
        });
    }

    private function findInvite(Request $request): object
    {
        $token = $request->validate(['token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']], [
            'token.required' => '招待リンクが必要です。',
            'token.regex' => '招待リンクが正しくありません。',
        ])['token'];
        $invite = $this->active()->where('token_hash', hash('sha256', $token))->first();
        abort_unless($invite, 404, '有効な招待が見つかりません。');

        return $invite;
    }

    private function active(): Builder
    {
        return DB::table('recipient_invitations')->whereNull('used_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    private function withRecipient(Recipient $recipient, callable $action): mixed
    {
        return DB::transaction(fn () => $action(Recipient::query()->lockForUpdate()->findOrFail($recipient->id)));
    }
}
