<?php
/**
 * 插件更新类
 *
 * @package Hermit X
 * @since Hermit X 2.5.9
 *
 * 其他人不许乱改！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！！
 */

/**
 * 插件更新
 *
 * @since Hermit X 2.5.9
 */
final class Hermit_Update {

	/**
	 * API 服务器
	 *
	 * @since Hermit X 2.5.9
	 * @var object
	 */
	private $api = 'https://api.lwl12.com/project/hermit/updateCheck';

	/**
	 * 更新数据
	 *
	 * @since Hermit X 2.5.9
	 * @var object
	 */
	private $update_data;

	/**
	 * 构造函数
	 *
	 * @since Hermit X 2.5.9
	 */
	public function __construct() {
		register_activation_hook(
			HERMIT_FILE,
			array( $this, 'force_check' )
		);

		add_action(
			'admin_notices',
			array( $this, 'vcs_warning' )
		);

		add_filter(
			'pre_set_site_transient_update_plugins',
			array( $this, 'insert_update_data' )
		);

		add_filter(
			'upgrader_source_selection',
			array( $this, 'rename_package' ),
			4,
			4
		);
	}

	/**
	 * 强制检测插件更新
	 *
	 * @since Hermit X 2.5.9
	 * @see wp_update_themes()
	 */
	public function force_check() {
		$update = get_site_transient( 'update_plugins' );

		if ( isset( $update->last_checked ) ) {
			unset( $update->last_checked );
			set_site_transient( 'update_plugins', $update );
		}

		wp_update_plugins();
	}

	/**
	 * 输出版本控制工具使用警告
	 *
	 * @since Hermit X 2.5.9
	 */
	public function vcs_warning() {
		if ( !current_user_can( 'activate_plugins' ) )
			return;

		$dismissed = get_user_meta( get_current_user_id(), 'dismissed_wp_pointers', true );
		$dismissed = array_filter( explode( ',', (string) $dismissed ) );

		if ( in_array( 'hermit-vcs-warning', $dismissed ) )
			return;

		if ( !$this->is_vcs_checkout() ) {
/*			$dismissed[] = 'hermit-vcs-warning';
			$dismissed   = implode( ',', $dismissed );

			update_user_meta(
				get_current_user_id(),
				'dismissed_wp_pointers',
				$dismissed
			);
*/
			return;
		}

		$text  = '警告：使用版本控制系统可能导致 Hermit X 更新失败';
		$text .= '，请删除本插件目录下的 .git 文件夹或其他版本控制文件（夹）。';

		echo '
			<div class="error notice is-dismissible" id="vcs-warning">
				<p>' . $text . '</p>
			</div>
			<script type="text/javascript">
				jQuery( document ).on( "click", "#vcs-warning .notice-dismiss", function() {
					jQuery.post(
						"' . esc_url_raw( admin_url( 'admin-ajax.php' ) ) . '",
						"action=dismiss-wp-pointer&pointer=hermit-vcs-warning"
					);
				} );
			</script>';
	}

	/**
	 * 插入插件更新信息
	 *
	 * @since Hermit X 2.5.9
	 */
	public function insert_update_data( $update ) {
		if ( $update_data = $this->get_update_data() ) {
			$file = $this->get_plugin_file();
			$update->response[$file] = $update_data;
		}

		return $update;
	}

	/**
	 * 重命名软件包
	 *
	 * @since Hermit X 2.5.9
	 */
	public function rename_package( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( empty( $hook_extra['plugin'] ) )
			return $source;

		if ( $hook_extra['plugin'] != $this->get_plugin_file() )
			return $source;

		global $wp_filesystem;
		$filename = dirname( $source ) . '/' . dirname( $this->get_plugin_file() );

		if ( !$wp_filesystem->move( $source, $filename, true ) )
			return new WP_Error( 'rename_failed', 'Rename Failed.' );

		return $filename;
	}

	/**
	 * 从远程服务器获取插件更新数据
	 *
	 * @since Hermit X 2.5.9
	 * @since Hermit X 2.6.0 新增 TTL 的支持；修复启用插件时 HTTP 请求次数过多的问题。
	 */
	private function get_update_data() {
		if ( isset( $this->update_data ) )
			return $this->update_data;

		$wp_version  = get_bloginfo( 'version' );
		$home_url    = home_url();

		$response = wp_remote_post( $this->api, array(
			'timeout'    => 10,
			'user-agent' => "WordPress/{$wp_version}; {$home_url}",

			'body' => array(
				'url'         => $home_url,
				'file'        => $this->get_plugin_file(),
				'version'     => $this->get_plugin_version(),
				'wp_version'  => $wp_version,
				'php_version' => phpversion(),
				'wp_locale'   => get_locale()
			)
		) );

		if ( wp_remote_retrieve_response_code( $response ) == 200 ) {
			$body = wp_remote_retrieve_body( $response );
			$body = json_decode( trim( $body ) );

			if ( $body && is_object( $body ) ) {
				if ( isset( $body->response ) ) {
					$this->update_data = $body->response;

					if ( !empty( $body->response->autoupdate ) )
						wp_schedule_single_event( time() + 10, 'wp_version_check' );
				} else {
					$this->update_data = false;
				}

				if ( !empty( $body->ttl ) ) {
					$ttl  = (int) $body->ttl;
					$ttl += time();

					if ( $ttl < wp_next_scheduled( 'wp_version_check' ) )
						wp_schedule_single_event( $ttl, 'wp_version_check' );
				}

				if ( isset( $body->within_China ) ) {
					$within_china   = (bool) $body->within_China;
					$hermit_setting = get_option( 'hermit_setting', array() );

					$hermit_setting['within_China'] = $within_china;
					update_option( 'hermit_setting', $hermit_setting );
				}
			}
		}

		return $this->update_data;
	}

	/**
	 * 获取插件文件名
	 *
	 * @since Hermit X 2.5.9
	 */
	private function get_plugin_file() {
		return plugin_basename( HERMIT_FILE );
	}

	/**
	 * 获取插件版本
	 *
	 * @since Hermit X 2.5.9
	 */
	private function get_plugin_version() {
		if ( !function_exists( 'get_plugin_data' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		$plugin = get_plugin_data( HERMIT_FILE );
		return $plugin['Version'];
	}

	/**
	 * 检测插件目录是否使用了版本控制工具
	 *
	 * @since Hermit X 2.5.9
	 * @since Hermit X 2.6.0 使用插件目录替换原来的 `WP_PLUGIN_DIR`。
	 */
	private function is_vcs_checkout() {
		include_once( ABSPATH . '/wp-admin/includes/admin.php' );
		include_once( ABSPATH . '/wp-admin/includes/class-wp-upgrader.php' );

		$upgrader = new WP_Automatic_Updater;
		return $upgrader->is_vcs_checkout( HERMIT_PATH );
	}

}

// End of page.
