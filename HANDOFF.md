# Flares Sync — デバッグ引き継ぎ

対象: `~/dev/flares-repos/yamashin-wp-migration`（WordPress プラグイン `flares-sync`）
状態: フェーズ1（基盤・設定・認証）完了。差分エンジン本体は未実装。
作成: 2026-07-26

このドキュメントは**デバッグ担当者向け**です。「何を直してよくて、何を触ってはいけないか」「どこが検証済みで、どこが未検証か」を明示します。

---

## 1. 5分で動かす

```bash
cd ~/dev/flares-repos/yamashin-wp-migration

# 静的テスト（WordPress 不要・約20秒）
docker run --rm -v "$PWD":/app -w /app php:8.0-cli php tests/run.php
# → OK: 264 assertions passed

# 3環境の WordPress を立てる
docker compose up -d
docker compose --profile setup run --rm setup

# 実環境テスト（実 HTTP 往復・各26項目）
./docker/verify-pairing.sh staging
./docker/verify-pairing.sh production
# → Success: 26 checks passed
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

── 管理画面（未検証度が高い。§6 参照）
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

## 4. 既知の不具合（優先度順・再現手順つき）

### 4.1 【高】ペアリング失敗時にゴミが残る

`class-fsync-pairing.php:261 connect()` は `import()`（ローカル保存）→ `/pair/confirm`（ネットワーク）の順に実行します。import を先にコミットするのは「一時的な通信失敗で貼り付けた blob を失わせない」ためですが、**恒久的な失敗でもロールバックしません。**

再現:

```bash
./docker/verify-pairing.sh staging   # テスト内で失敗する再ペアリングを1回行う
docker compose --profile tools run --rm -T wpcli_local wp eval '
foreach (Fsync_Peer::all() as $p) echo $p["env_name"]."\n";
foreach (Fsync_Credentials::all() as $c) echo $c["credential_id"]." ".$c["fingerprint"]."\n";' --allow-root
```

実際の結果:

```
production / production2 / staging / staging2      ← 2つは孤児
peer-production a995d832 / peer-production2 a995d832  ← 同じ秘密が2つのIDで保存されている
```

期待動作: `fsync_pairing_consumed` / `fsync_pairing_expired` / `fsync_signature_invalid` のような**終端エラー**では import を巻き戻す。`fsync_network_error` のような**再試行可能エラー**では残す（現在の意図はそちら）。`Fsync_Client::decode()` が既に `retryable` フラグを付けているので、それを判定に使えます。

セキュリティホールではありません（同じ秘密が同じ鍵で暗号化されて2箇所にあるだけ）が、ピア一覧が汚れ、どれが本物か分からなくなります。

### 4.2 【中】環境名の衝突で別サイトのピアを上書きする

`class-fsync-pairing.php:confirm()` と `import()` は `env_name` で既存ピアを探し、あれば同じ `peer_id` を再利用して**URL を上書き**します。異なる 2 サイトが同じ環境名（例: どちらも `local`）で接続してくると、後から来た方が前のピアを乗っ取ります。

`peers` テーブルは `env_name` に UNIQUE 制約があるため、別 peer_id での共存もできません。

要検討: 確定時に「既存ピアの URL と一致しない場合は新しい環境名を要求する」か、`(env_name, url)` で同一性を判断するか。

### 4.3 【中】nonce の INSERT 失敗を一律「リプレイ」と報告する

`class-fsync-nonce-store.php:64` は `$wpdb->insert()` が false を返したとき、テーブルの存在だけを確認して、それ以外はすべて `fsync_nonce_replayed` にしています。カラム長超過・接続断・デッドロックも「リプレイ」と表示されます。

改善案: `$wpdb->last_error` に重複キーを示す文字列（`Duplicate entry`）が含まれるかを見て分岐する。

### 4.4 【中】不正な正規表現が黙って無視される

`class-fsync-config.php:315` は `@preg_match($pattern, $name)`。設定の `protected_extra` や `options.allow` に壊れた正規表現（例: `/^foo(/`）を書くと、**エラーにならず単にマッチしなくなります**。保護リストに書いたつもりのものが保護されません。

改善案: `Fsync_Config_Validate` で、スラッシュ囲みのパターンを `preg_match` にかけて妥当性を検証し、エラーとして JSON Pointer 付きで返す。

### 4.5 【低】introspect のメタキー集計が全表スキャン

`class-fsync-introspect.php:166` の `GROUP BY meta_key` は `wp_postmeta` 全体を走査します。20万行規模で数秒〜数十秒。`/config/introspect` は既定で `include_meta_keys=true` なので、大きなサイトでこのエンドポイントを叩くとタイムアウトし得ます。

改善案: `Fsync_Budget` を使って途中で打ち切り、`truncated: true` を返す。または既定を false にする。

### 4.6 【低】`Fsync_Env::report()` が毎回 DB ロックを取得する

`supports_get_lock()`（`class-fsync-env.php:231`）は `GET_LOCK` / `IS_USED_LOCK` / `RELEASE_LOCK` の3クエリを発行します。`report()` はリクエスト内でメモ化されていますが、ハンドシェイク・status・introspect のたびに実行されます。結果を transient にキャッシュしてよいはずです。

---

## 5. 検証済みの範囲

**信頼してよい部分**（実際に実行して確認済み）:

- 静的テスト 264 アサーション（`tests/run.php`、PHP 8.0）
  - ハッシュ安定性、不正 UTF-8 の検出、パストラバーサル拒否
  - 暗号: 往復・AAD 束縛・改竄検出・鍵変更検出・カナリア
  - 署名: 正規化文字列の8行構造、全要素が署名に効くこと、時刻ずれ
  - CIDR 照合（非バイト境界・IPv4/IPv6 混在含む）
  - JSONC 解析（**文字列中の `//` を壊さないこと**）、設定検証、秘匿値の混入拒否
  - 同梱の `flares-sync.config.example.jsonc` 自体が解析・検証を通ること
- 実環境テスト 26項目 × 2環境（`docker/verify-pairing.sh`）
  - ペアリング成立、blob 使い捨て、認証情報が値を返さないこと
  - ハンドシェイク、チャンクサイズ交渉（256KiB の倍数）
  - **nonce リプレイ拒否・署名改竄拒否・1時間前のタイムスタンプ拒否**
  - introspect が保護対象オプションを返さないこと
- 管理画面3枚が致命的エラーなくレンダリングされること（スモークのみ）

---

## 6. 未検証・弱い領域（バグが潜んでいるならここ）

優先的に疑うべき順:

1. **管理画面のフォーム処理。** レンダリングは確認しましたが、**POST ハンドラは一度も実行していません**（`Fsync_Admin::handle_post` 経由の 10 個の handler）。nonce・リダイレクト・transient 経由の通知、特に `Fsync_Admin_Config::handle_build()` のビルダー出力は未検証です。
2. **`Fsync_Config_Io` のファイル経路。** テストは `parse()` と `merge()` のみ。実際に `wp-content/flares-sync.config.jsonc` を置いて `locate()` → `load()` → 管理画面が読み取り専用になる、という流れは未実行です。
3. **キーのローテーションと猶予期間。** `Fsync_Keys::rotate()` は一度も呼ばれていません。`grace_until` を使った `usable()` の分岐も未検証。
4. **IP 許可リストの実挙動。** `ip_matches()` は単体テスト済みですが、`Fsync_Auth::check_ip()` と `client_ip()`（信頼プロキシ判定）はリクエスト経路で未検証。
5. **`/config/apply` の REST 経路。** 静的テストは `Fsync_Config_Validate::check()` を直接呼んでいるだけ。REST 経由の保存・履歴記録・差分計算は未実行。
6. **`uninstall.php`。** 一度も実行していません。
7. **`Fsync_Signer::normalize_query` と WordPress の実挙動。** 受信側は `$request->get_query_params()` を署名対象にします。WordPress や他プラグインがクエリパラメータを注入すると署名が壊れます（例: JS クライアント由来の `_locale`）。サーバー間通信では現状問題ありませんが、経路が増えたら疑うべき箇所です。

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

設計はすべて `~/.claude/plans/wordpress-optimized-moon.md` にあります（15セクション）。特に §1（可搬形式と三方向差分）と §4（リリース昇格）は、実装前に読む前提で書かれています。

---

## 9. 既存プラグインの重大バグ（参考・別リポジトリ）

このプラグインの前身 `shusei-club-yokkaichi-hp/wp-content/plugins/shusei-deploy` に、**稼働中サイトを壊しうるバグ**があります。

`includes/class-shusei-deploy-rest.php:1564` の `rewrite_urls()` が、PHP シリアライズ済みデータに対して生の `str_replace` で URL 置換を行っています。`s:29:"http://localhost:8082/img.png"` を置換すると長さ表記が実体とずれ、`unserialize()` が `false` を返して**メタ値が恒久的に破壊されます**。

現在無事なのは、同期対象メタ（`event_meta_keys()` / `column_meta_keys()`）が偶然すべてスカラー値だからです。**同期対象を広げた瞬間に ACF・Elementor・`theme_mods_*` を壊します。**

加えて Gutenberg のブロック属性は `http:\/\/` とエスケープされているため、その形の URL は 1 つも書き換わっていないはずです（画像が壊れている可能性あり）。

Flares Sync ではここを可搬形式パイプライン（送信側でトークン化 → 受信側で `serialize()` により長さを再計算）で作り直す設計です。
