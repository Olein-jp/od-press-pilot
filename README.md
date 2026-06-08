# OD Press Pilot

WordPress プラグイン開発用のローカル環境です。`@wordpress/env` で WordPress を起動し、このリポジトリ全体をプラグインとしてマウントします。

## 必要なもの

- Docker Desktop
- Node.js 20 以上
- npm

## セットアップ

```bash
npm install
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
```

## 構成

- `od-press-pilot.php`: プラグインのメインファイル
- `.wp-env.json`: ローカル WordPress 環境設定
