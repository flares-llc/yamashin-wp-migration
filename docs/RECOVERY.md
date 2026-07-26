# Recovery

## Automatic rollback

適用開始前に変更予定行、ファイル、plugin／theme有効化状態をsnapshot化します。次の場合は変更を停止し、同じsnapshotを自動復元します。

- objectまたはrecord hash不一致
- DB／filesystem書き込み失敗
- 未解決参照、権限不足、lock競合
- 適用後manifestの項目hash不一致
- receipt保存失敗
- PHPの致命的エラーまたは10分以上再開されないapply lease

管理画面、REST、MCPからrelease、snapshot、audit logを確認し、`rolled_back`になっていることを確認してください。

## Manual rollback

保持期間内のsnapshot IDを選び、exact IDと明示確認を付けてrollbackします。snapshot自体のcanonical hashがDB記録と一致しなければ復元を拒否します。復元は元レコードを依存順に再適用し、snapshot時に存在しなかった新規項目を削除し、runtime状態を戻します。

## Apply and rollback both failed

1. サイトをmaintenanceへ置き、追加書き込みを止める。
2. releaseの`summary.snapshot_id`と`.flares-sync/snapshots/{id}.json`を退避する。
3. object store内のsnapshot参照hashが存在し、SHA-256一致するか確認する。
4. DB／filesystem容量と権限、MariaDB error、PHP fatal logを直す。
5. 管理画面が使えなければWP-CLIから`Fsync_Snapshot::restore("snapshot_id")`を実行する。
6. `active_plugins`、theme、緊急管理者、home/site URLを確認する。
7. 復旧後に新しいdry-runを作り、古い確認値やplan hashを再利用しない。

## Runtime guard

コード切替時は一時mu-pluginがfatal shutdownを検出し、切替前のactive pluginsとthemeへ戻します。guardは短時間で失効し、正常requestのshutdown後に状態を消します。残存した`wp-content/mu-plugins/fsync-guard.php`と`fsync_runtime_guard` optionは、状態確認後に除去できます。

## Network interruption

object transferは期待offsetを応答に含むため、同じjobをcontinueします。完成済みhashの再送は成功です。applyの応答が失われた場合はrelease状態を取得し、`verified`なら再適用せずreceiptを確認します。`applying`が長時間残る場合はsnapshotとaudit logを確認してから復旧します。

適用中はmu-plugin guardが元のプラグイン・テーマ状態とsnapshot IDを保持します。致命的エラーを検知した場合、またはapply leaseが10分以上更新されなかった場合は、次にWordPressが安全に起動した時点でsnapshotを自動復元し、jobを`failed / auto_rollback`へ変更します。
