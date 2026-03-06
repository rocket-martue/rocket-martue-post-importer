<?php
/**
 * Plugin Name: Rocket Martue Post Importer
 * Description: REST API 経由で 任意のサイトの投稿を開発環境にインポートします。管理画面 → ツール → RM Post インポート から実行してください。
 * Version: 1.0.0
 * Author: Rocket Martue
 * Plugin URI: https://github.com/rocket-martue/rocket-martue-post-importer
 * License: GPL-2.0+
 *
 * @package Rocket_Martue_Post_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-rocket-martue-post-importer.php';

// プラグインを初期化
new Rocket_Martue_Post_Importer();
