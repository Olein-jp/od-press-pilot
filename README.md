# OD Press Pilot

WordPress AI Client を利用した「AIお知らせ作成アシスタント」プラグインです。`@wordpress/env` で WordPress を起動し、このリポジトリ全体をプラグインとしてマウントします。

## 必要なもの

- Docker Desktop
- Node.js 20 以上
- npm
- Composer

## セットアップ

```bash
npm install
composer install
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
bash scripts/build-release-zip.sh 0.1.0
```

## 構成

- `od-press-pilot.php`: プラグインのメインファイル
- `src/`: PHP クラスと React 管理画面
- `build/`: 管理画面アセット
- `vendor/`: Composer 依存（Release zip に同梱）
- `.wp-env.json`: ローカル WordPress 環境設定

## 管理画面

- AIお知らせ作成 > コンテンツ生成
- AIお知らせ作成 > 広報プロフィール

AI Provider の API キーはこのプラグインでは保存しません。WordPress 7.0 以上の Settings > Connectors で設定された Provider を AI Client 経由で利用します。OpenAI、Google (Gemini)、Anthropic など複数の AI Provider が接続済みの場合は、コンテンツ生成画面で使用する Provider を選択できます。

## リリース

`main` の履歴上にあるコミットへ `*.*.*` 形式のタグを push すると、GitHub Actions が Release を作成し、配布用 zip を添付します。

```bash
git tag 0.1.0
git push origin 0.1.0
```

配布 zip は `od-press-pilot/` ディレクトリを含む WordPress プラグイン形式で作成されます。`node_modules/` や `.wp-env/` は含めず、管理画面アセットの `build/` と Composer 依存の `vendor/` を含めます。

管理画面からのアップデート確認には `inc2734/wp-github-plugin-updater` を利用します。GitHub Releases の最新リリースに添付された zip が、WordPress のプラグイン更新パッケージとして使われます。
