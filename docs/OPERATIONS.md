# Operations

## Environment topology

推奨はlocal → staging → productionです。各操作は明示的な一方向releaseであり、productionへ送る前に同一のsource manifestをstagingで検証したreceiptを昇格ゲートとして確認します。

## Routine migration

1. 両サイトのWordPress版、PHP下限、空き容量、暗号化canary、受信設定を診断する。
2. 移行先ごとに最小capabilityの接続キーを使う。
3. fullまたはcontent profileでreleaseを作る。
4. jobを確認待ちまで進める。中断時は同じjobをcontinueする。
5. preflight、件数、容量、競合、blocked／delete、未登録テーブルを確認する。
6. 競合を項目ごとに解決し、削除一覧を別操作で確定する。
7. 新しい`plan_hash`を人が確認してapplyする。
8. verified receipt、manifest root、snapshot IDを保存する。
9. サイトのログイン、公開ページ、管理画面、画像、cron、外部連携を確認する。

## Code migration

WordPress本体は同一バージョンだけです。バージョン更新は別工程に分離します。プラグイン／テーマのPHP互換性をstagingで確認し、productionには同じreleaseの検証結果を用います。当プラグイン自身、`wp-config.php`、`.htaccess`、秘密情報は置き換えません。

## Key rotation

新しい接続キーをpendingで発行し、所有確認後にactive化します。旧キーは短いgrace期間だけ残し、接続確認後にretireします。MCPトークンは用途ごとに分け、不要になった時点で即時失効します。

## Retention

安全snapshotの既定保持は7日です。保存先容量を監視し、verified receiptとrelease metadataは監査に必要な期間保持します。アンインストールはDB状態を削除しますが、`.flares-sync`のsnapshot／objectは明示opt-inなしに消しません。

## Promotion gate

production適用前に、同じsource manifest root、scope fingerprint、config hashのreleaseがstagingでverifiedであることを人または運用AIが照合します。URLや秘密値をreleaseへ埋め込まないため、環境固有値はproduction側に保持されます。
