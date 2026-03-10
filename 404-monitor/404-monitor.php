<?php
/**
 * Plugin Name: 404 Error Log
 * Plugin URI: https://github.com/art-kuzmenko/404-monitor-plugin
 * Description: Logs 404 (Page Not Found) errors to help find broken links in content, plugins, and theme files. Compatible with W3 Total Cache and WP Super Cache.
 * Version: 1.0.0
 * Author: Artem Kuzmenko
 * Author URI: https://github.com/art-kuzmenko/404-monitor-plugin
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: 404-error-log
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'E404_LOG_VERSION', '1.0.0' );
define( 'E404_LOG_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'E404_LOG_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Get the log table name (with prefix).
 */
function e404_log_get_table_name() {
	global $wpdb;
	return $wpdb->prefix . '404_error_log';
}

/**
 * Create log table on plugin activation.
 */
function e404_log_activate() {
	global $wpdb;
	$table_name = e404_log_get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		log_date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		url varchar(2048) NOT NULL DEFAULT '',
		user_agent text,
		referer varchar(2048) DEFAULT '',
		ip_address varchar(45) DEFAULT '',
		PRIMARY KEY (id),
		KEY log_date (log_date)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'e404_log_db_version', E404_LOG_VERSION );
}
register_activation_hook( __FILE__, 'e404_log_activate' );

/**
 * Get plugin options with defaults.
 */
function e404_log_get_options() {
	$defaults = array(
		'max_entries'         => 500,
		'record_referrer'     => true,
		'record_ip'           => true,
		'record_user_agent'   => true,
		'ignore_robots'       => false,
		'ignore_no_referrer'  => false,
	);
	return wp_parse_args( get_option( 'e404_log_options', array() ), $defaults );
}

/**
 * Check if the current request is from a robot (common bots by User-Agent).
 */
function e404_log_is_robot( $user_agent ) {
	if ( empty( $user_agent ) ) {
		return false;
	}
	$bot_patterns = array(
		'Googlebot',
		'bingbot',
		'YandexBot',
		'Baiduspider',
		'Slurp',
		'DuckDuckBot',
		'facebookexternalhit',
		'Twitterbot',
		'rogerbot',
		'linkedinbot',
		'embedly',
		'quora link preview',
		'showyoubot',
		'outbrain',
		'pinterest',
		'slackbot',
		'vkshare',
		'W3C_Validator',
		'redditbot',
		'Applebot',
		'WhatsApp',
		'flipboard',
		'tumblr',
		'bitlybot',
		'SkypeUriPreview',
		'nuzzel',
		'Discordbot',
		'Qwantify',
		'pocket',
		'Bytespider',
		'GPTBot',
		'ChatGPT',
		'Claudebot',
		'Anthropic',
	);
	$ua_lower = strtolower( $user_agent );
	foreach ( $bot_patterns as $bot ) {
		if ( false !== strpos( $ua_lower, strtolower( $bot ) ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Log a 404 request. Called on template_redirect when is_404().
 */
function e404_log_record_404() {
	if ( ! is_404() ) {
		return;
	}

	$options = e404_log_get_options();
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
	$referer    = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';

	if ( $options['ignore_robots'] && e404_log_is_robot( $user_agent ) ) {
		return;
	}
	if ( $options['ignore_no_referrer'] && empty( $referer ) ) {
		return;
	}

	global $wpdb;
	$table = e404_log_get_table_name();

	$data = array(
		'log_date'   => current_time( 'mysql' ),
		'url'        => e404_log_get_request_uri(),
	);
	if ( $options['record_user_agent'] ) {
		$data['user_agent'] = substr( $user_agent, 0, 16384 );
	}
	if ( $options['record_referrer'] ) {
		$data['referer'] = substr( $referer, 0, 2048 );
	}
	if ( $options['record_ip'] ) {
		$data['ip_address'] = e404_log_get_client_ip();
	}

	$wpdb->insert( $table, $data );

	// Trim log to max_entries (remove oldest).
	$max = absint( $options['max_entries'] );
	if ( $max > 0 ) {
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		if ( $count > $max ) {
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM $table ORDER BY log_date ASC LIMIT %d",
				$count - $max
			) );
			if ( ! empty( $ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );
			}
		}
	}
}

/**
 * Get request URI for logging (relative path or full URL as requested).
 */
function e404_log_get_request_uri() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	// Store path only to avoid huge URLs; strip query string for consistency.
	$path = wp_parse_url( $uri, PHP_URL_PATH );
	return $path ? $path : $uri;
}

/**
 * Get client IP (respecting proxies).
 */
function e404_log_get_client_ip() {
	$keys = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
	foreach ( $keys as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$ip = wp_unslash( $_SERVER[ $key ] );
			if ( strpos( $ip, ',' ) !== false ) {
				$ip = trim( explode( ',', $ip )[0] );
			}
			if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
		}
	}
	return '';
}

