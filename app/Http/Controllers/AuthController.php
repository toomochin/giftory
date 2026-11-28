<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
        ], [
            'name.required' => '名前を入力してください。',
            'name.max' => '名前は255文字以内で入力してください。',
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.max' => 'メールアドレスは255文字以内で入力してください。',
            'email.unique' => 'このメールアドレスは登録済みです。',
            'password.required' => 'パスワードを入力してください。',
            'password.min' => 'パスワードは8文字以上で入力してください。',
            'password.max' => 'パスワードは72文字以内で入力してください。',
            'password.confirmed' => '確認用パスワードが一致しません。',
        ]);
        // 文字数に加えて、bcryptが扱える72バイト以内に収める。
        if (strlen($data['password']) > 72) {
            throw ValidationException::withMessages(['password' => 'パスワードは72バイト以内で入力してください。']);
        }

        // パスワードはUserモデルのhashedキャストで保存時にハッシュ化する。
        // 登録直後にログインさせ、ログイン前のセッションIDがそのまま使われないよう更新する。
        $user = User::create($data);
        Auth::login($user);
        $request->session()->regenerate();

        // ウェルカムメールのSMTPエラーと、会員登録の結果は別に扱う。
        try {
            Mail::raw("Giftoryへのご登録ありがとうございます。\n\nログイン: ".config('app.url'), fn ($message) => $message
                ->to($user->email)->subject('Giftoryへようこそ'));
        } catch (TransportExceptionInterface $exception) {
            report($exception);
        }

        return response()->json($user->only('id', 'name', 'email'), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $this->normalizeEmail($request);
        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ], [
            'email.required' => 'メールアドレスを入力してください。',
            'email.email' => '正しいメールアドレスを入力してください。',
            'email.max' => 'メールアドレスは255文字以内で入力してください。',
            'password.required' => 'パスワードを入力してください。',
        ]);

        if (strlen($credentials['password']) > 72 || ! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'メールアドレスまたはパスワードが正しくありません。']);
        }
        // 認証成功後は新しいセッションIDを使い、ブラウザに表示する会員情報を返す。
        $request->session()->regenerate();

        return $this->me($request);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($request->user()->only('id', 'name', 'email'));
    }

    public function logout(Request $request): JsonResponse
    {
        // ログアウト前のセッションとCSRFトークンを破棄する。
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(null, 204);
    }

    private function normalizeEmail(Request $request): void
    {
        // 登録時とログイン時に同じ形式でメールアドレスを照合する。
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower($request->input('email'))]);
        }
    }
}
