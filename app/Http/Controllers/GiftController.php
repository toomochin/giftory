<?php

namespace App\Http\Controllers;

use App\Models\Gift;
use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class GiftController extends Controller
{
    public function index(Request $request, Recipient $recipient)
    {
        $recipient->checkAccess($request->user());

        return $recipient->gifts()
            ->select('id', 'recipient_id', 'name', 'gifted_on', 'reaction', 'event', 'price')
            ->selectRaw('CASE WHEN photo_path IS NULL THEN 0 ELSE 1 END AS has_photo')
            ->withCasts(['has_photo' => 'boolean'])
            ->orderByDesc('gifted_on')->orderByDesc('id')->paginate(5);
    }

    public function store(Request $request, Recipient $recipient)
    {
        $recipient->checkAccess($request->user());
        $data = $this->attributes($request);
        $file = $this->upload($request);
        $path = $file?->store('gift-photos', 'local');
        abort_if($file && ! $path, 500, '写真を保存できませんでした。');

        try {
            $gift = DB::transaction(function () use ($request, $recipient, $data, $path) {
                $locked = Recipient::query()->lockForUpdate()->findOrFail($recipient->id);
                $locked->checkAccess($request->user());
                $gift = $locked->gifts()->make($data);
                $gift->created_by = $request->user()->id;
                $gift->photo_path = $path;
                $gift->save();

                return $gift;
            });
        } catch (\Throwable $error) {
            $this->removeFile($path);
            throw $error;
        }

        return response()->json($this->detail($gift), 201);
    }

    public function show(Request $request, Gift $gift)
    {
        $gift->recipient->checkAccess($request->user());

        return $this->detail($gift);
    }

    public function update(Request $request, Gift $gift)
    {
        return $this->mutate($request, $gift, function (Gift $locked) use ($request) {
            $locked->update($this->attributes($request));

            return $this->detail($locked);
        });
    }

    public function destroy(Request $request, Gift $gift)
    {
        $path = $this->mutate($request, $gift, function (Gift $locked) {
            $locked->delete();

            return $locked->photo_path;
        });
        $this->removeFile($path);

        return response()->noContent();
    }

    public function photo(Request $request, Gift $gift)
    {
        $gift->recipient->checkAccess($request->user());
        abort_unless($gift->photo_path && Storage::disk('local')->exists($gift->photo_path), 404);

        return Storage::disk('local')->response($gift->photo_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function replacePhoto(Request $request, Gift $gift)
    {
        $this->checkEditor($request, $gift, $gift->recipient);
        $file = $this->upload($request, true);
        $path = $file->store('gift-photos', 'local');
        abort_unless($path, 500, '写真を保存できませんでした。');

        try {
            $old = $this->mutate($request, $gift, fn (Gift $locked) => $this->setPhoto($locked, $path));
        } catch (\Throwable $error) {
            $this->removeFile($path);
            throw $error;
        }
        $this->removeFile($old);

        return response()->noContent();
    }

    public function deletePhoto(Request $request, Gift $gift)
    {
        $old = $this->mutate($request, $gift, fn (Gift $locked) => $this->setPhoto($locked, null));
        $this->removeFile($old);

        return response()->noContent();
    }

    private function mutate(Request $request, Gift $gift, callable $update): mixed
    {
        return DB::transaction(function () use ($request, $gift, $update) {
            $recipient = Recipient::query()->lockForUpdate()->findOrFail($gift->recipient_id);
            $locked = $recipient->gifts()->lockForUpdate()->findOrFail($gift->id);
            $this->checkEditor($request, $locked, $recipient);

            return $update($locked);
        });
    }

    private function checkEditor(Request $request, Gift $gift, Recipient $recipient): void
    {
        $recipient->checkAccess($request->user());
        abort_unless($gift->created_by === $request->user()->id, 403);
    }

    private function detail(Gift $gift): array
    {
        return $gift->load('recorder:id,name')->toArray() + ['has_photo' => (bool) $gift->photo_path];
    }

    private function setPhoto(Gift $gift, ?string $path): ?string
    {
        $old = $gift->photo_path;
        $gift->photo_path = $path;
        $gift->save();

        return $old;
    }

    private function removeFile(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }

    private function upload(Request $request, bool $required = false)
    {
        return $request->validate([
            'photo' => [$required ? 'required' : 'nullable', 'image', 'mimes:jpeg,png', 'max:2048'],
        ], [
            'photo.required' => '写真を選んでください。',
            'photo.image' => '画像ファイルを選んでください。',
            'photo.mimes' => '写真はJPEGまたはPNGを指定してください。',
            'photo.max' => '写真は2 MiB以下にしてください。',
        ])['photo'] ?? null;
    }

    private function attributes(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'gifted_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Asia/Tokyo')->toDateString()],
            'giver' => ['required', 'string', 'max:255'],
            'reaction' => ['nullable', 'string', 'max:4000'],
            'price' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'event' => ['nullable', 'string', 'max:255'],
        ], [
            'name.required' => '品物名を入力してください。',
            'giver.required' => '贈った人を入力してください。',
            'gifted_on.required' => '贈った日を入力してください。',
            'gifted_on.date_format' => '贈った日を年月日で入力してください。',
            'gifted_on.before_or_equal' => '贈った日は当日以前の日付を入力してください。',
            'price.integer' => '価格は整数で入力してください。',
            'price.min' => '価格は0円以上で入力してください。',
            'price.max' => '価格が上限を超えています。',
            '*.max' => '入力が長すぎます。',
        ]);
    }
}
