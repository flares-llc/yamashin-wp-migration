# Threat Model

## Assets and trust boundaries

保護対象はWordPress DB、Uploadsとコード、管理者アカウント、接続HMACシークレット、MCPトークン、スナップショット、receiptです。主な境界はブラウザ↔WordPress、AI client↔MCP、移行元↔移行先REST、WordPress↔DB／filesystemです。

想定攻撃者は未認証インターネット利用者、漏洩した読み取り専用キーの保有者、ネットワーク上の改変者、悪意ある移行payload、誤操作した管理者です。WordPressサーバーrootまたはDB管理者を完全に侵害した攻撃者は対象外です。

## Abuse paths and mitigations

| 攻撃・事故 | 緩和策 |
|---|---|
| REST改変・偽装 | body hashを含むHMAC-SHA256、鍵ID、capability、時計ずれ検査 |
| 正規requestの再送 | 署名検証後のnonce一意INSERT。DB障害時はfail closed |
| 鍵・token漏洩 | 暗号化credential、MCP token hash、最小capability、失効、IP／Origin allowlist、監査ログ |
| CSRF | 管理フォームnonce、REST cookie経路のWordPress nonce、MCP専用token |
| SSRF／平文credential送信 | 公開URLのHTTPS強制、redirect禁止、接続URL検証 |
| path traversal／symlink escape | 相対パス正規化、既存パスのrealpath境界確認、SHA-256由来object path、symlink非追跡 |
| チャンク改変・混線 | exact offset、総サイズ、最大4MiB、完了時SHA-256、atomic rename |
| target-only contentの誤削除 | receiptへsource-owned itemだけを保存、削除三重確認、既定OFF |
| 競合の上書き | receipt基点の三方向差分、既定停止、項目解決をplan_hashへ束縛 |
| plan差し替え／古い確認 | immutable release ID、exact plan_hash、一度限り確認、設定hash再確認 |
| 部分適用 | 完全な事前snapshot、依存順、適用後再scan、失敗時自動rollback |
| 致命的plugin／theme | 一時領域からatomic配置、runtime guard、当プラグインの有効化維持 |
| 管理者ロックアウト | login統合、session非移行、現在／最後の管理者保護、target roles保護 |
| 秘密情報流出 | option allowlist、保護option／meta／table、wp-config除外、ログredaction |
| ブラウザMCP乗っ取り | strict Origin比較、公開HTTPS、専用token、capability |

## Residual risks

- WordPress、PHP、DB、他プラグイン自体の脆弱性。
- アプリケーションsnapshotはDBとfilesystemを跨ぐため、OS／DBの同時破損に対する完全なACID transactionではない。
- 大規模サイトではmanifest scanとapplyがホスト制限に依存する。転送は再開可能だが、適用時間帯の十分な余裕が必要。
- 独自テーブルの意味や外部サービス参照は自動推測できない。安定キーと参照規則を設定し、ステージングで検証する必要がある。
- Core公式チェックサム取得不能時はネットワーク障害と改変を区別できないため、コード移行前に運用者の検証が必要。

脆弱性報告は[`SECURITY.md`](../SECURITY.md)の非公開窓口だけで受け付けます。