// Run after query is set; works with cached 404s (cache plugins serve 404 after it was first generated).
add_action( 'template_redirect', 'e404_log_record_404', 1 );

/**
 * Admin: add menu and pages.
 */
function e404_log_admin_menu() {
	$hook = add_management_page(
		__( '404 Error Log', '404-error-log' ),
		__( '404 Error Log', '404-error-log' ),
		'manage_options',
		'404_error_log',
		'e404_log_render_admin_page'
	);
	add_action( 'load-' . $hook, 'e404_log_admin_load' );
}
add_action( 'admin_menu', 'e404_log_admin_menu' );

/**
 * Admin load: handle actions and enqueue scripts.
 */
function e404_log_admin_load() {
	$options = e404_log_get_options();

	// Save settings.
	if ( isset( $_POST['e404_log_settings_nonce'] ) && wp_verify_nonce( $_POST['e404_log_settings_nonce'], 'e404_log_save_settings' ) && current_user_can( 'manage_options' ) ) {
		$options = array(
			'max_entries'         => isset( $_POST['e404_max_entries'] ) ? absint( $_POST['e404_max_entries'] ) : 500,
			'record_referrer'     => ! empty( $_POST['e404_record_referrer'] ),
			'record_ip'           => ! empty( $_POST['e404_record_ip'] ),
			'record_user_agent'   => ! empty( $_POST['e404_record_user_agent'] ),
			'ignore_robots'       => ! empty( $_POST['e404_ignore_robots'] ),
			'ignore_no_referrer'  => ! empty( $_POST['e404_ignore_no_referrer'] ),
		);
		$options['max_entries'] = max( 1, min( 10000, $options['max_entries'] ) );
		update_option( 'e404_log_options', $options );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings updated.', '404-error-log' ) . '</p></div>';
	}

	// Bulk delete.
	if ( isset( $_POST['e404_bulk_nonce'] ) && wp_verify_nonce( $_POST['e404_bulk_nonce'], 'e404_bulk_action' ) && current_user_can( 'manage_options' ) ) {
		$action = isset( $_POST['action'] ) ? $_POST['action'] : '';
		$ids    = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'absint', $_POST['ids'] ) : array();
		if ( 'delete' === $action && ! empty( $ids ) ) {
			global $wpdb;
			$table = e404_log_get_table_name();
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE id IN ($placeholders)", $ids ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected entries deleted.', '404-error-log' ) . '</p></div>';
		}
	}

	wp_enqueue_style( 'e404-log-admin', plugin_dir_url( __FILE__ ) . 'admin.css', array(), E404_LOG_VERSION );
}

/**
 * Render admin page (tabs: View log, Settings).
 */
