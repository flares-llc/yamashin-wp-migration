# REST API

OpenAPI定義は[`openapi.yaml`](openapi.yaml)です。namespaceは互換性のため`flares-sync/v1`です。

## Authentication

サイト間通信は`X-Fsync-Key-Id`、`X-Fsync-Timestamp`、`X-Fsync-Nonce`、`X-Fsync-Algorithm`、`X-Fsync-Signature`を使います。正規化文字列は次の8行です。

```text
FSYNC1
METHOD
/flares-sync/v1/route
sorted-query
sha256(body)
unix-timestamp
nonce
key-id
```

署名検証後にnonceを一意INSERTし、リプレイ、時計ずれ、失効鍵、capability不足、IP範囲外を拒否します。WordPress管理者のcookie＋REST nonceでも同じrouteを操作できます。

## Migration endpoints

- `POST /migration/releases`: `direction=push`は現在サイトでmanifestとreleaseを作る。`direction=pull`は取得元へpushを依頼して追跡するproxy jobを作る（202）。pullは両方向のペアリングが必要。
- `POST /migration/releases/prepare`: 受信manifestを検証し、対象側manifestと差分を作る（202）。
- `GET /migration/releases/{release_id}`: releaseと項目差分。
- `POST /migration/releases/{release_id}/dry-run`: オブジェクトとpreflightを再検証し、一度限りの確認値を返す。
- `POST .../resolve`: `plan_hash`と項目解決を要求し、新しいplan hash／確認値を返す。
- `POST .../confirm-deletes`: exact planの削除一覧を、`confirm=true`で再確認して確定する。
- `POST .../apply`: `plan_hash`、確認値、idempotency keyを要求し、対象側のapply jobを202で返す。検証済みreleaseの再送は成功として返す。
- `GET /migration/manifests/{id}`、`GET .../buckets/{00..ff}`: manifestとMerkle bucket。
- `POST /migration/objects/{sha256}`: offset、total、base64 dataで大きなオブジェクトのチャンクを送る。
- `POST /migration/objects/batch`: 128KiB以下の小オブジェクトを最大100件まとめて送る。
- `GET /migration/jobs/{id}`、`POST .../continue|resolve|confirm-deletes|confirm|cancel`: 再開可能job。
- `GET /migration/snapshots`、`POST /migration/snapshots/{id}/rollback`: 手動復元。後者はsnapshotに一致する`plan_hash`、`confirm=true`、idempotency keyを要求する。
- `GET /migration/receipts`: verified receipt一覧。baseline本体は公開応答から除外する。

開始系は202と`job_id`を使います。適用は100項目単位の依存順バッチで、接続元jobが接続先jobを追跡します。状態変更はexact `plan_hash`へ拘束し、dry-run、continue、cancelを含む更新呼出しは32桁hexのidempotency keyを要求します。ネットワーク、408、425、429、5xxは再送可能として扱い、オブジェクト転送とverified release適用は冪等です。接続元で中止すると接続先jobも中止し、部分適用済みならsnapshotを復元します。
