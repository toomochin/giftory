<?php

namespace Database\Seeders;

use App\Models\Gift;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoSeeder requires APP_ENV=local.');
        }

        DB::transaction(function () {
            $owner = $this->account('demo-owner@giftory.test', 'デモ管理者');
            $member = $this->account('demo-member@giftory.test', 'デモ参加者');

            $family = Recipient::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'name' => 'ゆい（共有デモ）'],
                ['relationship' => '家族', 'preferences' => '水族館と焼き菓子が好き'],
            );
            $friend = Recipient::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'name' => 'はる（個人用デモ）'],
                ['relationship' => '友人', 'preferences' => 'コーヒーと読書が好き'],
            );
            DB::table('recipient_members')->insertOrIgnore(['recipient_id' => $family->id, 'user_id' => $member->id, 'joined_at' => now()]);

            $this->gift($family, $owner, '水族館のペアチケット', '2025-12-20', 'デモ管理者', '誕生日', 5000, 'ペンギンを見られて喜んでいた。');
            $this->gift($family, $member, '焼き菓子の詰め合わせ', '2026-05-10', 'デモ参加者', '母の日', 2800, 'フィナンシェが特に好評だった。');
            $this->gift($family, $owner, 'ガラスの花瓶', '2026-09-13', 'デモ管理者', '記念日', 3200, null);
            $this->gift($friend, $owner, 'ドリップコーヒーセット', '2026-02-14', 'デモ管理者', 'バレンタイン', 2400, '深煎りが気に入ったそう。');
            $this->gift($friend, $owner, '布製のブックカバー', '2026-07-18', 'デモ管理者', '誕生日', 1800, '通勤中の読書に使ってくれている。');
            $this->seedHistory($family, $owner, $member);
            $this->seedHistory($friend, $owner);
        });
    }

    private function seedHistory(Recipient $recipient, User $owner, ?User $member = null): void
    {
        $items = [
            ['文庫本', 'お土産'],
            ['マグカップ', '誕生日'],
            ['ハンドタオル', 'お礼'],
            ['ドリップバッグ', '記念日'],
            ['チョコレート', 'バレンタイン'],
            ['クッキー缶', 'クリスマス'],
            ['紅茶セット', 'お祝い'],
            ['靴下', 'その他'],
            ['入浴剤', 'お礼'],
            ['ジャムセット', 'お土産'],
            ['花束', '母の日'],
            ['革のしおり', '誕生日'],
            ['スープセット', 'お祝い'],
            ['ノート', '記念日'],
            ['ポーチ', 'お土産'],
            ['はちみつ', 'お礼'],
            ['コーヒー豆', '父の日'],
            ['焼き菓子', 'ホワイトデー'],
            ['ミニ観葉植物', 'お祝い'],
            ['映画チケット', '誕生日'],
        ];
        $reactions = ['気に入って使ってくれている。', 'その場で喜んでくれた。', 'お礼のメッセージをもらった。', null];

        foreach ($items as $index => [$name, $event]) {
            $author = $member !== null && $index % 2 === 1 ? $member : $owner;
            $date = sprintf('2024-%02d-%02d', intdiv($index, 2) + 1, $index % 2 === 0 ? 8 : 23);
            $direction = intdiv($index, 2) % 2 === 0 ? 'given' : 'received';
            $price = 1000 + $index * 150;
            $reaction = $reactions[$index % count($reactions)];

            $gift = $this->gift($recipient, $author, $name, $date, $author->name, $event, $price, $reaction);
            // Convert older demo rows only if their original seeded fields are intact.
            if ($direction === 'received' && $gift->direction === 'given' && $gift->giver === $author->name
                && $gift->event === $event && $gift->price === $price && $gift->reaction === $reaction && $gift->photo_path === null) {
                $gift->update([
                    'direction' => 'received',
                    'giver' => $recipient->name,
                    'reaction' => $reaction === null ? null : '受け取ってうれしかった。',
                ]);
            }
        }
    }

    private function account(string $email, string $name): User
    {
        $password = 'GiftoryDemo2026!';
        $user = User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => $password]);

        if ($user->name !== $name || ! Hash::check($password, $user->password)) {
            throw new RuntimeException("Demo account address is already in use: {$email}");
        }

        return $user;
    }

    private function gift(Recipient $recipient, User $author, string $name, string $date, string $giver, string $event, int $price, ?string $reaction): Gift
    {
        $gift = $recipient->gifts()->where('created_by', $author->id)->firstOrNew(['name' => $name, 'gifted_on' => $date]);
        if ($gift->exists) {
            return $gift;
        }

        $gift->fill(['giver' => $giver, 'event' => $event, 'price' => $price, 'reaction' => $reaction]);
        $gift->created_by = $author->id;
        $gift->save();

        return $gift;
    }
}
