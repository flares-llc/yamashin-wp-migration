=== Yamashin WP Migration ===
Contributors: shinroh
Tags: migration, staging, rollback, mcp, hmac
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress環境間の差分をドライランし、明示確認後に適用・検証・ロールバックする一方向移行プラグインです。

== Description ==

Yamashin WP Migrationは、ローカル、ステージング、本番を明示的に接続し、サイト全体またはコンテンツの差分を安全に移行します。自動双方向同期は行いません。

* 投稿、固定ページ、CPT、分類、メタ、コメント、添付、Uploads
* ユーザー、許可済みオプション、登録済み独自テーブル
* テーマ、プラグイン、mu-plugin、同一バージョンのWordPress本体
* 可搬UID、SHA-256、Merkleバケット、receipt基点の三方向差分
* ドライラン、項目単位の競合解決、明示削除
* 適用前スナップショット、自動／手動ロールバック
* HMAC署名REST API、Streamable HTTP MCP、stdioブリッジ

接続先URL、wp-config.php、暗号鍵、ライセンス・認証情報、セッション、当プラグインの接続状態は保持します。マルチサイト、WordPress版更新、秘密情報込み完全クローン、SSH、クラウド保存は対象外です。

== Installation ==

1. リリースZIPを両サイトへアップロードして有効化します。
2. wp-config.phpへFSYNC_ENCRYPTION_KEYを設定します。
3. 受信側で受信を有効にし、一度限りの接続情報を発行します。
4. 移行元でペアリングし、「移行」からpush / pullとプロファイルを選択します。pullは両方向のペアリングが必要です。
5. ドライラン、競合、削除、plan_hashを確認して適用します。

公開ホスト間はHTTPS、OpenSSL拡張、十分な退避容量が必要です。

== Frequently Asked Questions ==

= 双方向に自動同期しますか？ =

いいえ。操作ごとにpushまたはpullの方向を確定する一方向移行です。

= 競合や削除は自動適用されますか？ =

いいえ。競合は既定で停止します。削除は既定OFFで、設定上の二重許可と対象一覧の再確認が必要です。

= WordPressのバージョンも更新できますか？ =

できません。WordPress本体を扱う場合も同一バージョン間だけです。

= AIから操作できますか？ =

はい。専用トークンとcapabilityを持つStreamable HTTP MCP、およびstdioブリッジを提供します。適用とロールバックには正確な識別子、plan_hash、明示確認が必要です。

== Changelog ==

= 1.0.0 =

* サイト全体の可搬形式、UID、Merkleマニフェスト、三方向差分を追加
* ドライラン、競合解決、明示削除、適用、検証、receiptを追加
* 内容アドレス型チャンク転送、スナップショット、自動／手動ロールバックを追加
* 移行ウィザード、移行REST API、Streamable HTTP MCP、stdioブリッジを追加
* 公開JSON Schema、OpenAPI、アーキテクチャ、脅威モデル、復旧・運用文書を追加

= 0.1.0 =

* 接続、HMAC署名認証、権限、暗号化認証情報、JSONC設定、診断を追加
