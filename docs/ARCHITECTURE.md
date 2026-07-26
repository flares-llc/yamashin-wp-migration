# Architecture

## Goal and direction

1リリースは、1つの移行元から1つの移行先へ向かう不変な計画です。`push`は現在サイトの通常job、`pull`は取得元へ通常のpushを依頼して追跡するproxy jobとして実行します。pullは双方がそれぞれ発行した鍵でペアリングされている必要があります。どちらも方向は開始時に固定し、自動双方向同期やlast-write-winsは行いません。

```text
source WordPress
  portable scan → object store → Merkle manifest → immutable release
         │                                      │
         └──── signed REST / resumable chunks ──┘
                                                ▼
target WordPress
  target manifest → three-way diff → dry-run → plan_hash confirmation
                                                ▼
  snapshot → dependency-ordered apply → rescan/verify → receipt
                 └──── any failure ────→ automatic rollback
```

## Components

- `Fsync_Portable`: WordPress行とファイルを環境非依存レコードへ変換する。
- `Fsync_Identity`: 投稿、分類、コメント、ユーザーのUUIDv4とローカルIDを対応付ける。
- `Fsync_Store`: SHA-256をキーにした内容アドレス型ストア。最大4MiBのチャンクを検証して最終化する。
- `Fsync_Manifest`: 256個のMerkleバケットとルートハッシュを作る。受信マニフェストは集約ハッシュを再計算する。
- `Fsync_Diff`: receipt基点の三方向差分を純粋関数として判定する。
- `Fsync_Release`: 不変`release_id`、差分項目、`plan_hash`、確認値、receiptを管理する。
- `Fsync_Snapshot`: 変更対象の直前状態と実行時有効化状態を保存・検証・復元する。
- `Fsync_Apply`: user → term → file → post → comment → option/table → runtimeの順で適用し、再走査でハッシュを検証する。
- `Fsync_Job`: 転送と適用を短い再開可能ステップに分け、確認待ち・接続先job・進捗を永続化する。
- `Fsync_Auth` / `Fsync_Client`: `FSYNC1` HMAC署名、nonce、時計ずれ、capability、IP制限を担当する。
- `Fsync_Mcp`: RESTと同じサービス層をMCP tools/resources/promptsとして公開する。

## Storage

WordPress DBには`wp_fsync_`接頭辞の14テーブルを作ります。バイナリと大きなJSONは`wp-content/.flares-sync/`へ保存し、DBはID、状態、相対パス、検証ハッシュを保持します。ディレクトリにはWebアクセス拒否ファイルを置き、外部パスは正規化・境界確認します。

スキーマ更新はinitで保存済みバージョンを比較し、v0.1.0の6テーブルを保持したまま追加テーブルを`dbDelta`で作成します。全テーブルの存在確認に成功するまでスキーマバージョンは更新しません。

## Atomicity model

WordPressのDBとファイルを跨ぐ完全な単一トランザクションは使えません。その代わり、変更対象を適用前にすべてスナップショット化し、対象容量を確認してから変更を開始します。ファイルは同一ディレクトリの一時ファイルからrenameし、DB／ファイルの適用エラー、ハッシュ不一致、検証不合格時はスナップショットを即時復元します。

適用は100項目ずつ、user → term → file → post → comment → option/table → runtimeの順に進みます。投稿・分類・コメントの参照解決は全実体の作成後に別巡で行い、最後に削除と全体再走査を実行します。各HTTP要求の間は永続leaseで他の移行を排除し、中止時は接続先jobまで伝播して復元します。

## Preserved target state

`siteurl`、`home`、`wp-config.php`、WordPressソルト、暗号鍵、認証情報、ライセンス情報、cron、セッション、Yamashin WP Migration自身の有効化と接続状態は対象外です。コード有効化では当プラグインを必ず残し、移行先に存在しないプラグインは有効化しません。
