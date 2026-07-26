# yamashin-wp-migration-mcp

Yamashin WP Migrationをstdio型MCPクライアントから利用するための公式ブリッジです。WordPress側のStreamable HTTP MCPへ接続するため、サイトごとの専用トークンが必要です。

Node.js 20以上が必要です。

```json
{
  "mcpServers": {
    "yamashin-wp-migration": {
      "command": "npx",
      "args": ["-y", "yamashin-wp-migration-mcp@1"],
      "env": {
        "FSYNC_SITE_URL": "https://example.com/",
        "FSYNC_MCP_TOKEN": "発行時に一度だけ表示されるトークン"
      }
    }
  }
}
```

公開ホストはHTTPS必須です。HTTPはlocalhost、ループバック、ドットを含まない開発ホストでだけ許可されます。
