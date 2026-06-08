# OD Press Pilot

WordPress AI Client を利用した「AIお知らせ作成アシスタント」プラグインです。`@wordpress/env` で WordPress を起動し、このリポジトリ全体をプラグインとしてマウントします。

## 必要なもの

- Docker Desktop
- Node.js 20 以上
- npm

## セットアップ

```bash
npm install
npm run build
npm run env:start
```

起動後の URL は次の通りです。

- WordPress: http://localhost:8890
- 管理画面: http://localhost:8890/wp-admin
- ユーザー名: `admin`
- パスワード: `password`

## よく使うコマンド

```bash
npm run env:start
npm run env:stop
npm run wp -- plugin list
npm run wp -- plugin activate od-press-pilot
npm run lint:php
npm run build
```

## 構成

- `od-press-pilot.php`: プラグインのメインファイル
- `src/`: PHP クラスと React 管理画面
- `build/`: 管理画面アセット
- `.wp-env.json`: ローカル WordPress 環境設定

## 管理画面

- AIお知らせ作成 > コンテンツ生成
- AIお知らせ作成 > 広報プロフィール

AI Provider の API キーはこのプラグインでは保存しません。WordPress 7.0 以上の Settings > Connectors で設定された Provider を AI Client 経由で利用します。
