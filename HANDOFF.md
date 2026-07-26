# Yamashin WP Migration — デバッグ引き継ぎ

対象: このリポジトリ（WordPress プラグインの内部 slug は `flares-sync`）
状態: フェーズ1（基盤・設定・認証）完了。差分エンジン本体は未実装。
作成: 2026-07-26

このドキュメントは**デバッグ担当者向け**です。「何を直してよくて、何を触ってはいけないか」「どこが検証済みで、どこが未検証か」を明示します。

---

## 1. 5分で動かす

```bash
cd /path/to/yamashin-wp-migration

# 静的テスト（WordPress 不要・約20秒）
docker run --rm -v "$PWD":/app -w /app php:8.0-cli php tests/run.php
# → OK: 341 assertions passed

# 3環境の WordPress を立てる
docker compose up -d
docker compose --profile setup run --rm setup

# 実環境テスト（実 HTTP 往復・各61項目＋補助検証）
./docker/verify-pairing.sh staging
./docker/verify-pairing.sh production
# → Success: 61 checks passed

# アンインストール検証（指定した Docker サイトのプラグイン状態を一度消して再構築する）
./docker/verify-uninstall.sh production
```

| | URL | 役割 |
|---|---|---|
| local | http://localhost:8091 | リリース作成元 |
| staging | http://localhost:8092 | 検証ゲート |
| production | http://localhost:8093 | 昇格先。**制限付き php.ini**（`max_execution_time=20`, `upload_max_filesize=2M`） |
| mailpit | http://localhost:8094 | |

管理者は全て `admin` / `admin`。

**任意のサイトで PHP を実行する:**

```bash
docker compose --profile tools run --rm -T wpcli_local wp eval 'var_dump(Fsync_Crypto::check());' --allow-root
# wpcli_local / wpcli_stg / wpcli_prod
```

**構文チェック（PHP 8.0 = 宣言している下限）:**

```bash
docker run --rm -v "$PWD":/app:ro -w /app php:8.0-cli \
  sh -c 'find . -name "*.php" -exec php -l {} \; | grep -v "No syntax errors" || echo OK'
```

---

## 2. コードマップ

すべて `final class` + `public static function`、名前空間なし、1ファイル1クラス。読む順序は依存関係の順です。

```
flares-sync.php                    ブートストラップ・定数・require チェーン

── 基盤（他のすべてが依存）
class-fsync-env.php                実行環境の検出。チャンクサイズ交渉の計算もここ
class-fsync-budget.php             時間/メモリ予算。全ループがこれで中断判断する
class-fsync-utils.php              canonical_hash / パス正規化 / JSON encode(失敗検出付き)
class-fsync-fs.php                 保存領域・原子的書き込み・ツリー走査
class-fsync-schema.php             dbDelta テーブル定義（6テーブル）
class-fsync-log.php                監査ログ + redact()

── 暗号・認証情報
class-fsync-crypto.php             AES-256-GCM 封筒v2・HKDF・AAD・4段鍵チェーン・カナリア
class-fsync-credentials.php        書き込み専用ストア。値を返す唯一の入口は get()

── 認証（プロトコル。§3 参照）
class-fsync-signer.php             正規化文字列の構築。★ここが仕様
class-fsync-signer-hmac.php        HMAC-SHA256
class-fsync-nonce-store.php        リプレイ防止
class-fsync-keys.php               キー発行・権限スコープ・ローテーション・失効
class-fsync-peer.php               ピア台帳
class-fsync-pairing.php            ペアリング blob の発行/解析/取り込み/確定
class-fsync-auth.php               permission_callback 本体。★検査順序が重要

── 設定
class-fsync-config-io.php          ファイル/DB 解決・JSONC 解析・マージ・履歴
class-fsync-config.php             実効設定・保護リスト・scope_fingerprint
class-fsync-introspect.php         サイトの内容を返す（設定を書く材料）
class-fsync-config-schema.php      サイト固有の JSON Schema 生成
class-fsync-config-validate.php    検証。JSON Pointer 付きで返す

── 通信
class-fsync-client.php             署名付き HTTP クライアント
class-fsync-rest.php               ルート登録・共通レスポンス
class-fsync-rest-status.php        /handshake /status /echo
class-fsync-rest-config.php        /config/{schema,introspect,validate,apply,history}
class-fsync-rest-keys.php          /pair/confirm /keys

── 管理画面（主要フォーム・通知・削除・診断をブラウザ検証済み。§5 参照）
class-fsync-admin*.php             メニュー・接続・設定・診断

── テスト
tests/run.php                      静的テスト。bootstrap.php が WP をスタブする
tests/integration/*.php            wp eval-file で動く実環境テスト
```

---

## 3. 変更してはいけないもの（プロトコル凍結）

