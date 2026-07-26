# Yamashin WP Migration v1.0.0 — 開発・検証引き継ぎ

対象: このリポジトリ。公開名はYamashin WP Migration、内部slugは互換性維持のため`flares-sync`。

## 5分で確認

```bash
docker run --rm -v "$PWD":/app -w /app php:8.0-cli php tests/run.php
docker compose up -d
docker compose --profile setup run --rm setup
./docker/verify-pairing.sh staging
./docker/verify-pairing.sh production
npm --prefix mcp ci
npm --prefix mcp run check
npm --prefix mcp audit --omit=dev
```

| 環境 | URL | 役割 |
|---|---|---|
| local | http://localhost:8091 | release作成元 |
| staging | http://localhost:8092 | 検証先 |
| production | http://localhost:8093 | 制限PHP設定の昇格先 |
| mailpit | http://localhost:8094 | 通知確認 |

管理者はDocker fixtureに限り`admin` / `admin`です。

## v1.0.0のコード順

1. `class-fsync-utils.php`, `class-fsync-fs.php`, `class-fsync-store.php`
2. `class-fsync-identity.php`, `class-fsync-portable.php`, `class-fsync-manifest.php`
3. `class-fsync-diff.php`, `class-fsync-release.php`, `class-fsync-snapshot.php`, `class-fsync-apply.php`
4. `class-fsync-job.php`, `class-fsync-rest-migration.php`
5. `class-fsync-mcp-token.php`, `class-fsync-mcp.php`, `mcp/src/index.ts`
6. `class-fsync-admin-migration.php`

仕様は`docs/`、JSON形式は`schemas/`、AI向け入口は`llms.txt`と`AGENTS.md`を正とします。

## 互換性を壊さない

- plugin slug `flares-sync`
- REST namespace `flares-sync/v1`
- DB prefix `fsync_`
- 署名prefix `FSYNC1`と8行canonical string
- `_fsync_uid`のUUIDv4形式
- portable format version 1

これらを変える場合はwire／hash／format versionを分離して上げ、旧peerを明示拒否してください。

## 検証済み

- PHP 8.0／8.5構文と純粋ロジック363 assertions（両版とも警告なし）
- MariaDB 10.6の3サイトfixtureに加え、MySQL 8.4.10のクリーン有効化と14テーブル作成
- staging／production各61項目の実HTTP認証・設定回帰
- 初回content移行、2回目unchanged、source update、双方変更conflict、未解決拒否、source/target/skipの遠隔解決経路
- 全体許可＋scope許可＋一覧再確認によるdelete、delete前拒否、manual rollback
- 10,036項目のcontent移行と約3,949項目・87MB相当のfull manifest、コード重複排除、user統合、full apply／verify
- チャンクoffset、SHA-256改変拒否、既存object冪等再送
- MCP HTTP initialize/tools/resources/prompts、readonly拒否、Origin拒否、公式InspectorとSDK経由stdioで22 tools、status、破壊操作の確認不足拒否
- push／pullの両方向を実HTTPで完走。pullは同一idempotency keyの再送が同じjobを返し、別profileへの再利用を拒否
- MCP SDK 1.29.0固定、TypeScript build、npm audit 0

## 特に回帰しやすい点

- MariaDBでは`cursor`が予約語。job列は`cursor_pos`。
- job statusは`awaiting_confirmation`を収めるためvarchar(24)。
- plugin更新はactivation hookを通らないため、schemaは全requestのinitで遅延更新する。
- WordPress APIはpost modified日時とcomment本文／日時を書き換える。hash検証のため、認証・hash検証済みportable値をDBへ正確に戻す。
- user meta置換時にcapabilities、user_level、session_tokensを消さない。
- receiptはtarget manifest全体ではなくsource-owned itemだけをbaselineにする。
- scope fingerprintが異なるreceiptを三方向差分のbaseに使わない。
- targetで削除された既存itemはcreateではなくconflict。
- object chunkのtmp directoryはactivation済み前提にせず都度作成可能にする。

## 公開前の残作業チェック

- 全自動テスト、uninstall、配布ZIP展開・有効化をクリーン環境で再実行
- GitHub branch rules、Issues／Discussions／Wiki／Projects無効、private vulnerability reporting有効を確認
- exact main commitへ`v1.0.0`タグ、ZIP、SHA-256を作成
- 匿名clone、latest release、asset checksumを確認
- 山真研究室サイトはローカル確認後にだけToolbox PR／本番反映
