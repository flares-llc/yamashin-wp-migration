# Portable Format v1

機械可読定義は[`schemas/`](../schemas/)にあります。可搬レコードとMerkleマニフェストに加え、release、item、job、snapshot、receiptをJSON Schemaで固定しています。

## Record envelope

```json
{
  "format_version": 1,
  "kind": "post",
  "uid": "0cb9aa9a-3898-495e-8a42-82f5c7d15798",
  "data": {},
  "objects": []
}
```

JSONはオブジェクトキーを再帰的に昇順化し、`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION`で符号化します。レコードの論理ハッシュとペイロードオブジェクトIDはいずれもSHA-256ですが、前者は正規化値、後者は転送バイト列に対するハッシュです。

## Identity

- post / term / comment / user: `_fsync_uid`に保存するUUIDv4。
- option: option名のSHA-256先頭32桁。
- file: category、改行、相対パスのSHA-256先頭32桁。
- table: UID列、主キー、自然キーの順。UUIDでない値はテーブル名と自然キーの正規化JSONから導出する。
- runtime: 固定の`wordpress-runtime`識別子。

既存の独立サイトを初回統合する場合、投稿はpost_type＋slug、分類はtaxonomy＋slug、ユーザーはloginを安全な自然キーとして採用できます。採用後は移行元UIDへ一本化します。

## Kinds

- `post`: 投稿タイプ、状態、タイトル、slug、本文、抜粋、GMT日時、親UID、著者login、分類UID、メタ。添付は相対パス、alt、metadataを追加。
- `term`: taxonomy、名前、slug、説明、親UID、メタ。
- `comment`: 投稿UID、親UID、著者、GMT日時、本文、状態、メタ。
- `user`: login、表示情報、登録日時、roles、許可メタ。capabilities、user_level、session_tokensは含めない。password_hashは明示設定時だけ。
- `option`: 許可リストに一致し、保護対象でないoption名と値。
- `table`: 登録済み独自テーブル名、identity、row、設定。
- `file`: category、正規化相対パス、サイズ、内容SHA-256。
- `runtime`: active_plugins、stylesheet、template、WordPress版。

## URL and references

home、site、uploads URLは`{{FSYNC_HOME}}`、`{{FSYNC_SITE}}`、`{{FSYNC_UPLOADS}}`へ正規化し、受信側で復元します。PHPシリアライズ値は構造として展開・再シリアライズするため文字列長を壊しません。

設定で宣言したID参照メタはraw IDではなく`fsync_ref`、shape、UID配列で運び、対象側のローカルIDへ解決します。Gutenbergの主要メディアブロック属性、`wp-image-*`、`wp-attachment-*`も投稿UIDトークンへ変換し、2段階適用で受信側IDに復元します。本文と`srcset`のURLはURLトークン置換で環境差を吸収します。

## Objects and chunks

レコードJSONとファイルは同じ内容アドレス型ストアへ置きます。受信側は期待offset以外を409で拒否し、最大4MiBのbase64チャンクを連結し、総サイズ到達時にSHA-256を再計算して一致した場合だけ最終パスへrenameします。同じ完成済みハッシュの再送は成功として扱います。
