# Giftory

家族や友人への贈り物と反応を相手ごとに記録するWebアプリです。招待した会員と、選んだ相手の履歴を共有できます。

## Dockerで起動

WSLで `docker compose version` が動く状態で、リポジトリのルートから実行します。

```sh
docker compose up -d --build
docker compose logs -f app
```

マイグレーションが完了したら [http://localhost:8080](http://localhost:8080) を開きます。ポートを変える場合は `GIFTORY_PORT=8081 docker compose up -d --build` を使います。Docker側のSQLite、アプリキー、写真は名前付きボリュームに保存され、ホストで起動したGiftoryのデータとは別です。停止は `docker compose down` で行えます。

## Mailpit

Docker版と一緒にMailpitが起動します。開発用メールは [http://localhost:8025](http://localhost:8025) で確認できます。受信画面のポートは `MAILPIT_PORT` で変更できます。

手動でテストメールを送るには、以下を実行します。

```sh
docker compose exec -T app php artisan tinker --execute='Illuminate\Support\Facades\Mail::raw("Giftory mail test", fn ($message) => $message->to("demo-owner@giftory.test")->subject("Giftory SMTP test"));'
```

会員登録時はウェルカムメールが届きます。共有管理で宛先を入力して招待を発行すると、招待リンク付きメールが届きます。宛先を空欄にするとリンクだけが発行されます。

## ローカル起動

PHP 8.3以上、Composer、Node.js 24.12以上、PHPの `pdo_sqlite` 拡張を用意します。

```sh
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm --prefix frontend ci
npm --prefix frontend run build
php artisan serve
```

表示されたローカルURLを開きます。画面を変更した場合はフロントエンドを再ビルドしてください。

## サンプルデータ

Dockerのローカル環境に、デモ会員2人、贈る相手2人、贈り物45件（個人用22件・共有23件）を追加できます。「贈った」と「もらった」の両方の記録が含まれます。

```sh
docker compose exec -T app php artisan db:seed --class=DemoSeeder
```

| 会員 | メールアドレス |
|---|---|
| デモ管理者 | `demo-owner@giftory.test` |
| デモ参加者 | `demo-member@giftory.test` |

パスワードは共通で `GiftoryDemo2026!` です。管理者には個人用と共有の履歴、参加者には共有の履歴が表示されます。

## 確認

Docker内でテストする場合は、テスト用の環境とインメモリDBを明示します。

```sh
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=:memory: -e MAIL_MAILER=array app php artisan test --fail-on-warning
```


```sh
php artisan test
vendor/bin/pint --test
```

共有を確認するには、管理者が相手を登録して共有管理から招待リンクを発行し、別のブラウザプロファイルで別会員として開きます。参加後に履歴を閲覧・追加でき、管理者による解除または本人の退出後は履歴と写真が開けなくなることを確認します。

## 公開環境

公開用のDocker構成は、CaddyでHTTPSを提供し、ApacheでLaravelとVueを同じURLから配信します。MySQL・写真・証明書はDockerボリュームに保存します。

1. Docker Composeが使える公開サーバーを用意し、ドメインのDNSをそのサーバーに向けます。外部からTCP 80番と443番に接続できるようにします。
2. サーバーで `cp .env.production.example .env.production` を実行し、ドメインとSMTP設定を入力します。`APP_KEY` には `base64:` に続けて `openssl rand -base64 32` の出力を設定し、`GIFTORY_DB_PASSWORD` には `openssl rand -hex 32` の出力を設定します。`GIFTORY_DOMAIN` の値は `giftory.example.com` のようなホスト名です。
3. 公開用のComposeを起動します。MySQLが使える状態になると自動でマイグレーションが実行されます。

```sh
docker compose --env-file .env.production -f compose.production.yaml up -d --build
docker compose --env-file .env.production -f compose.production.yaml ps
```

証明書発行後、`https://設定したドメイン` を開き、別の端末で会員登録、写真付き記録、共有招待を確認してください。更新時も同じ起動コマンドを使います。データを保持するため、公開中の `APP_KEY` とデータベースのパスワードを維持し、MySQLと写真を定期的にバックアップしてください。

設計は [要件定義書](docs/要件定義書.md) と [基本設計](docs/基本設計.md) を参照してください。
