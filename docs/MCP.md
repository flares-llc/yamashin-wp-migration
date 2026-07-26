# Model Context Protocol

WordPressは`flares-sync/v1/mcp`でステートレスなStreamable HTTP MCPを提供します。安定プロトコル`2025-11-25`に対応し、`initialize`、`ping`、tools、resources、prompts、initialized notificationを実装します。

## Authentication and transport

- 公開ホストはHTTPS必須。HTTPはlocalhost、loopback、ドットを含まない開発ホストだけ。
- 管理画面で発行する専用トークンを`Authorization: Bearer`または`X-Fsync-MCP-Token`へ設定。
- DBにはトークン全体のSHA-256だけを保存し、平文は発行時に一度だけ表示。
- `Origin`がある場合は正規化した完全一致allowlistまたはサイト自身のoriginだけを許可。
- capabilityは`status`、`read`、`write`、`files`、`restore`など既存の接続権限と同じ意味で検査。

## Tools

設定: `status`, `introspect`, `config_get`, `config_schema`, `config_validate`, `config_apply`, `peers_list`

移行: `release_create`, `release_list`, `release_get`, `release_dry_run`, `conflicts_resolve`, `deletes_confirm`, `release_apply`。`release_create.direction`は`push`または`pull`で、pullは両方向のペアリングが必要です。更新toolの`idempotency_key`はクライアントが生成した32桁hexを再試行時も変えずに送ります。

遠隔job: `job_get`, `job_continue`, `job_conflicts_resolve`, `job_deletes_confirm`, `job_confirm`, `job_cancel`

復旧: `snapshots_list`, `snapshot_rollback`。ロールバックはsnapshotの`plan_hash`、`idempotency_key`、`confirm=true`を要求します。

設定保存、削除、適用、ロールバックは`confirm=true`を要求します。適用系はさらにexact ID、`plan_hash`、一度限りの確認値を要求します。

## Resources and prompts

`fsync://site/status`、config、peers、releases、jobs、snapshots、architecture、portable formatをresourcesとして公開します。設定作成、移行計画、失敗診断のpromptsは、人の確認を飛ばさない手順をAIへ指示します。

## stdio bridge

`mcp/`は公式TypeScript SDKの安定版を固定したstdioブリッジです。Node.js 20以上と次の2変数だけを必要とします。

```text
FSYNC_SITE_URL=https://example.com/
FSYNC_MCP_TOKEN=issued-token
```

ブリッジはredirectを拒否し、120秒でtimeoutし、公開URLの平文HTTPを拒否します。依存更新時は`npm audit --omit=dev`とMCP Inspector／公式Clientの一覧・call往復を確認します。

公開版はGitHub Releaseへ常に同じ名前の`yamashin-wp-migration-mcp.tgz`として添付します。デスクトップAIでは`npx --yes --package=https://github.com/flares-llc/yamashin-wp-migration/releases/latest/download/yamashin-wp-migration-mcp.tgz yamashin-wp-migration-mcp`をcommand/argsへ分けて設定でき、サイトURLと専用トークン以外の環境変数は不要です。
