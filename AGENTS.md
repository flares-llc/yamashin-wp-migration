# AGENTS.md

## Purpose

Yamashin WP Migrationは、2つのWordPressサイト間で明示的な一方向移行を行うプラグインです。公開名はYamashin WP Migrationですが、互換性のため内部slug、REST namespace、DB接頭辞、署名プロトコルは`flares-sync`のままです。

## Read first

変更前に次を読んでください。

1. `docs/ARCHITECTURE.md`
2. `docs/PORTABLE_FORMAT.md`
3. `docs/DIFF_ALGORITHM.md`
4. `docs/THREAT_MODEL.md`
5. 変更対象に応じて`docs/REST_API.md`または`docs/MCP.md`

## Invariants

- 双方向自動同期にしない。方向はリリースごとに`push`または`pull`で固定する。
- 既存の`flares-sync` slug、`flares-sync/v1` namespace、`FSYNC1`署名文字列を互換性判断なしに変更しない。
- 外部入力のパスを直接結合しない。`Fsync_Fs::resolve()`またはSHA-256から導く`Fsync_Store::path()`を使う。
- 破壊的操作はドライラン済み`plan_hash`と一度限りの確認値へ拘束する。
- 競合は未解決のまま適用しない。削除は全体許可、スコープ許可、確定対象一覧の再確認をすべて要求する。
- `siteurl`、`home`、秘密情報、セッション、最後の管理者、当プラグイン自身の接続状態を移行しない。
- 受信オブジェクトは最終化前にSHA-256を再計算する。
- 適用前スナップショットが完全に作れなければ変更を始めない。適用・検証失敗時はロールバックする。
- MCPトークンは平文保存しない。capabilityとOriginを毎回検査する。

## Verification

最低限、次を実行します。

```bash
docker run --rm -v "$PWD":/app -w /app php:8.0-cli php tests/run.php
docker run --rm -v "$PWD":/app:ro -w /app php:8.0-cli sh -c 'find . -name "*.php" -exec php -l {} \;'
npm --prefix mcp ci
npm --prefix mcp run check
npm --prefix mcp audit --omit=dev
./docker/verify-pairing.sh staging
./docker/verify-pairing.sh production
```

移行エンジンを変更した場合は、初回移行、同一リリース再送、更新、双方変更の競合、明示削除、通信再送、改変オブジェクト、自動／手動ロールバックをDockerの別サイト間で確認します。

## Public repository policy

外部Issue、Discussion、PR、修正要望は受け付けません。脆弱性報告だけをGitHub private vulnerability reportingへ案内します。
