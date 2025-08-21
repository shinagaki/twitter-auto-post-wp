# Twitter/X Auto Post

WordPress記事の投稿時に自動的にTwitter/Xにも投稿するプラグインです。リンクカード表示にも対応。

## 機能

- **WordPress記事投稿時の自動Twitter/X投稿**
- **リンクカード表示対応** - タイトル、説明、サムネイル画像付き
- **改行対応の投稿フォーマット** - テキストエリアで自由にフォーマット設定
- **管理画面での簡単設定**
- **接続テスト機能** - 設定の動作確認
- **手動投稿テスト機能** - デバッグ用の手動投稿
- **重複投稿防止機能**
- **OAuth 1.0a認証対応** - セキュアな認証
- **280文字制限対応** - 自動的に文字数制限内に調整
- **手動制御機能** - 投稿ごとに個別にTwitter/X投稿を制御可能 (v1.1.0)

## 必要な準備

### Twitter Developer Account
このプラグインを使用するには、**Twitter Developer Account**が必要です。

1. [Twitter Developer Portal](https://developer.twitter.com/)にアクセス
2. アカウントを申請・承認を受ける
3. 新しいアプリケーションを作成
4. API KeysとAccess Tokensを取得

### 必要な認証情報（OAuth 1.0a）
- **API Key** (Consumer Key)
- **API Key Secret** (Consumer Secret)
- **Access Token**
- **Access Token Secret**

## インストール方法

### 方法1: GitHubからダウンロード（推奨）
1. [リリースページ](../../releases) または「Code → Download ZIP」からダウンロード
2. WordPress管理画面 → プラグイン → 新規追加 → プラグインのアップロード
3. ZIPファイルをアップロードしてインストール
4. プラグインを有効化

### 方法2: 手動アップロード
1. `twitter-auto-post-wp` フォルダを `wp-content/plugins/` にアップロード
2. WordPress管理画面の「プラグイン」ページでプラグインを有効化

### 設定
「設定」→「Twitter/X Auto Post」で設定を行う

## 設定方法

### 1. Twitter/X認証情報の設定

1. WordPress管理画面で「設定」→「Twitter/X Auto Post」を開く
2. Twitter Developer Portalで取得した以下の情報を入力：
   - **API Key** (Consumer Key)
   - **API Key Secret** (Consumer Secret)
   - **Access Token**
   - **Access Token Secret**
3. 「接続をテスト」ボタンで認証が正常に動作することを確認

### 2. 認証情報取得手順

1. [Twitter Developer Portal](https://developer.twitter.com/)でプロジェクトを作成
2. **App permissions**を「**Read and write**」に設定
3. 「Keys and tokens」タブを開く
4. 以下をコピー：
   - **Consumer Keys** → **API Key** をプラグインの「API Key」欄に入力
   - **Consumer Keys** → **API Key Secret** をプラグインの「API Key Secret」欄に入力
   - **Access Token and Secret** → **Access Token** をプラグインの「Access Token」欄に入力
   - **Access Token and Secret** → **Access Token Secret** をプラグインの「Access Token Secret」欄に入力

**重要**: App permissionsを変更した場合は、Access Tokenを「**Regenerate**」してください。

### 3. 投稿フォーマットの設定

投稿内容をカスタマイズできます。テキストエリアで改行を含む自由な形式で設定可能です。

**使用可能なプレースホルダー：**
- `{title}` - 記事のタイトル
- `{url}` - 記事のURL（リンクカード表示対応）
- `{excerpt}` - 記事の抜粋

**フォーマット例：**
```
{title}

{url}
```

デフォルト: タイトルの後に空行を入れてURL表示

**注意：** Twitter/Xの文字数制限（280文字）を考慮してフォーマットを設定してください。

### 4. 自動投稿の有効化

「自動投稿を有効にする」チェックボックスをオンにして設定を保存します。

## 使用方法

1. 設定完了後、通常通りWordPress記事を投稿
2. 記事が公開されると自動的にTwitter/Xに投稿される
3. 投稿済みの記事は重複投稿されない

## トラブルシューティング

### 投稿が自動で送信されない場合

1. プラグイン設定で「自動投稿を有効にする」がオンになっているか確認
2. 「接続をテスト」で認証情報が正しいか確認
3. WordPressのエラーログを確認（`wp-content/debug.log`）

### よくあるエラー

- **認証エラー**: API KeyとAccess Tokenが正しいか確認
- **API制限エラー**: 短時間での大量投稿を避ける
- **文字数制限**: Twitter/Xの文字数制限（280文字）を確認
- **Developer Account**: Twitter Developer Accountが承認されているか確認

### API制限について

Twitter/X APIには以下の制限があります：
- **投稿制限**: 15分間に300ツイートまで
- **認証**: OAuth 1.0a必須
- **文字数制限**: 280文字まで

## セキュリティについて

- 認証情報はWordPressのオプションテーブルに保存されます
- OAuth 1.0a認証を使用して安全に通信
- 本番環境では適切なファイルパーミッションを設定してください

## アーキテクチャ

### クラス構成

プラグインは以下の3つのクラスで構成されています：

#### `Twitter_OAuth_Signature`
- **責任**: OAuth 1.0a署名生成
- **メソッド**:
  - `generate()`: OAuth署名を生成
  - `generate_nonce()`: OAuth nonceを生成
  - `build_auth_header()`: Authorizationヘッダーを構築

#### `Twitter_API_Client`  
- **責任**: Twitter API v2との通信
- **メソッド**:
  - `request()`: APIリクエストを実行
- **特徴**: OAuth 1.0a認証を使用してセキュアに通信

#### `TwitterAutoPost`（メインクラス）
- **責任**: WordPress統合とUI管理
- **機能**:
  - WordPress admin画面の提供
  - 自動投稿の制御
  - 設定管理

### 設計原則

- **Single Responsibility Principle**: 各クラスが単一の責任を持つ
- **Dependency Injection**: API Clientをメインクラスに注入
- **Separation of Concerns**: UI、API通信、認証処理を分離

## 開発者向け情報

### フィルターフック

カスタマイズのためのフィルターフックが利用可能です：

```php
// 投稿内容をカスタマイズ
add_filter('twitter_auto_post_content', function($content, $post_id, $post) {
    // カスタム処理
    return $content;
}, 10, 3);

// 投稿条件をカスタマイズ
add_filter('twitter_auto_post_should_post', function($should_post, $post_id, $post) {
    // 特定の条件で投稿を制御
    return $should_post;
}, 10, 3);
```

### ログ

プラグインの動作ログはWordPressのエラーログに記録されます。デバッグ時は以下を `wp-config.php` に追加：

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

## ライセンス

GPL v2 or later

## サポート

問題や機能要望がございましたら、GitHubのIssuesページでお知らせください。

### テスト

プラグインの動作テストには以下の機能を活用してください：

1. **接続テスト**: 認証情報が正しく設定されているかを確認
2. **手動投稿テスト**: 実際の投稿機能をテスト
3. **WordPressデバッグログ**: 詳細なエラー情報を確認

### コード品質

- **WordPress Coding Standards準拠**
- **PHPCSによるコーディング規約チェック**
- **単体テスト可能な設計**

## Twitter API v2について

このプラグインはTwitter API v2とOAuth 1.0a認証を使用しています：

- **API v2の利点**: 最新機能とパフォーマンス
- **OAuth 1.0a**: セキュアで確実な認証方式
- **Developer Account**: 申請・承認が必要（個人利用含む）

### API制限
- 投稿制限: 15分間に300ツイートまで
- 文字数制限: 280文字まで（自動調整機能付き）