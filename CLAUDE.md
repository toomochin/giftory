# Giftory の開発ガイド（下書き）

## プロジェクトの目的

家族や友人に贈ったもの、もらったものを相手ごとに記録し、次の贈り物選びで品物とメモを見返せるようにする。共有は相手単位で行う。要件と試用の判定基準は [要件定義書](docs/要件定義書.md)、DBとAPIの仕様は [基本設計](docs/基本設計.md) を参照する。

## 構成と変更箇所

- PHP 8.3以上とLaravel 13でAPIを提供する。ルートは [routes/web.php](routes/web.php)、認証・履歴・共有の処理は [app/Http/Controllers](app/Http/Controllers) にある。
- 画面はVue 3とViteを使用する。主な画面は [frontend/src/App.vue](frontend/src/App.vue) にあり、ビルド結果は `public/app/` に出力される。
- 開発はSQLite、テストはインメモリSQLiteを使う。alwaysdataの公開環境ではMariaDBを使い、画面とAPIを同じHTTPS URLで配信する。開発用メールはMailpit、公開環境のメールはSMTPで扱う。

## 開発・確認

リポジトリのルートで実行する。初期設定、サンプルデータの投入、公開環境への反映は [README](README.md) を参照する。

```sh
docker compose up -d --build
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=:memory: -e MAIL_MAILER=array app php artisan test --fail-on-warning
```

Dockerを使わない環境で、依存関係を入れた後にテスト・書式・ビルドを確認する場合のコマンド。

```sh
php artisan test --fail-on-warning
vendor/bin/pint --test
npm --prefix frontend run build
```

## 変更時に確認すること

- 入力項目を変更するときは、Laravel側のバリデーションと、Vue側で表示する日本語のエラーメッセージをあわせて確認する。
- 認証はLaravelのセッションCookieとCSRF保護を使う。ログイン時にセッションIDを更新し、ログアウト時にセッションを無効化する。未ログインで `GET /api/me` を開くとJSONの `401` が返る。
- 履歴と写真は、相手の管理者と現在の参加者だけが閲覧できる。記録を編集・削除するには登録者の一致も必要。共有まわりを変更したら、別アカウントで参加、閲覧、解除後の閲覧不可、使用済みリンクの再利用不可を確認する。
- 招待は発行から7日間・1回限り。再発行時は以前の有効な招待を無効化する。DBにはトークンのハッシュを保存し、写真は非公開ストレージに置く。
- `.env`、実際の贈答記録、招待リンクをコミットや作業ログに含めない。テストには架空データを使う。

## Claude Code と開発者の分担

- Claude Codeは調査、設計案、コードやテストの下書き、レビュー観点の整理に使う。変更時は説明とともに、実施した確認と結果を残す。
- 要件の採否、技術選定、認証・共有権限、公開の判断は開発者が持つ。差分とテスト結果を確認し、試用記録には実際に測った結果を書く。

## 面談前に自分の言葉で追記すること

- **開発のきっかけと技術選定**　どの場面で履歴を探すのに困ったか。Laravel・Vue・alwaysdataを選んだ理由は何か。
- **認証と権限の流れ**　[AuthController](app/Http/Controllers/AuthController.php) のログインから、[Recipient](app/Models/Recipient.php) の `checkAccess` による閲覧権限の判定までを、共有解除後の例で説明する。
- **最も長いPHPメソッド**　現時点では `app/` 内の [SharingController::issue()](app/Http/Controllers/SharingController.php#L52) が38行。権限と入力を確認し、トランザクション内で旧招待を無効にして新規招待を保存する。メール送信をトランザクションの外で行う理由と、メール送信に失敗してもリンクを利用できる仕組みを自分の言葉で説明する。
- **試用の結果**　利用者の意見を受けて「反応」を「メモ」に変えた経緯と、実際の贈り物選びで履歴がどう役立ったかを、自分が確認した事実に沿って追記する。
