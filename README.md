# Rocket Martue Post Importer

WordPress REST API 経由で、任意のサイトの投稿・カテゴリー・ユーザーを開発環境にインポートする WordPress プラグインです。

---

## 概要

本番サイトのコンテンツを開発環境に素早く移行するためのツールです。  
WordPress の REST API (`/wp-json/wp/v2/`) を利用してデータを取得し、slug ベースの重複チェックにより何度実行しても安全にインポートできます。

---

## 機能

- カテゴリー・ユーザー・投稿を段階的にインポート
- **ドライラン機能** — 実際の変更を行わずに処理内容を事前確認
- slug ベースの重複チェック（2回実行しても二重登録されない）
- ユーザーマッピング設定（ソースサイトの author ID と開発環境ユーザーの紐付け）
- インポート済み投稿にメタ情報（`_imported_from` / `_imported_post_id` / `_imported_at`）を付与
- Nonce 検証 + 管理者権限チェックによるセキュリティ保護

---

## 動作環境

| 項目 | バージョン |
|---|---|
| PHP | 7.4 以上 |
| WordPress | 制限なし（REST API が有効なこと） |

---

## インストール

1. このリポジトリを `wp-content/plugins/rocket-martue-post-importer/` に配置します。

```
wp-content/plugins/
└── rocket-martue-post-importer/
    ├── rocket-martue-post-importer.php
    ├── class-rocket-martue-post-importer.php
    ├── composer.json
    └── phpcs.xml
```

2. WordPress 管理画面 → **プラグイン** から「Rocket Martue Post Importer」を有効化します。

---

## 使い方

1. 管理画面 → **ツール** → **RM Post インポート** を開きます。
2. **ソースサイト URL** にインポート元サイトの URL を入力します（末尾スラッシュなし）。
3. **ユーザーマッピング設定** でソースサイトの投稿者と開発環境のユーザーを対応付けます。
4. まず **ドライラン** にチェックを入れた状態で実行し、処理内容を確認します。
5. 問題がなければドライランのチェックを外して **インポート実行** します。

---

## インポートの処理順序

```
Step 1: カテゴリー
  /wp-json/wp/v2/categories から取得 → slug 重複チェック → 新規作成

Step 2: ユーザー
  /wp-json/wp/v2/users から取得 → ログイン名重複チェック → 新規作成

Step 3: 投稿
  /wp-json/wp/v2/posts から全投稿取得（100件/ページでページネーション）
  → slug 重複チェック → カテゴリー・ユーザーをマッピングして登録
```

---

## ユーザーマッピングについて

ソースサイトの `author` ID（数値）に対して、開発環境に作成するユーザー情報を管理画面から設定できます。

| 項目 | 説明 |
|---|---|
| ログイン名 | 開発環境での `user_login` |
| メールアドレス | 開発環境での `user_email` |
| 表示名 | 開発環境での `display_name`（API で取得できた場合は上書きされる） |

既に同じログイン名のユーザーが存在する場合は、そのユーザーが投稿者として使用されます。

---

## インポート済みメタ情報

各投稿に以下のカスタムフィールドが付与されます。

| メタキー | 内容 |
|---|---|
| `_imported_from` | ソースサイトの URL |
| `_imported_post_id` | ソースサイトでの投稿 ID |
| `_imported_at` | インポート実行日時（MySQL 形式） |

---

## 開発

### コーディングスタンダード

[WordPress Coding Standards](https://developer.wordpress.org/coding-standards/) 3.0.0 以降に準拠しています。  
設定は [phpcs.xml](phpcs.xml) を参照してください。

### 依存パッケージのインストール

```bash
composer install
```

### Lint 実行

```bash
# チェックのみ
composer phpcs

# 自動修正
composer phpcbf
```

---

## ライセンス

[GPL-2.0-or-later](https://www.gnu.org/licenses/old-licenses/gpl-2.0.html)
