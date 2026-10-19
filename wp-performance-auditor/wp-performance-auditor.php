<?php
/**
 * Plugin Name: WP Performance Auditor
 * Description: Runs Google PageSpeed Insights audits and converts the findings into a WordPress-focused action plan.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Codex
 * License: GPL-2.0-or-later
 * Text Domain: wp-performance-auditor
 */

defined( 'ABSPATH' ) || exit;

final class WP_Performance_Auditor {
	const OPTION_SETTINGS = 'wpa_settings';
	const OPTION_HISTORY  = 'wpa_history';
	const VERSION         = '1.0.0';
	const GITHUB_REPOSITORY = 'jmave/wp-performance-auditor';
	const NONCE_ACTION    = 'wpa_run_audit';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wpa_run_audit', array( $this, 'run_audit' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'update_information' ), 20, 3 );
	}

	public function admin_menu() {
		add_menu_page( __( 'Performance Auditor', 'wp-performance-auditor' ), __( 'Performance Auditor', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-auditor', array( $this, 'render_dashboard' ), 'dashicons-chart-line', 81 );
		add_submenu_page( 'wp-performance-auditor', __( 'Settings', 'wp-performance-auditor' ), __( 'Settings', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-auditor-settings', array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( 'wpa_settings_group', self::OPTION_SETTINGS, array( $this, 'sanitize_settings' ) );
	}

	public function sanitize_settings( $input ) {
		$current = get_option( self::OPTION_SETTINGS, array() );
		$value   = isset( $input['api_key'] ) ? trim( $input['api_key'] ) : '';
		$repository = isset( $input['github_repository'] ) ? trim( $input['github_repository'] ) : '';
		$repository = preg_replace( '#^https?://github\.com/#i', '', $repository );
		$repository = trim( $repository, " /" );
		if ( $repository && ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository ) ) $repository = '';
		return array( 'api_key' => '' === $value ? ( $current['api_key'] ?? '' ) : sanitize_text_field( $value ), 'github_repository' => $repository );
	}

	public function assets( $hook ) {
		if ( false === strpos( $hook, 'wp-performance-auditor' ) ) return;
		wp_enqueue_style( 'wpa-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), '1.0.0' );
	}

	public function render_dashboard() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$history = get_option( self::OPTION_HISTORY, array() );
		$latest  = ! empty( $history ) ? $history[0] : null;
		?>
		<div class="wrap wpa-wrap">
			<h1><?php esc_html_e( 'WP Performance Auditor', 'wp-performance-auditor' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Measure your site with Google PageSpeed Insights, then work through the highest-value WordPress fixes.', 'wp-performance-auditor' ); ?></p>
			<?php $this->notice(); ?>
			<div class="wpa-card wpa-audit-form">
				<h2><?php esc_html_e( 'Run a new audit', 'wp-performance-auditor' ); ?></h2>
				<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="wpa_run_audit">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<label for="wpa-url"><?php esc_html_e( 'URL to audit', 'wp-performance-auditor' ); ?></label>
					<input id="wpa-url" name="url" type="url" class="regular-text" required value="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Both mobile and desktop reports will be run. A Google PSI API key is recommended for reliable quota.', 'wp-performance-auditor' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-performance-auditor-settings' ) ); ?>"><?php esc_html_e( 'Configure key', 'wp-performance-auditor' ); ?></a></p>
					<?php submit_button( __( 'Run PageSpeed audit', 'wp-performance-auditor' ), 'primary', 'submit', false ); ?>
				</form>
			</div>
			<?php if ( $latest ) { $this->render_report( $latest ); } else { ?>
				<div class="wpa-empty"><span class="dashicons dashicons-chart-bar"></span><p><?php esc_html_e( 'No audit yet. Run one to see scores, Core Web Vitals, and prioritized fixes.', 'wp-performance-auditor' ); ?></p></div>
			<?php } ?>
			<?php if ( count( $history ) > 1 ) : ?>
				<h2><?php esc_html_e( 'Recent audit history', 'wp-performance-auditor' ); ?></h2><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Date', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'URL', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'Mobile', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'Desktop', 'wp-performance-auditor' ); ?></th></tr></thead><tbody>
				<?php foreach ( array_slice( $history, 1 ) as $scan ) : ?><tr><td><?php echo esc_html( $scan['created_at'] ); ?></td><td><?php echo esc_html( $scan['url'] ); ?></td><td><?php echo esc_html( $scan['mobile']['score'] ?? '—' ); ?></td><td><?php echo esc_html( $scan['desktop']['score'] ?? '—' ); ?></td></tr><?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div><?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$settings = get_option( self::OPTION_SETTINGS, array() ); ?>
		<div class="wrap wpa-wrap"><h1><?php esc_html_e( 'Performance Auditor Settings', 'wp-performance-auditor' ); ?></h1>
			<form method="post" action="options.php" class="wpa-card"><?php settings_fields( 'wpa_settings_group' ); ?>
				<h2><?php esc_html_e( 'Google PageSpeed Insights API', 'wp-performance-auditor' ); ?></h2>
				<p><?php esc_html_e( 'Add a Google Cloud API key with the PageSpeed Insights API enabled. The key is kept in the WordPress options table and is never shown in reports.', 'wp-performance-auditor' ); ?></p>
				<label for="wpa-api-key"><?php esc_html_e( 'API key', 'wp-performance-auditor' ); ?></label><br>
				<input id="wpa-api-key" name="wpa_settings[api_key]" type="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo ! empty( $settings['api_key'] ) ? esc_attr__( 'Saved — leave blank to keep', 'wp-performance-auditor' ) : ''; ?>">
				<hr><h2><?php esc_html_e( 'Plugin updates', 'wp-performance-auditor' ); ?></h2>
				<p><?php esc_html_e( 'Connect a public GitHub repository to receive update notices in Plugins. Each release must include a ZIP whose top-level folder is wp-performance-auditor.', 'wp-performance-auditor' ); ?></p>
				<label for="wpa-github-repository"><?php esc_html_e( 'GitHub repository', 'wp-performance-auditor' ); ?></label><br>
				<input id="wpa-github-repository" name="wpa_settings[github_repository]" type="text" class="regular-text" value="<?php echo esc_attr( $settings['github_repository'] ?? self::GITHUB_REPOSITORY ); ?>" placeholder="owner/repository">
				<?php submit_button(); ?>
			</form></div><?php
	}

	public function run_audit() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) );
		check_admin_referer( self::NONCE_ACTION );
		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! wp_http_validate_url( $url ) ) $this->redirect( 'invalid_url' );
		$scan = array( 'url' => $url, 'created_at' => current_time( 'mysql' ), 'mobile' => $this->fetch_strategy( $url, 'mobile' ), 'desktop' => $this->fetch_strategy( $url, 'desktop' ) );
		if ( ! empty( $scan['mobile']['error'] ) && ! empty( $scan['desktop']['error'] ) ) $this->redirect( 'audit_failed', $scan['mobile']['error'] );
		$history = get_option( self::OPTION_HISTORY, array() ); array_unshift( $history, $scan ); update_option( self::OPTION_HISTORY, array_slice( $history, 0, 10 ), false );
		$this->redirect( 'success' );
	}

	private function fetch_strategy( $url, $strategy ) {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		$args = array( 'url' => $url, 'strategy' => $strategy, 'category' => array( 'performance', 'accessibility', 'best-practices', 'seo' ) );
		if ( ! empty( $settings['api_key'] ) ) $args['key'] = $settings['api_key'];
		$endpoint = add_query_arg( $args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' );
		$response = wp_remote_get( $endpoint, array( 'timeout' => 90, 'redirection' => 3, 'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url() ) );
		if ( is_wp_error( $response ) ) return array( 'error' => $response->get_error_message() );
		$code = wp_remote_retrieve_response_code( $response ); $body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || empty( $body['lighthouseResult'] ) ) return array( 'error' => $body['error']['message'] ?? __( 'PageSpeed Insights did not return a report.', 'wp-performance-auditor' ) );
		return $this->normalize_report( $body['lighthouseResult'] );
	}

	private function normalize_report( $lh ) {
		$audits = $lh['audits'] ?? array(); $categories = $lh['categories'] ?? array();
		$metrics = array();
		foreach ( array( 'first-contentful-paint' => 'FCP', 'largest-contentful-paint' => 'LCP', 'total-blocking-time' => 'TBT', 'cumulative-layout-shift' => 'CLS', 'speed-index' => 'Speed Index' ) as $id => $label ) {
			$metrics[] = array( 'label' => $label, 'value' => $audits[ $id ]['displayValue'] ?? '—' );
		}
		$opportunities = array();
		foreach ( $audits as $id => $audit ) {
			if ( isset( $audit['details']['type'] ) && 'opportunity' === $audit['details']['type'] && ( $audit['score'] ?? 1 ) < 0.9 ) $opportunities[] = array( 'id' => $id, 'title' => $audit['title'] ?? $id, 'description' => $audit['description'] ?? '', 'savings' => $audit['displayValue'] ?? '', 'score' => $audit['score'] ?? 0 );
		}
		usort( $opportunities, function( $a, $b ) { return $a['score'] <=> $b['score']; } );
		return array( 'score' => isset( $categories['performance']['score'] ) ? (int) round( 100 * $categories['performance']['score'] ) : null, 'metrics' => $metrics, 'categories' => array( 'Accessibility' => isset( $categories['accessibility']['score'] ) ? (int) round( 100 * $categories['accessibility']['score'] ) : null, 'Best practices' => isset( $categories['best-practices']['score'] ) ? (int) round( 100 * $categories['best-practices']['score'] ) : null, 'SEO' => isset( $categories['seo']['score'] ) ? (int) round( 100 * $categories['seo']['score'] ) : null ), 'opportunities' => array_slice( $opportunities, 0, 8 ) );
	}

	private function render_report( $scan ) { ?>
		<h2><?php esc_html_e( 'Latest report', 'wp-performance-auditor' ); ?> <span class="wpa-muted">— <?php echo esc_html( $scan['created_at'] ); ?></span></h2>
		<div class="wpa-scores"><?php foreach ( array( 'mobile' => __( 'Mobile', 'wp-performance-auditor' ), 'desktop' => __( 'Desktop', 'wp-performance-auditor' ) ) as $strategy => $label ) : $report = $scan[ $strategy ]; ?>
			<div class="wpa-card"><h3><?php echo esc_html( $label ); ?></h3><?php if ( ! empty( $report['error'] ) ) : ?><p class="wpa-error"><?php echo esc_html( $report['error'] ); ?></p><?php else : ?><div class="wpa-score wpa-score-<?php echo esc_attr( $this->score_band( $report['score'] ) ); ?>"><?php echo esc_html( $report['score'] ); ?></div><div class="wpa-metrics"><?php foreach ( $report['metrics'] as $metric ) : ?><div><strong><?php echo esc_html( $metric['value'] ); ?></strong><span><?php echo esc_html( $metric['label'] ); ?></span></div><?php endforeach; ?></div><?php endif; ?></div>
		<?php endforeach; ?></div>
		<?php foreach ( array( 'mobile' => __( 'Mobile priorities', 'wp-performance-auditor' ), 'desktop' => __( 'Desktop priorities', 'wp-performance-auditor' ) ) as $strategy => $heading ) : if ( empty( $scan[ $strategy ]['opportunities'] ) ) continue; ?><div class="wpa-card"><h3><?php echo esc_html( $heading ); ?></h3><ol class="wpa-priorities"><?php foreach ( $scan[ $strategy ]['opportunities'] as $item ) : ?><li><strong><?php echo esc_html( $item['title'] ); ?></strong> <span><?php echo esc_html( $item['savings'] ); ?></span><p><?php echo wp_kses_post( $this->wp_advice( $item['id'], $item['description'] ) ); ?></p></li><?php endforeach; ?></ol></div><?php endforeach;
	}

	private function wp_advice( $id, $fallback ) {
		$advice = array( 'uses-optimized-images' => 'Convert large uploads to WebP/AVIF, serve correctly sized images, and regenerate thumbnails.', 'uses-responsive-images' => 'Confirm your theme uses <code>wp_get_attachment_image()</code> so WordPress outputs responsive <code>srcset</code> markup.', 'render-blocking-resources' => 'Review theme and plugin assets; defer non-critical JavaScript and generate critical CSS through a performance plugin or your build process.', 'unused-css-rules' => 'Remove stylesheet features supplied by inactive page-builder modules and unload plugin CSS on pages where it is not needed.', 'unused-javascript' => 'Delay or conditionally load plugin scripts, analytics tags, chat widgets, and page-builder modules.', 'uses-long-cache-ttl' => 'Configure long browser-cache headers for versioned static assets in your CDN, host, or caching plugin.', 'server-response-time' => 'Enable page/object caching, inspect slow plugins and database queries, and consider faster hosting or a CDN.', 'third-party-summary' => 'Audit third-party tags; remove unnecessary trackers and delay non-essential widgets until consent or interaction.' );
		return $advice[ $id ] ?? wp_kses_post( $fallback );
	}

	private function score_band( $score ) { return $score >= 90 ? 'good' : ( $score >= 50 ? 'needs-work' : 'poor' ); }

	/** Fetch the latest public GitHub release for WordPress' native update UI. */
	private function latest_release() {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		$repository = $settings['github_repository'] ?? self::GITHUB_REPOSITORY;
		if ( ! $repository ) return false;
		$cache_key = 'wpa_release_' . md5( $repository ); $cached = get_site_transient( $cache_key );
		if ( false !== $cached ) return $cached;
		$parts = explode( '/', $repository );
		$response = wp_remote_get( 'https://api.github.com/repos/' . rawurlencode( $parts[0] ) . '/' . rawurlencode( $parts[1] ) . '/releases/latest', array( 'timeout' => 15, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'WP-Performance-Auditor/' . self::VERSION ) ) );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) return false;
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		$package = '';
		foreach ( $release['assets'] ?? array() as $asset ) { if ( ! empty( $asset['browser_download_url'] ) && preg_match( '/\.zip$/i', $asset['name'] ?? '' ) ) { $package = $asset['browser_download_url']; break; } }
		if ( empty( $release['tag_name'] ) || ! $package ) return false;
		$data = array( 'version' => ltrim( (string) $release['tag_name'], 'v' ), 'package' => esc_url_raw( $package ), 'url' => esc_url_raw( $release['html_url'] ?? '' ), 'body' => wp_kses_post( $release['body'] ?? '' ) );
		set_site_transient( $cache_key, $data, 6 * HOUR_IN_SECONDS ); return $data;
	}

	public function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) return $transient;
		$release = $this->latest_release(); $plugin_file = plugin_basename( __FILE__ );
		if ( $release && version_compare( $release['version'], self::VERSION, '>' ) ) $transient->response[ $plugin_file ] = (object) array( 'slug' => 'wp-performance-auditor', 'plugin' => $plugin_file, 'new_version' => $release['version'], 'url' => $release['url'], 'package' => $release['package'] );
		return $transient;
	}

	public function update_information( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'wp-performance-auditor' !== $args->slug ) return $result;
		$release = $this->latest_release(); if ( ! $release ) return $result;
		return (object) array( 'name' => 'WP Performance Auditor', 'slug' => 'wp-performance-auditor', 'version' => $release['version'], 'author' => 'Codex', 'homepage' => $release['url'], 'download_link' => $release['package'], 'sections' => array( 'description' => __( 'Google PageSpeed Insights audits with WordPress-specific recommendations.', 'wp-performance-auditor' ), 'changelog' => $release['body'] ) );
	}
	private function redirect( $status, $message = '' ) { wp_safe_redirect( add_query_arg( array_filter( array( 'page' => 'wp-performance-auditor', 'wpa_status' => $status, 'wpa_message' => $message ) ), admin_url( 'admin.php' ) ) ); exit; }
	private function notice() { $status = sanitize_key( $_GET['wpa_status'] ?? '' ); if ( 'success' === $status ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit complete.', 'wp-performance-auditor' ) . '</p></div>'; if ( in_array( $status, array( 'invalid_url', 'audit_failed' ), true ) ) echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( $_GET['wpa_message'] ?? __( 'Please enter a valid public URL.', 'wp-performance-auditor' ) ) ) . '</p></div>'; }
}

new WP_Performance_Auditor();

