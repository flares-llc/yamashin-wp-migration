=== Yamashin WP Migration ===
Contributors: shinroh
Tags: migration, staging, hmac, configuration
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

WordPress環境間の安全な差分移行に向けた、接続・署名認証・設定検証・診断の基盤です。

== Description ==

Yamashin WP Migrationは、ローカル・ステージング・本番のWordPressを、安全に接続して管理するための基盤プラグインです。

バージョン0.1.0で利用できる機能:

* 使い捨て接続情報によるペアリング
* HMAC-SHA256署名、nonceリプレイ防止、権限スコープ
* 認証情報の暗号化保存
* JSONC設定、環境オーバーレイ、検証、履歴
* サイト固有JSON Schemaと設定用REST API
* 管理画面の設定ビルダーと診断

重要: バージョン0.1.0は基盤版です。投稿・メディアの差分検知、ドライラン、適用、ロールバックはまだ実装されていません。

詳しい手順はREADME.mdを参照してください。

== Installation ==

1. リリースZIPをWordPress管理画面の「プラグインを追加」からアップロードして有効化します。
2. wp-config.phpにFSYNC_ENCRYPTION_KEYを設定します。
3. 接続先サイトで受信を有効にし、接続キーを発行します。
4. 接続元サイトへ接続情報を貼り付けます。

OpenSSL拡張が必要です。WordPressマルチサイトには対応していません。

== Frequently Asked Questions ==

= このバージョンだけでコンテンツを移行できますか？ =

いいえ。0.1.0は接続、認証、設定、診断の基盤版です。差分エンジンは今後のフェーズで実装します。

= 設定ファイルに秘密鍵を書けますか？ =

書かないでください。秘密情報は管理画面の認証情報ストアへ保存し、設定ファイルからはIDで参照します。

== Changelog ==

= 0.1.0 =

* 接続、HMAC署名認証、権限スコープ、鍵失効を追加
* 暗号化された認証情報ストアを追加
* JSONC設定、サイト固有スキーマ、検証、履歴を追加
* 管理画面、診断、監査ログを追加