function e404_log_render_admin_page() {
	$current_tab = isset( $_GET['tab'] ) ? $_GET['tab'] : 'log';
	if ( ! in_array( $current_tab, array( 'log', 'settings' ), true ) ) {
		$current_tab = 'log';
	}
	$base_url = admin_url( 'tools.php?page=404_error_log' );
	?>
	<div class="wrap e404-log-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( '404 Error Log', '404-error-log' ); ?></h1>
		<nav class="nav-tab-wrapper wp-clearfix">
			<a href="<?php echo esc_url( $base_url ); ?>" class="nav-tab <?php echo $current_tab === 'log' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'View 404 log', '404-error-log' ); ?></a>
			<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base_url ) ); ?>" class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Manage plugin settings', '404-error-log' ); ?></a>
		</nav>

		<?php if ( 'settings' === $current_tab ) : ?>
			<?php e404_log_render_settings_page(); ?>
		<?php else : ?>
			<?php e404_log_render_log_page(); ?>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render settings form.
 */
function e404_log_render_settings_page() {
	$options = e404_log_get_options();
	?>
	<form method="post" action="" class="e404-settings-form">
		<?php wp_nonce_field( 'e404_log_save_settings', 'e404_log_settings_nonce' ); ?>

		<table class="form-table">
			<tr>
				<th scope="row"><label for="e404_max_entries"><?php esc_html_e( 'Maximum log entries to keep:', '404-error-log' ); ?></label></th>
				<td>
					<input name="e404_max_entries" id="e404_max_entries" type="number" min="1" max="10000" value="<?php echo esc_attr( $options['max_entries'] ); ?>" class="small-text" />
					<p class="description"><?php esc_html_e( 'When this limit is reached, oldest entries are replaced by new ones.', '404-error-log' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Additional data to record:', '404-error-log' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="e404_record_referrer" value="1" <?php checked( $options['record_referrer'] ); ?> /> <?php esc_html_e( 'HTTP Referrer', '404-error-log' ); ?></label><br />
						<label><input type="checkbox" name="e404_record_ip" value="1" <?php checked( $options['record_ip'] ); ?> /> <?php esc_html_e( 'Client IP Address', '404-error-log' ); ?></label><br />
						<label><input type="checkbox" name="e404_record_user_agent" value="1" <?php checked( $options['record_user_agent'] ); ?> /> <?php esc_html_e( 'Client User Agent', '404-error-log' ); ?></label>
					</fieldset>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Other options:', '404-error-log' ); ?></th>
				<td>
					<fieldset>
						<label><input type="checkbox" name="e404_ignore_robots" value="1" <?php checked( $options['ignore_robots'] ); ?> /> <?php esc_html_e( 'Ignore visits from robots', '404-error-log' ); ?></label><br />
						<label><input type="checkbox" name="e404_ignore_no_referrer" value="1" <?php checked( $options['ignore_no_referrer'] ); ?> /> <?php esc_html_e( 'Ignore visits which don\'t have an HTTP Referrer', '404-error-log' ); ?></label>
					</fieldset>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Update settings', '404-error-log' ); ?></button>
		</p>
	</form>
	<?php
}

/**
 * Render log table with search and bulk actions.
 */
function e404_log_render_log_page() {
	global $wpdb;
	$table   = e404_log_get_table_name();
	$options = e404_log_get_options();
	$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page = 20;
	$offset  = ( $paged - 1 ) * $per_page;

	$where = '1=1';
	$params = array();
	if ( $search !== '' ) {
		$like = '%' . $wpdb->esc_like( $search ) . '%';
		$where .= ' AND ( url LIKE %s OR user_agent LIKE %s OR referer LIKE %s OR ip_address LIKE %s )';
		$params = array( $like, $like, $like, $like );
	}

	if ( ! empty( $params ) ) {
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE $where", $params ) );
	} else {
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
	}
	$total_pages = max( 1, ceil( $total / $per_page ) );
	$paged = min( $paged, $total_pages );
	$offset = ( $paged - 1 ) * $per_page;

	$limit_params = array( $per_page, $offset );
	$all_params = array_merge( $params, $limit_params );
	$query = "SELECT * FROM $table WHERE $where ORDER BY log_date DESC LIMIT %d OFFSET %d";
	$entries = $wpdb->get_results( $wpdb->prepare( $query, $all_params ) );

	$base_url = admin_url( 'tools.php?page=404_error_log' );
	if ( $search !== '' ) {
		$base_url = add_query_arg( 's', rawurlencode( $search ), $base_url );
	}
	?>

	<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>" class="e404-log-search" style="margin-bottom: 15px;">
		<input type="hidden" name="page" value="404_error_log" />
		<label for="e404-search"><?php esc_html_e( 'Search log', '404-error-log' ); ?></label>
		<input type="search" name="s" id="e404-search" value="<?php echo esc_attr( $search ); ?>" />
		<button type="submit" class="button"><?php esc_html_e( 'Search', '404-error-log' ); ?></button>
	</form>

	<form method="post" action="">
		<?php wp_nonce_field( 'e404_bulk_action', 'e404_bulk_nonce' ); ?>
		<div class="tablenav top">
			<div class="alignleft actions bulkactions">
				<select name="action">
					<option value=""><?php esc_html_e( 'Bulk Actions', '404-error-log' ); ?></option>
					<option value="delete"><?php esc_html_e( 'Delete', '404-error-log' ); ?></option>
				</select>
				<button type="submit" class="button action"><?php esc_html_e( 'Apply', '404-error-log' ); ?></button>
			</div>
			<div class="alignright"><?php echo esc_html( sprintf( _n( '%s item', '%s items', $total, '404-error-log' ), number_format_i18n( $total ) ) ); ?></div>
		</div>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<td class="check-column"><input type="checkbox" id="e404-select-all" /></td>
					<th scope="col" class="column-date"><?php esc_html_e( 'Date', '404-error-log' ); ?></th>
					<th scope="col" class="column-url"><?php esc_html_e( 'URL', '404-error-log' ); ?></th>
					<?php if ( $options['record_user_agent'] ) : ?><th scope="col" class="column-ua"><?php esc_html_e( 'User Agent', '404-error-log' ); ?></th><?php endif; ?>
					<?php if ( $options['record_referrer'] ) : ?><th scope="col" class="column-referer"><?php esc_html_e( 'HTTP Referer', '404-error-log' ); ?></th><?php endif; ?>
					<?php if ( $options['record_ip'] ) : ?><th scope="col" class="column-ip"><?php esc_html_e( 'IP Address', '404-error-log' ); ?></th><?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $entries ) ) : ?>
					<tr><td colspan="<?php echo 3 + ( (int) $options['record_user_agent'] + (int) $options['record_referrer'] + (int) $options['record_ip'] ); ?>"><?php esc_html_e( 'No 404 entries found.', '404-error-log' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $entries as $row ) : ?>
						<tr>
							<th scope="row" class="check-column"><input type="checkbox" name="ids[]" value="<?php echo esc_attr( $row->id ); ?>" /></th>
							<td class="column-date"><?php echo esc_html( $row->log_date ); ?></td>
							<td class="column-url"><code><?php echo esc_html( $row->url ); ?></code></td>
							<?php if ( $options['record_user_agent'] ) : ?><td class="column-ua"><?php echo esc_html( $row->user_agent ); ?></td><?php endif; ?>
							<?php if ( $options['record_referrer'] ) : ?><td class="column-referer"><?php echo esc_html( $row->referer ); ?></td><?php endif; ?>
							<?php if ( $options['record_ip'] ) : ?><td class="column-ip"><?php echo esc_html( $row->ip_address ); ?></td><?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="pagination-links">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'      => add_query_arg( 'paged', '%#%', $base_url ),
							'format'    => '',
							'prev_text' => '&laquo;',
							'next_text' => '&raquo;',
							'total'     => $total_pages,
							'current'   => $paged,
						) ) );
						?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	</form>

	<script>
	document.getElementById('e404-select-all')?.addEventListener('change', function() {
		document.querySelectorAll('input[name="ids[]"]').forEach(function(cb) { cb.checked = this.checked; }, this);
	});
	</script>
	<?php
}