プラグインは**両サイトに入る**ため、これらを変えると既存ペアが全滅します。変更する場合は `FSYNC_PROTOCOL` / `FSYNC_HASH_ALGO_VERSION` を必ず上げてください（ハンドシェイクが不一致を検出して拒否します）。

**署名の正規化文字列**（`class-fsync-signer.php:canonical()`）:

```
FSYNC1
{HTTPメソッド大文字}
{正規化された REST ルート}
{ソート済みクエリ（rest_route を除外）}
{sha256_hex(ボディ)}
{UNIXタイムスタンプ}
{nonce}
{key_id}
```

意図的な設計判断で、直すべき「変な実装」ではないもの:

- **`Authorization` ヘッダーを使わない。** 一部のレンタルサーバー/PHP-CGI が削除するため。代わりに `X-Fsync-*`。
- **URL パスではなく REST ルートを署名する。** リクエストは `?rest_route=` 形式で送るので、URL パスはサイト設定によって変わる。
- **nonce は INSERT の一意制約で判定する。** SELECT→INSERT は同時リクエストで両方通る。
- **nonce テーブルが無ければ 503 で拒否する。** 保護なしのフォールバックは入れない。
- **暗号封筒に鍵参照 `k` を入れる。** 「鍵が変わった」と「復号失敗」を区別するため。
- **オプションは許可リスト方式のみ**（`deny` を書くと検証エラー）。投稿メタは拒否リスト方式。

`class-fsync-auth.php:run_checks()` の**検査順序も意図的**です。特に **nonce の消費は署名検証の後**。逆にすると、未認証の攻撃者が正規リクエストの nonce を先に潰せます。

---

## 4. 今回のデバッグで修正した主な不具合

2026-07-26 の複数巡回で、旧版の §4 に記録されていた既知不具合はすべて修正し、回帰テストを追加しました。

- **ペアリング:** 終端エラー時のピア・認証情報ロールバック、環境名衝突の拒否、URL の厳格化と正規化、IP 許可リストの確認時適用、再ペアリング後の旧受信キー失効
- **認証・鍵:** nonce の重複と DB 障害の識別、IPv4/IPv6 CIDR の厳格化、信頼プロキシ経由のクライアント IP 判定、鍵発行・ローテーション・失効時の入力/DB エラー処理
- **設定:** トップレベル配列と壊れた JSONC の拒否、ファイル設定の fail-closed、正規表現検証、生成 JSON Schema と実行時検証の一致、環境/スコープ上書き後の再検証、no-op 保存と履歴/ロールバックの整合性
- **性能:** 重いメタキー集計を opt-in に変更、`GET_LOCK` 対応判定を transient にキャッシュ
- **管理・運用:** ピア/認証情報削除失敗の伝播、保存領域の自己修復とガードファイル検証、アンインストール時のテーブル・オプション・transient・cron の除去

この時点で再現可能な既知不具合は残っていません。未実装機能は §8、なお検証が弱い境界条件は §6 を参照してください。

---

## 5. 検証済みの範囲

**信頼してよい部分**（実際に実行して確認済み）:

- 静的テスト 341 アサーション（`tests/run.php`、PHP 8.0）
  - ハッシュ安定性、不正 UTF-8 の検出、パストラバーサル拒否
  - 暗号: 往復・AAD 束縛・改竄検出・鍵変更検出・カナリア
  - 署名: 正規化文字列の8行構造、全要素が署名に効くこと、時刻ずれ
  - CIDR 照合（非バイト境界・IPv4/IPv6 混在含む）
  - JSONC 解析（**文字列中の `//` を壊さないこと**、未終端コメント・トップレベル配列の拒否）、設定検証、秘匿値の混入拒否
  - 生成 JSON Schema の再帰検証、環境/スコープ上書き、通知先 URL、壊れた正規表現
  - 同梱の `flares-sync.config.example.jsonc` 自体が解析・検証を通ること
- 実環境テスト 61項目＋補助検証 × 2環境（`docker/verify-pairing.sh`）
  - ペアリング成立、blob 使い捨て、認証情報が値を返さないこと
  - 終端エラーの巻き戻し、環境名衝突拒否、旧受信キーの失効、実 HTTP での IP 拒否
  - ハンドシェイク、チャンクサイズ交渉（256KiB の倍数）
  - **nonce リプレイ拒否・署名改竄拒否・1時間前のタイムスタンプ拒否**
  - introspect の保護対象除外・メタ集計 opt-in、設定ファイル優先/fail-closed
  - キーローテーションと猶予期間、REST 設定適用・履歴・no-op 保存・不正設定拒否
- 管理画面をブラウザ操作
  - 設定ビルダー生成、検証（警告0件）、適用、履歴、公開 HTTP URL 拒否
  - ピア削除時の認証情報消去、不正 IP 入力の拒否、診断と保存領域の自己修復
  - 対象ページの JavaScript コンソールにプラグイン由来のエラーなし
