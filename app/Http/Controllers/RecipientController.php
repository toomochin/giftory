<?php

namespace App\Http\Controllers;

use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecipientController extends Controller
{
    public function index(Request $request)
    {
        $id = $request->user()->id;

        // 自分で登録した相手と、共有に参加中の相手を一覧に表示する。
        return Recipient::query()->where('owner_id', $id)
            ->orWhereHas('members', fn ($query) => $query->whereKey($id))
            ->withExists('members as is_shared')
            ->orderBy('name')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $recipient = new Recipient($this->attributes($request));
        $recipient->owner_id = $request->user()->id;
        $recipient->save();

        return response()->json($recipient, 201);
    }

    public function show(Request $request, Recipient $recipient)
    {
        $recipient->checkAccess($request->user());

        return $recipient;
    }

    public function update(Request $request, Recipient $recipient)
    {
        $recipient->checkOwner($request->user());
        $recipient->update($this->attributes($request));

        return $recipient;
    }

    public function destroy(Request $request, Recipient $recipient)
    {
        // 履歴の追加や共有への参加と競合しないよう、相手行をロックしてから削除条件を確かめる。
        DB::transaction(function () use ($request, $recipient) {
            $locked = Recipient::query()->lockForUpdate()->findOrFail($recipient->id);
            $locked->checkOwner($request->user());
            // 履歴や共有参加者を取り残さないよう、どちらかが存在する場合は409を返す。
            abort_if($locked->gifts()->exists() || $locked->members()->exists(), 409, '贈り物や参加者がいる相手は削除できません。');
            $locked->delete();
        });

        return response()->noContent();
    }

    private function attributes(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relationship' => ['required', 'string', 'max:255'],
            'preferences' => ['nullable', 'string', 'max:4000'],
        ], [
            'name.required' => '名前またはニックネームを入力してください。',
            'relationship.required' => '関係性を入力してください。',
            'name.max' => '名前は255文字以内で入力してください。',
            'relationship.max' => '関係性は255文字以内で入力してください。',
            'preferences.max' => '好みは4000文字以内で入力してください。',
        ]);
    }
}
