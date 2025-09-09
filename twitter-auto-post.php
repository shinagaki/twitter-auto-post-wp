<?php
/**
 * Plugin Name: Twitter/X Auto Post
 * Plugin URI: https://github.com/shinagaki/twitter-auto-post-wp
 * Description: WordPressの記事投稿時に自動的にTwitter/Xにも投稿するプラグイン。リンクカード表示にも対応
 * Version: 1.1.1
 * Author: Shintaro Inagaki
 * Author URI: https://creco.net/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: twitter-auto-post
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 *
 * @package Twitter_Auto_Post
 */

// 直接アクセスを防ぐ
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// プラグインの定数を定義
define( 'TWITTER_AUTO_POST_VERSION', '1.1.1' );
define( 'TWITTER_AUTO_POST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'TWITTER_AUTO_POST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Twitter OAuth 1.0a 署名生成クラス
 *
 * @since 1.0.0
 */
class Twitter_OAuth_Signature {

	/**
	 * OAuth 1.0a署名を生成
	 *
	 * @param string $method HTTPメソッド
	 * @param string $url リクエストURL
	 * @param array  $params パラメータ
	 * @param string $consumer_secret Consumer Secret
	 * @param string $token_secret Token Secret
	 * @return string 署名
	 */
	public function generate( $method, $url, $params, $consumer_secret, $token_secret ) {
		ksort( $params );

		$param_string = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		$base_string  = strtoupper( $method ) . '&' . rawurlencode( $url ) . '&' . rawurlencode( $param_string );
		$signing_key  = rawurlencode( $consumer_secret ) . '&' . rawurlencode( $token_secret );

		return base64_encode( hash_hmac( 'sha1', $base_string, $signing_key, true ) );
	}

	/**
	 * OAuth nonceを生成
	 *
	 * @return string
	 */
	public function generate_nonce() {
		return md5( microtime() . wp_rand() );
	}

	/**
	 * OAuth Authorizationヘッダーを生成
	 *
	 * @param array $oauth_params OAuthパラメータ
	 * @return string Authorizationヘッダー
	 */
	public function build_auth_header( $oauth_params ) {
		$auth_header_parts = array();
		foreach ( $oauth_params as $key => $value ) {
			$auth_header_parts[] = rawurlencode( $key ) . '="' . rawurlencode( $value ) . '"';
		}
		return 'OAuth ' . implode( ', ', $auth_header_parts );
	}
}

/**
 * Twitter API クライアントクラス
 *
 * @since 1.0.0
 */
class Twitter_API_Client {

	/**
	 * API エンドポイント
	 *
	 * @var string
	 */
	private $api_endpoint = 'https://api.twitter.com/2/';

	/**
	 * OAuth署名生成インスタンス
	 *
	 * @var Twitter_OAuth_Signature
	 */
	private $oauth_signature;

	/**
	 * 認証情報
	 *
	 * @var array
	 */
	private $credentials;

	/**
	 * コンストラクタ
	 *
	 * @param array $credentials 認証情報
	 */
	public function __construct( $credentials ) {
		$this->oauth_signature = new Twitter_OAuth_Signature();
		$this->credentials     = $credentials;
	}

	/**
	 * APIリクエスト実行
	 *
	 * @param string $endpoint APIエンドポイント
	 * @param array  $data リクエストデータ
	 * @param string $method HTTPメソッド
	 * @return array|WP_Error APIレスポンスまたはエラー
	 */
	public function request( $endpoint, $data = array(), $method = 'GET' ) {
		$url = $this->api_endpoint . $endpoint;

		if ( empty( $this->credentials['api_key'] ) || empty( $this->credentials['api_secret'] ) ||
			empty( $this->credentials['access_token'] ) || empty( $this->credentials['access_token_secret'] ) ) {
			return new WP_Error( 'missing_credentials', 'OAuth 1.0a認証情報が設定されていません。' );
		}

		$oauth_params = array(
			'oauth_consumer_key'     => $this->credentials['api_key'],
			'oauth_nonce'            => $this->oauth_signature->generate_nonce(),
			'oauth_signature_method' => 'HMAC-SHA1',
			'oauth_timestamp'        => time(),
			'oauth_token'            => $this->credentials['access_token'],
			'oauth_version'          => '1.0',
		);

		$all_params = $oauth_params;
		if ( 'GET' === $method && ! empty( $data ) ) {
			$all_params = array_merge( $oauth_params, $data );
		}

		$signature = $this->oauth_signature->generate(
			$method,
			$url,
			$all_params,
			$this->credentials['api_secret'],
			$this->credentials['access_token_secret']
		);

		$oauth_params['oauth_signature'] = $signature;
		$auth_header                     = $this->oauth_signature->build_auth_header( $oauth_params );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => $auth_header,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( 'POST' === $method ) {
			$args['body'] = wp_json_encode( $data );
		} elseif ( 'GET' === $method && ! empty( $data ) ) {
			$url .= '?' . http_build_query( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code >= 400 ) {
			$body          = wp_remote_retrieve_body( $response );
			$error_data    = json_decode( $body, true );
			$error_message = isset( $error_data['detail'] ) ? $error_data['detail'] : 'Unknown error';
			if ( isset( $error_data['errors'] ) && is_array( $error_data['errors'] ) ) {
				$error_message = $error_data['errors'][0]['message'] ?? $error_message;
			}
			return new WP_Error( 'api_error', "HTTP {$response_code}: {$error_message}" );
		}

		return $response;
	}
}

/**
 * Twitter Auto Post プラグインのメインクラス
 *
 * WordPress記事の投稿時に自動的にTwitter/Xにも投稿する機能を提供
 * リンクカード表示、画像のアップロード、設定画面なども含む
 *
 * @since 1.0.0
 */
class TwitterAutoPost {

	/**
	 * Twitter API クライアント
	 *
	 * @var Twitter_API_Client
	 */
	private $api_client;

	/**
	 * コンストラクタ
	 *
	 * WordPressのフックを設定し、プラグインを初期化
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'wp_ajax_twitter_test_connection', array( $this, 'test_connection' ) );
		add_action( 'wp_ajax_twitter_manual_post', array( $this, 'manual_post' ) );
		add_action( 'publish_post', array( $this, 'auto_post_to_twitter' ) );

		// 投稿画面にMeta Boxを追加
		add_action( 'add_meta_boxes', array( $this, 'add_post_meta_boxes' ) );
		add_action( 'save_post', array( $this, 'save_post_meta_data' ) );

		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
	}

	/**
	 * API クライアントを取得
	 *
	 * @return Twitter_API_Client
	 */
	private function get_api_client() {
		if ( ! $this->api_client ) {
			$credentials      = $this->get_credentials();
			$this->api_client = new Twitter_API_Client( $credentials );
		}
		return $this->api_client;
	}

	/**
	 * 認証情報を取得
	 *
	 * @return array
	 */
	private function get_credentials() {
		return array(
			'api_key'             => get_option( 'twitter_api_key', '' ),
			'api_secret'          => get_option( 'twitter_api_secret', '' ),
			'access_token'        => get_option( 'twitter_access_token', '' ),
			'access_token_secret' => get_option( 'twitter_access_token_secret', '' ),
		);
	}

	/**
	 * プラグイン初期化処理
	 *
	 * @since 1.0.0
	 */
	public function init() {
		load_plugin_textdomain( 'twitter-auto-post', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}

	/**
	 * プラグイン有効化時の処理
	 *
	 * @since 1.0.0
	 */
	public function activate() {
		// 初期設定
		add_option( 'twitter_auto_post_enabled', false );
		add_option( 'twitter_post_format', "{title}\n\n{url}" );
	}

	/**
	 * プラグイン無効化時の処理
	 *
	 * @since 1.0.0
	 */
	public function deactivate() {
		// 必要に応じてクリーンアップ処理
	}

	/**
	 * 管理画面メニューを追加
	 *
	 * 設定ページを管理画面に追加します
	 *
	 * @since 1.0.0
	 */
	public function add_admin_menu() {
		add_options_page(
			'Twitter/X Auto Post Settings',
			'Twitter/X Auto Post',
			'manage_options',
			'twitter-auto-post',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * 管理画面初期化処理
	 *
	 * 設定フィールドとセクションを登録します
	 *
	 * @since 1.0.0
	 */
	public function admin_init() {
		register_setting( 'twitter_auto_post_settings', 'twitter_auto_post_enabled' );
		register_setting( 'twitter_auto_post_settings', 'twitter_api_key' );
		register_setting( 'twitter_auto_post_settings', 'twitter_api_secret' );
		register_setting( 'twitter_auto_post_settings', 'twitter_access_token' );
		register_setting( 'twitter_auto_post_settings', 'twitter_access_token_secret' );
		register_setting( 'twitter_auto_post_settings', 'twitter_post_format' );

		add_settings_section(
			'twitter_auto_post_main',
			'Twitter/X接続設定',
			array( $this, 'settings_section_callback' ),
			'twitter-auto-post'
		);

		add_settings_field(
			'twitter_auto_post_enabled',
			'自動投稿を有効にする',
			array( $this, 'enabled_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);

		add_settings_field(
			'twitter_api_key',
			'API Key',
			array( $this, 'api_key_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);

		add_settings_field(
			'twitter_api_secret',
			'API Secret',
			array( $this, 'api_secret_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);

		add_settings_field(
			'twitter_access_token',
			'Access Token',
			array( $this, 'access_token_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);

		add_settings_field(
			'twitter_access_token_secret',
			'Access Token Secret',
			array( $this, 'access_token_secret_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);

		add_settings_field(
			'twitter_post_format',
			'投稿フォーマット',
			array( $this, 'format_field_callback' ),
			'twitter-auto-post',
			'twitter_auto_post_main'
		);
	}

	/**
	 * 設定セクションのコールバック
	 *
	 * 設定ページのセクションに表示する説明文を出力
	 *
	 * @since 1.0.0
	 */
	public function settings_section_callback() {
		echo '<p>Twitter/Xアカウントの接続設定を行ってください。</p>';
		echo '<p><strong>注意:</strong> Twitter API v2の利用にはTwitter Developer Accountが必要です。</p>';
	}

	/**
	 * 自動投稿有効化フィールドのコールバック
	 *
	 * 自動投稿を有効化するチェックボックスを表示
	 *
	 * @since 1.0.0
	 */
	public function enabled_field_callback() {
		$enabled = get_option( 'twitter_auto_post_enabled', false );
		echo '<input type="checkbox" name="twitter_auto_post_enabled" value="1" ' . checked( 1, $enabled, false ) . ' />';
	}

	/**
	 * API Keyフィールドのコールバック
	 *
	 * API Keyの入力フィールドを表示
	 *
	 * @since 1.0.0
	 */
	public function api_key_field_callback() {
		$api_key = get_option( 'twitter_api_key', '' );
		echo '<input type="text" name="twitter_api_key" value="' . esc_attr( $api_key ) . '" size="50" />';
		echo '<p class="description">Keys and tokens → Consumer Keys → API Keyを入力してください。</p>';
	}

	/**
	 * API Secretフィールドのコールバック
	 *
	 * API Secretの入力フィールドを表示
	 *
	 * @since 1.0.0
	 */
	public function api_secret_field_callback() {
		$api_secret = get_option( 'twitter_api_secret', '' );
		echo '<input type="password" name="twitter_api_secret" value="' . esc_attr( $api_secret ) . '" size="50" />';
		echo '<p class="description">Keys and tokens → Consumer Keys → API Key Secretを入力してください。</p>';
	}

	/**
	 * Access Tokenフィールドのコールバック
	 *
	 * Access Tokenの入力フィールドを表示
	 *
	 * @since 1.0.0
	 */
	public function access_token_field_callback() {
		$access_token = get_option( 'twitter_access_token', '' );
		echo '<input type="password" name="twitter_access_token" value="' . esc_attr( $access_token ) . '" size="70" />';
		echo '<p class="description">Keys and tokens → Access Token and Secretを入力してください。</p>';
	}

	/**
	 * Access Token Secretフィールドのコールバック
	 *
	 * Access Token Secretの入力フィールドを表示
	 *
	 * @since 1.0.0
	 */
	public function access_token_secret_field_callback() {
		$access_token_secret = get_option( 'twitter_access_token_secret', '' );
		echo '<input type="password" name="twitter_access_token_secret" value="' . esc_attr( $access_token_secret ) . '" size="50" />';
		echo '<p class="description">Keys and tokens → Access Token and Secret → Access Token Secretを入力してください。</p>';
	}

	/**
	 * フォーマットフィールドのコールバック
	 *
	 * 投稿フォーマットのテキストエリアを表示
	 *
	 * @since 1.0.0
	 */
	public function format_field_callback() {
		$format = get_option( 'twitter_post_format', "{title}\n\n{url}" );
		echo '<textarea name="twitter_post_format" rows="5" cols="50">' . esc_textarea( $format ) . '</textarea>';
		echo '<p class="description">使用可能なプレースホルダー: {title}, {url}, {excerpt}</p>';
		echo '<p class="description">注意: Twitter/Xの文字数制限（280文字）にご注意ください。</p>';
	}

	/**
	 * 管理画面ページの表示
	 *
	 * プラグインの設定画面を表示します
	 *
	 * @since 1.0.0
	 */
	public function admin_page() {
		?>
		<div class="wrap">
			<h1>Twitter/X Auto Post 設定</h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'twitter_auto_post_settings' );
				do_settings_sections( 'twitter-auto-post' );
				submit_button();
				?>
			</form>
			
			<h2>接続テスト</h2>
			<p>設定した認証情報でTwitter/Xに接続できるかテストします。</p>
			<button id="twitter-test-connection" class="button button-secondary">接続をテスト</button>
			<div id="twitter-test-result"></div>
			
			<h2>手動投稿テスト</h2>
			<p>テスト投稿を送信してプラグインが正常に動作するかテストします。</p>
			<button id="twitter-manual-post" class="button button-secondary">テスト投稿を送信</button>
			<div id="twitter-manual-result"></div>
		</div>

		<script>
		document.getElementById('twitter-test-connection').addEventListener('click', function() {
			const resultDiv = document.getElementById('twitter-test-result');
			resultDiv.innerHTML = '<p>接続テスト中...</p>';
			
			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=twitter_test_connection&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'twitter_test_nonce' ) ); ?>'
			})
			.then(response => response.json())
			.then(data => {
				resultDiv.innerHTML = '<p style="color: ' + (data.success ? 'green' : 'red') + '">' + data.data + '</p>';
			});
		});

		document.getElementById('twitter-manual-post').addEventListener('click', function() {
			const resultDiv = document.getElementById('twitter-manual-result');
			resultDiv.innerHTML = '<p>テスト投稿送信中...</p>';
			
			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=twitter_manual_post&_ajax_nonce=<?php echo esc_js( wp_create_nonce( 'twitter_manual_nonce' ) ); ?>'
			})
			.then(response => response.json())
			.then(data => {
				resultDiv.innerHTML = '<p style="color: ' + (data.success ? 'green' : 'red') + '">' + data.data + '</p>';
			});
		});
		</script>
		<?php
	}

	/**
	 * 接続テスト処理
	 *
	 * AJAX経由で呼び出され、Twitter/Xへの接続をテストします
	 *
	 * @since 1.0.0
	 */
	public function test_connection() {
		check_ajax_referer( 'twitter_test_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'twitter-auto-post' ), '', array( 'response' => 403 ) );
		}

		$credentials = $this->get_credentials();
		if ( empty( $credentials['api_key'] ) || empty( $credentials['api_secret'] ) ||
			empty( $credentials['access_token'] ) || empty( $credentials['access_token_secret'] ) ) {
			wp_send_json_error( '認証情報が入力されていません。API Key、API Secret、Access Token、Access Token Secretをすべて入力してください。' );
		}

		$api_client = $this->get_api_client();
		$response   = $api_client->request( 'users/me', array(), 'GET' );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( 'Twitter/X API接続エラー: ' . $response->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( isset( $data['data']['username'] ) ) {
			wp_send_json_success( 'Twitter/Xに正常に接続されました。ユーザー名: @' . $data['data']['username'] );
		} else {
			wp_send_json_error( 'Twitter/X API認証に失敗しました。認証情報を確認してください。' );
		}
	}

	/**
	 * 手動投稿テスト処理
	 *
	 * AJAX経由で呼び出され、テスト投稿を送信します
	 *
	 * @since 1.0.0
	 */
	public function manual_post() {
		check_ajax_referer( 'twitter_manual_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'twitter-auto-post' ), '', array( 'response' => 403 ) );
		}

		$test_text = 'WordPress Twitter/X Auto Post プラグインのテスト投稿です。' . gmdate( 'Y-m-d H:i:s' );
		$result    = $this->post_to_twitter( $test_text );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( 'Twitter/X投稿エラー: ' . $result->get_error_message() );
		} else {
			wp_send_json_success( 'テスト投稿が正常に送信されました。' );
		}
	}

	/**
	 * WordPress記事公開時の自動投稿処理
	 *
	 * WordPressの記事が公開された際に自動的にTwitter/Xに投稿します
	 *
	 * @param int $post_id 投稿ID
	 *
	 * @since 1.0.0
	 */
	public function auto_post_to_twitter( $post_id ) {
		// 自動投稿が有効でない場合は処理をスキップ
		if ( ! get_option( 'twitter_auto_post_enabled', false ) ) {
			return;
		}

		// リビジョンや自動保存は無視
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		// 既に投稿済みかチェック
		if ( get_post_meta( $post_id, '_twitter_posted', true ) ) {
			return;
		}

		// 手動制御チェック：チェックボックスがオフの場合はスキップ
		$manual_control = get_post_meta( $post_id, '_twitter_manual_post', true );
		if ( '0' === $manual_control ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return;
		}

		// カスタムフィルターで投稿を制御
		if ( ! apply_filters( 'twitter_auto_post_should_post', true, $post_id, $post ) ) {
			return;
		}

		// 投稿内容を生成
		$content = $this->generate_post_content( $post );
		$content = apply_filters( 'twitter_auto_post_content', $content, $post_id, $post );

		// Twitter/Xに投稿
		$result = $this->post_to_twitter( $content );

		if ( ! is_wp_error( $result ) ) {
			// 投稿済みフラグを設定
			update_post_meta( $post_id, '_twitter_posted', true );
			error_log( "Twitter Auto Post: 投稿ID {$post_id} をTwitter/Xに自動投稿しました。" );
		} else {
			error_log( "Twitter Auto Post: 投稿ID {$post_id} のTwitter/X自動投稿に失敗しました: " . $result->get_error_message() );
		}
	}

	/**
	 * 投稿内容を生成
	 *
	 * WordPress記事からTwitter/X投稿用のテキストを生成します
	 *
	 * @param WP_Post $post WordPress記事オブジェクト
	 * @return string 投稿用テキスト
	 *
	 * @since 1.0.0
	 */
	private function generate_post_content( $post ) {
		$format = get_option( 'twitter_post_format', "{title}\n\n{url}" );

		$placeholders = array(
			'{title}'   => $post->post_title,
			'{url}'     => get_permalink( $post ),
			'{excerpt}' => wp_trim_words( wp_strip_all_tags( $post->post_content ), 20 ),
		);

		$content = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $format );

		// 280文字制限を考慮
		if ( mb_strlen( $content ) > 280 ) {
			$content = mb_substr( $content, 0, 277 ) . '...';
		}

		return $content;
	}

	/**
	 * Twitter/Xに投稿
	 *
	 * Twitter API v2を使用してツイートを投稿します
	 *
	 * @param string $text 投稿テキスト
	 * @return array|WP_Error 投稿結果またはエラー
	 *
	 * @since 1.0.0
	 */
	private function post_to_twitter( $text ) {
		$api_client = $this->get_api_client();
		$data       = array( 'text' => $text );

		return $api_client->request( 'tweets', $data, 'POST' );
	}

	/**
	 * 投稿編集画面にMeta Boxを追加
	 *
	 * @since 1.1.0
	 */
	public function add_post_meta_boxes() {
		add_meta_box(
			'twitter_post_control',
			'Twitter/X投稿設定',
			array( $this, 'post_meta_box_callback' ),
			'post',
			'side',
			'default'
		);
	}

	/**
	 * Meta Boxの内容を表示
	 *
	 * @since 1.1.0
	 * @param WP_Post $post 投稿オブジェクト
	 */
	public function post_meta_box_callback( $post ) {
		// Nonceフィールドを追加
		wp_nonce_field( 'twitter_post_meta_nonce', 'twitter_post_meta_nonce' );

		// 現在の設定を取得
		$already_posted = get_post_meta( $post->ID, '_twitter_posted', true );
		$manual_control = get_post_meta( $post->ID, '_twitter_manual_post', true );

		// デフォルト状態を決定（未ポストならオン、ポスト済みならオフ）
		if ( '' === $manual_control ) {
			$manual_control = empty( $already_posted ) ? '1' : '0';
		}

		echo '<div style="margin: 10px 0;">';
		echo '<label style="display: flex; align-items: center; gap: 8px;">';
		$disabled = ! empty( $already_posted ) ? ' disabled' : '';
		echo '<input type="checkbox" name="twitter_manual_post" value="1" ' . checked( $manual_control, '1', false ) . $disabled . '>';
		echo '<span>Twitter/Xに投稿する</span>';
		echo '</label>';

		if ( ! empty( $already_posted ) ) {
			echo '<p style="margin: 8px 0 0 0; color: #666; font-size: 12px;">（既に投稿済み）</p>';
		} else {
			echo '<p style="margin: 8px 0 0 0; color: #666; font-size: 12px;">（まだ投稿されていません）</p>';
		}

		// プラグインが無効の場合の警告
		if ( ! get_option( 'twitter_auto_post_enabled', false ) ) {
			echo '<p style="margin: 8px 0 0 0; color: #d63638; font-size: 12px;">⚠ プラグイン設定で自動投稿が無効になっています</p>';
		}

		echo '</div>';
	}

	/**
	 * 投稿保存時にMeta Dataを保存
	 *
	 * @since 1.1.0
	 * @param int $post_id 投稿ID
	 */
	public function save_post_meta_data( $post_id ) {
		// Nonce確認
		if ( ! isset( $_POST['twitter_post_meta_nonce'] ) ||
			! wp_verify_nonce( sanitize_key( $_POST['twitter_post_meta_nonce'] ), 'twitter_post_meta_nonce' ) ) {
			return;
		}

		// 自動保存時はスキップ
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// 権限確認
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// postタイプのみ処理
		if ( 'post' !== get_post_type( $post_id ) ) {
			return;
		}

		// チェックボックスの値を保存
		$manual_post = isset( $_POST['twitter_manual_post'] ) && '1' === $_POST['twitter_manual_post'] ? '1' : '0';
		update_post_meta( $post_id, '_twitter_manual_post', $manual_post );
	}
}

// プラグインを初期化
new TwitterAutoPost();