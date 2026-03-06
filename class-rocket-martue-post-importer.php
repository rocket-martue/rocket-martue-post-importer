<?php
/**
 * Rocket Martue Post Importer プラグイン
 *
 * REST API 経由でソースサイトから投稿・カテゴリー・ユーザーをインポートするプラグイン。
 *
 * @package Rocket_Martue_Post_Importer
 * @since 1.0.0
 * @license GPL-2.0-or-later
 */

declare( strict_types=1 );

/*
 * Exit if accessed directly.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rocket Martue Post Importer クラス
 *
 * REST API 経由でソースサイトからのデータインポートを管理します。
 * カテゴリー・ユーザー・投稿の段階的なインポート処理と、
 * ドライラン機能による事前確認をサポートしています。
 *
 * @since 1.0.0
 */
class Rocket_Martue_Post_Importer {

	/** ソースサイト URL を保存するオプション名 */
	const OPTION_SOURCE_URL = 'rocket_martue_source_url';

	/** 管理メニュースラッグ */
	const MENU_SLUG = 'rocket-martue-importer';

	/** Nonce アクション名 */
	const NONCE_ACTION = 'rocket_martue_import_action';

	/**
	 * コンストラクタ
	 *
	 * 管理画面のメニュー追加とインポート処理のアクションをフックします。
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_import' ) );
	}

	/**
	 * 管理メニューに追加（ツール配下）
	 */
	public function add_menu(): void {
		add_management_page(
			'RM Post インポート',
			'RM Post インポート',
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	// =============================================
	// API 取得
	// =============================================

	/**
	 * ソースサイト URL を取得する
	 *
	 * @return string 保存済みの URL、未設定時はデフォルト値.
	 */
	private function get_source_url(): string {
		return (string) get_option( self::OPTION_SOURCE_URL, 'https://example.com' );
	}

	/**
	 * REST API からデータ取得
	 *
	 * @param string $endpoint API エンドポイント（例: 'posts?per_page=100'）.
	 *
	 * @return array json_decode の結果、または空配列.
	 */
	private function fetch_api( string $endpoint ): array {
		$url = $this->get_source_url() . '/wp-json/wp/v2/' . $endpoint;

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $decoded ) ? $decoded : array();
	}

	// =============================================
	// インポート処理
	// =============================================

	/**
	 * フォーム送信時のインポート実行
	 */
	public function handle_import(): void {
		if ( ! isset( $_POST['rm_post_import_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}

		check_admin_referer( self::NONCE_ACTION );

		// ソースサイト URL を保存.
		if ( isset( $_POST['source_url'] ) ) {
			update_option( self::OPTION_SOURCE_URL, esc_url_raw( wp_unslash( $_POST['source_url'] ) ) );
		}

		$dry_run = isset( $_POST['dry_run'] );
		$log     = array();

		// --- ユーザーマッピング設定 ---
		$user_config = array(
			2 => array(
				'user_login'   => sanitize_text_field( wp_unslash( $_POST['user_login_2'] ?? 'rm_post_author_2' ) ),
				'user_email'   => sanitize_email( wp_unslash( $_POST['user_email_2'] ?? 'author2@example.com' ) ),
				'display_name' => sanitize_text_field( wp_unslash( $_POST['user_display_2'] ?? 'Author 2' ) ),
				'role'         => 'editor',
			),
			3 => array(
				'user_login'   => sanitize_text_field( wp_unslash( $_POST['user_login_3'] ?? 'rm_post_author_3' ) ),
				'user_email'   => sanitize_email( wp_unslash( $_POST['user_email_3'] ?? 'author3@example.com' ) ),
				'display_name' => sanitize_text_field( wp_unslash( $_POST['user_display_3'] ?? 'Author 3' ) ),
				'role'         => 'editor',
			),
		);

		// ① カテゴリーインポート
		$log[]        = array( 'heading', 'Step 1: カテゴリー' );
		$category_map = $this->import_categories( $log, $dry_run );

		// ② ユーザーインポート
		$log[]      = array( 'heading', 'Step 2: ユーザー' );
		$author_map = $this->import_users( $user_config, $log, $dry_run );

		// ③ 投稿インポート
		$log[] = array( 'heading', 'Step 3: 投稿' );
		$this->import_posts( $category_map, $author_map, $log, $dry_run );

		// 結果をトランジェントに保存 → 画面に表示
		set_transient( 'rm_post_import_log', $log, 300 );

		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG . '&done=1' ) );
		exit;
	}

	/**
	 * カテゴリーインポート
	 *
	 * @param array $log     ログエントリの配列（参照渡し）.
	 * @param bool  $dry_run true のとき実際の変更を行わない.
	 * @return array ソース ID → ローカル term_id のマッピング.
	 */
	private function import_categories( array &$log, bool $dry_run ): array {
		$category_map = array();
		$categories   = $this->fetch_api( 'categories?per_page=100' );

		if ( empty( $categories ) ) {
			$log[]           = array( 'warn', 'カテゴリーを取得できませんでした。デフォルト (ID:1) を使用します。' );
			$category_map[1] = 1;
			return $category_map;
		}

		foreach ( $categories as $cat ) {
			$sid  = $cat['id'];
			$name = $cat['name'];
			$slug = $cat['slug'];

			$existing = get_term_by( 'slug', $slug, 'category' );
			if ( $existing ) {
				$category_map[ $sid ] = $existing->term_id;
				$log[]                = array( 'info', "カテゴリー「{$name}」→ 既存使用 (ID:{$existing->term_id})" );
				continue;
			}

			if ( $dry_run ) {
				$log[]                = array( 'info', "[ドライラン] カテゴリー「{$name}」を作成予定" );
				$category_map[ $sid ] = 1;
				continue;
			}

			$result = wp_insert_term(
				$name,
				'category',
				array(
					'slug'        => $slug,
					'description' => $cat['description'] ?? '',
				)
			);

			if ( is_wp_error( $result ) ) {
				$log[]                = array( 'error', "カテゴリー「{$name}」作成失敗: " . $result->get_error_message() );
				$category_map[ $sid ] = 1;
			} else {
				$category_map[ $sid ] = $result['term_id'];
				$log[]                = array( 'ok', "カテゴリー「{$name}」を作成 (ID:{$result['term_id']})" );
			}
		}

		return $category_map;
	}

	/**
	 * ユーザーインポート
	 *
	 * @param array $user_config ユーザー設定の配列（ソース ID をキーとする）.
	 * @param array $log         ログエントリの配列（参照渡し）.
	 * @param bool  $dry_run     true のとき実際の変更を行わない.
	 * @return array ソース author_id → ローカル user_id のマッピング.
	 */
	private function import_users( array $user_config, array &$log, bool $dry_run ): array {
		$author_map = array();

		// API からユーザー公開情報を取得して display_name を補完
		$api_users = $this->fetch_api( 'users?per_page=100' );

		foreach ( $user_config as $source_id => $data ) {
			// API の公開名があれば上書き
			foreach ( $api_users as $u ) {
				if ( (int) $u['id'] === $source_id && ! empty( $u['name'] ) ) {
					$data['display_name'] = $u['name'];
					break;
				}
			}

			$existing = get_user_by( 'login', $data['user_login'] );
			if ( $existing ) {
				$author_map[ $source_id ] = $existing->ID;
				$log[]                    = array( 'info', "ユーザー「{$data['display_name']}」→ 既存使用 (ID:{$existing->ID})" );
				continue;
			}

			if ( $dry_run ) {
				$log[]                    = array( 'info', "[ドライラン] ユーザー「{$data['display_name']}」({$data['user_login']}) を作成予定" );
				$author_map[ $source_id ] = 1;
				continue;
			}

			$new_id = wp_insert_user(
				array(
					'user_login'   => $data['user_login'],
					'user_email'   => $data['user_email'],
					'display_name' => $data['display_name'],
					'role'         => $data['role'],
					'user_pass'    => wp_generate_password( 24 ),
				)
			);

			if ( is_wp_error( $new_id ) ) {
				$log[]                    = array( 'error', "ユーザー「{$data['display_name']}」作成失敗: " . $new_id->get_error_message() );
				$author_map[ $source_id ] = 1;
			} else {
				$author_map[ $source_id ] = $new_id;
				$log[]                    = array( 'ok', "ユーザー「{$data['display_name']}」を作成 (ID:{$new_id})" );
			}
		}

		return $author_map;
	}

	/**
	 * 投稿インポート
	 *
	 * @param array $category_map ソース category_id → ローカル term_id のマッピング.
	 * @param array $author_map   ソース author_id → ローカル user_id のマッピング.
	 * @param array $log          ログエントリの配列（参照渡し）.
	 * @param bool  $dry_run      true のとき実際の変更を行わない.
	 * @return void
	 */
	private function import_posts( array $category_map, array $author_map, array &$log, bool $dry_run ): void {
		$page     = 1;
		$imported = 0;
		$skipped  = 0;
		$failed   = 0;

		while ( true ) {
			$posts = $this->fetch_api( "posts?per_page=100&page={$page}&orderby=date&order=asc" );
			if ( empty( $posts ) ) {
				break;
			}

			foreach ( $posts as $post ) {
				$sid      = $post['id'];
				$title    = $post['title']['rendered'];
				$slug     = $post['slug'];
				$date     = $post['date'];
				$date_gmt = $post['date_gmt'];
				$content  = $post['content']['rendered'];
				$excerpt  = $post['excerpt']['rendered'];
				$status   = $post['status'];

				// カテゴリーマッピング
				$cats = array();
				foreach ( $post['categories'] ?? array() as $c ) {
					$cats[] = $category_map[ $c ] ?? 1;
				}

				// 投稿者マッピング
				$author_id = $author_map[ $post['author'] ] ?? 1;

				// slug で重複チェック（日本語等エンコードされた slug はデコードして比較）
				$decoded_slug   = urldecode( $slug );
				$existing_posts = get_posts(
					array(
						'name'        => $decoded_slug,
						'post_type'   => 'post',
						'post_status' => 'any',
						'numberposts' => 1,
					)
				);

				if ( ! empty( $existing_posts ) ) {
					$log[] = array( 'warn', "[スキップ] 「{$title}」— slug が既に存在 (ID:{$existing_posts[0]->ID})" );
					++$skipped;
					continue;
				}

				if ( $dry_run ) {
					$log[] = array( 'info', "[ドライラン] 「{$title}」({$date}) — インポート予定" );
					continue;
				}

				$new_id = wp_insert_post(
					array(
						'post_title'     => $title,
						'post_content'   => $content,
						'post_excerpt'   => $excerpt,
						'post_status'    => $status,
						'post_type'      => 'post',
						'post_date'      => $date,
						'post_date_gmt'  => $date_gmt,
						'post_name'      => $slug,
						'post_author'    => $author_id,
						'post_category'  => $cats,
						'comment_status' => $post['comment_status'] ?? 'closed',
						'ping_status'    => $post['ping_status'] ?? 'closed',
						'meta_input'     => array(
							'_imported_from'    => $this->get_source_url(),
							'_imported_post_id' => $sid,
							'_imported_at'      => current_time( 'mysql' ),
						),
					),
					true
				);

				if ( is_wp_error( $new_id ) ) {
					$log[] = array( 'error', "「{$title}」インポート失敗: " . $new_id->get_error_message() );
					++$failed;
				} else {
					$log[] = array( 'ok', "「{$title}」→ ID:{$new_id} (投稿者:{$author_id})" );
					++$imported;
				}
			}

			++$page;
		}

		$log[] = array( 'heading', '結果サマリー' );
		$log[] = array( 'ok', "成功: {$imported} 件" );
		if ( $skipped > 0 ) {
			$log[] = array( 'warn', "スキップ: {$skipped} 件（slug 重複）" );
		}
		if ( $failed > 0 ) {
			$log[] = array( 'error', "失敗: {$failed} 件" );
		}
	}

	// =============================================
	// 管理画面
	// =============================================

	/**
	 * 管理画面ページを出力する
	 *
	 * @return void
	 */
	public function render_page(): void {
		$log  = get_transient( 'rm_post_import_log' );
		$done = isset( $_GET['done'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- リダイレクト後の完了フラグ確認のみ、フォーム処理は handle_import() で nonce 検証済み

		if ( $done && $log ) {
			delete_transient( 'rm_post_import_log' );
		}
		?>
		<div class="wrap">
			<h1>RM 投稿インポーター</h1>
			<p>REST API 経由で投稿・カテゴリー・ユーザーを開発環境にインポートします。</p>

			<?php if ( $done && $log ) : ?>
				<div id="import-results" style="background:#fff;border:1px solid #ccd0d4;padding:16px 20px;margin:20px 0;max-height:500px;overflow-y:auto;font-family:monospace;font-size:13px;line-height:1.8;">
					<?php
					foreach ( $log as $entry ) :
						list( $type, $msg ) = $entry;
						switch ( $type ) {
							case 'heading':
								echo '<div style="margin-top:12px;font-weight:bold;font-size:14px;color:#1d2327;border-bottom:1px solid #ddd;padding-bottom:4px;">▶ ' . esc_html( $msg ) . '</div>';
								break;
							case 'ok':
								echo '<div style="color:#00a32a;">✅ ' . esc_html( $msg ) . '</div>';
								break;
							case 'warn':
								echo '<div style="color:#dba617;">⚠️ ' . esc_html( $msg ) . '</div>';
								break;
							case 'error':
								echo '<div style="color:#d63638;">❌ ' . esc_html( $msg ) . '</div>';
								break;
							default:
								echo '<div style="color:#50575e;">　 ' . esc_html( $msg ) . '</div>';
								break;
						}
					endforeach;
					?>
				</div>
				<p><a href="<?php echo esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ); ?>" class="button">← 戻る</a></p>
			<?php else : ?>
				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>

					<h2>インポート元サイト</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="source_url">ソースサイト URL</label></th>
							<td>
								<input type="url" id="source_url" name="source_url"
									value="<?php echo esc_attr( $this->get_source_url() ); ?>"
									class="regular-text" required>
								<p class="description">REST API 経由でインポートするサイトの URL（末尾スラッシュなし）</p>
							</td>
						</tr>
					</table>

					<h2>ユーザーマッピング設定</h2>
					<p class="description">ソースサイトの投稿者に対応する開発環境のユーザーを設定します。<br>
					既に同じログイン名が存在する場合はそのユーザーが使われます。</p>

					<table class="form-table" role="presentation">
						<tr>
							<th colspan="4" style="padding-bottom:0;">
								<strong>ソース Author ID: 2</strong>
							</th>
						</tr>
						<tr>
							<td>
								<label>ログイン名<br>
								<input type="text" name="user_login_2" value="rm_post_author_2" class="regular-text" required></label>
							</td>
							<td>
								<label>メールアドレス<br>
								<input type="email" name="user_email_2" value="author2@example.com" class="regular-text" required></label>
							</td>
							<td>
								<label>表示名<br>
								<input type="text" name="user_display_2" value="Author 2" class="regular-text"></label>
							</td>
						</tr>
						<tr>
							<th colspan="4" style="padding-bottom:0;">
								<strong>ソース Author ID: 3</strong>
							</th>
						</tr>
						<tr>
							<td>
								<label>ログイン名<br>
								<input type="text" name="user_login_3" value="rm_post_author_3" class="regular-text" required></label>
							</td>
							<td>
								<label>メールアドレス<br>
								<input type="email" name="user_email_3" value="author3@example.com" class="regular-text" required></label>
							</td>
							<td>
								<label>表示名<br>
								<input type="text" name="user_display_3" value="Author 3" class="regular-text"></label>
							</td>
						</tr>
					</table>

					<h2>実行オプション</h2>
					<fieldset>
						<label>
							<input type="checkbox" name="dry_run" value="1" checked>
							<strong>ドライラン</strong>（実際にはインポートしない — まずこちらで確認してください）
						</label>
					</fieldset>

					<p class="submit">
						<input type="submit"
								name="rm_post_import_submit"
								class="button button-primary"
								value="インポート実行"
								onclick="return confirm('インポートを実行しますか？');">
					</p>
				</form>

				<div class="card" style="max-width:720px;margin-top:20px;">
					<h3>📋 このプラグインの処理内容</h3>
					<ol>
						<li><code>/wp-json/wp/v2/categories</code> からカテゴリーを取得し、slug で重複チェック後に作成</li>
						<li><code>/wp-json/wp/v2/users</code> から公開ユーザー情報を取得し、上記設定と合わせてユーザーを作成</li>
						<li><code>/wp-json/wp/v2/posts</code> から全投稿を取得し、slug で重複チェック後にインポート</li>
					</ol>
					<p><strong>安全装置:</strong></p>
					<ul>
						<li>ドライラン機能で事前に確認可能</li>
						<li>slug ベースの重複チェック（2回実行しても二重登録されない）</li>
						<li>各投稿に <code>_imported_from</code> / <code>_imported_post_id</code> メタを付与</li>
						<li>管理者権限 + nonce 検証</li>
					</ul>
				</div>

			<?php endif; ?>
		</div>
		<?php
	}
}