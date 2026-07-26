# Three-way Diff

入力は移行元manifest、移行先manifest、同じpeerかつ同じscope fingerprintの直近verified receiptです。比較単位は`kind:uid`です。

| source | target | base | 判定 |
|---|---|---|---|
| あり | なし | なし | `create` |
| あり | なし | あり | `conflict`（移行先で削除された） |
| なし | あり | なし | `unchanged`（移行元が所有しない対象側データ） |
| なし | あり | 同じ | `blocked`または明示許可時`delete` |
| なし | あり | targetだけ変更 | `conflict` |
| 同じ | 同じ | 任意 | `unchanged` |
| 両方異なる | なし | なし | `conflict` |
| sourceだけ変更 | target=base | あり | `update` |
| source=base | targetだけ変更 | あり | `unchanged` |
| sourceとtargetが別々に変更 | あり | `conflict` |

競合は`source`、`target`、`skip`を項目ごとに選びます。解決内容は`plan_hash`へ含まれ、選択変更時は一度限りの適用確認も再発行されます。

削除は最初に`blocked`として計算し、次の全条件が成立する項目だけ`delete`へ昇格します。

1. `sync.policy.allow_delete=true`
2. 対応するpost type、taxonomy、comments、users、options、table、filesの`delete=true`
3. ドライラン後、確定された削除一覧を再確認

receiptのbaselineには、この移行元が所有する項目だけを記録します。対象側だけにある項目をbaselineへ取り込まないため、次回実行で突然削除候補になることはありません。`target`または`skip`を選んだ競合はsource hashを共通基点として記録し、同じ差異を毎回再提案しません。