- `uninstall.php` を Docker 本番役で実行
  - 6テーブル、オプション、transient、cron の削除
  - 非公開保存領域は意図どおり保持し、再有効化後にスキーマと受信設定を復元

---

## 6. 未検証・弱い領域（バグが潜んでいるならここ）

優先的に疑うべき順:

1. **実ロードバランサーでの信頼プロキシ連鎖。** IPv4/IPv6 と複数段 X-Forwarded-For は単体検証済みですが、nginx/Cloudflare/レンタルサーバー固有のヘッダー書き換えまでは再現していません。
2. **`Fsync_Signer::normalize_query` と他プラグインの干渉。** 受信側は `$request->get_query_params()` を署名対象にします。他プラグインが `_locale` などを注入すると署名が壊れ得ます。現在のサーバー間経路では問題ありません。
3. **外部通知サービスの実送信。** Slack/webhook/email の設定形状と秘密情報分離は検証済みですが、実アカウントへの配信、レート制限、相手側障害は未検証です。
4. **DB 障害注入の網羅。** 主要な insert/update/delete 失敗はスタブで確認していますが、MariaDB の接続断、デッドロック、ディスク枯渇を実コンテナで強制した耐障害テストは未実施です。
5. **大規模データでの性能。** メタキー集計は opt-in、ロック判定はキャッシュ済みですが、数百万行級 DB での計測はフェーズ2の差分エンジンと合わせて必要です。

---

## 7. 環境固有の落とし穴（ハマりどころ）

### 7.1 `WORDPRESS_CONFIG_EXTRA` は wp-config.php に焼き込まれない

WordPress 公式イメージは `WORDPRESS_CONFIG_EXTRA` を**実行時に `eval()`** します。したがって **WordPress を起動する全コンテナ**（web と wp-cli の両方）に同じ値を渡す必要があります。渡し忘れると、web と CLI で `FSYNC_ENCRYPTION_KEY` が食い違い、**片方が暗号化したものをもう片方が復号できません**。

これは実際に踏みました。症状は `fsync_key_changed`（「暗号化キーが変わっています」）。`compose.yaml` の `x-config-*` アンカーで全サービスに配っています。同種の症状が出たら、まずここを疑ってください。

### 7.2 コンテナ間はサービス名、ブラウザは公開ポート

ペアリング時の「接続用URL」は `http://fsync_stg` / `http://fsync_prod`（サービス名）を指定します。`http://localhost:8092` はコンテナ内から解決できません。

これは Docker 固有の不便ではなく、**ロードバランサー配下や内部ホスト名の本番でも起きる状況**なので、接続先URLは `home_url()` と独立して設定・編集できるようにしてあります。

### 7.3 ドット無しホスト名は HTTP を許可する

`Fsync_Pairing::is_local_url()` は、ドットを含まないホスト名（`fsync_stg`、`intranet`）を内部ホストとみなして平文 HTTP を許可します。公開 DNS 名には必ずドットが含まれるため、これは妥当な判定です。公開ホストへの HTTP は拒否されます。

### 7.4 このマシンの Docker は混雑している

他プロジェクトのコンテナが 20 個以上動いており、`docker run` の起動が数十秒かかることがあります。テストが遅いのはコードのせいではありません。ポートも `808x` 帯は埋まっているため、このスタックは `809x` を使っています。

### 7.5 テストスクリプトの `global` は効かない

`wp eval-file` はファイルをメソッド内で include するため、トップレベルの `$var` は関数スコープになり、ヘルパー内の `global $var` は**別の変数**を指します。`tests/integration/connect.php` は当初これで踏み、**失敗が常に無視される**状態でした（`Fsync_Checks` 静的クラスに修正済み）。同種のスクリプトを追加する際は同じ罠に注意してください。

---

## 8. まだ実装されていないもの（バグと間違えないこと）

以下は**未実装**であり、動かないのは正常です:

- 差分エンジン一式（可搬形式・UID・三方向差分・Merkle バケット交換・ドライラン・適用・スナップショット・ロールバック）
- リリース昇格（`release` / `receipt` / `promote`）
- バックアップ、復元、内容アドレスストア
- GCS / Google Drive アダプタ
- WP-Cron スケジュール実行、ジョブ基盤、ロック
- SSH/rsync トランスポート
- mu-plugin の致命的エラー復旧ガード

静的な `schema/config.schema.json` は**意図的に用意していません**。汎用スキーマは「post_types はオブジェクト」としか言えませんが、`/config/schema` が生成するサイト固有スキーマは実在する投稿タイプ名だけを `enum` に入れるため、存在しない名前を機械的に弾けます。設定例もそちらを取得する手順を案内しています。

フェーズ2以降を実装するときは、可搬形式、三方向差分、リリース昇格の仕様を、この公開リポジトリ内の設計文書として先に追加してください。非公開またはローカル専用の設計資料を前提にしないこと。
