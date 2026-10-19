<?php
/**
 * Plugin Name: WP Performance Auditor
 * Description: Runs Google PageSpeed Insights audits and converts the findings into a WordPress-focused action plan.
 * Version: 1.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: JCQ Digital
 * Author URI: https://jcqdigital.com
 * License: GPL-2.0-or-later
 * Text Domain: wp-performance-auditor
 *
 * Copyright (c) 2026 JCQ Digital.
 * Bundled Squoosh components are separately licensed under Apache-2.0;
 * see assets/squoosh/SQUOOSH-LICENSE.
 */

defined( 'ABSPATH' ) || exit;

final class WP_Performance_Auditor {
	const OPTION_SETTINGS = 'wpa_settings';
	const OPTION_HISTORY  = 'wpa_history';
	const VERSION         = '1.2.0';
	const GITHUB_REPOSITORY = 'jmave/wp-performance-auditor';
	const NONCE_ACTION    = 'wpa_run_audit';
	const OPTION_ASSET_RULES = 'wpa_asset_rules';
	const OPTION_SCHEDULE = 'wpa_audit_schedule';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_wpa_run_audit', array( $this, 'run_audit' ) );
		add_action( 'admin_post_wpa_run_bulk_audit', array( $this, 'run_bulk_audit' ) );
		add_action( 'admin_post_wpa_save_audit_schedule', array( $this, 'save_audit_schedule' ) );
		add_action( 'admin_post_wpa_save_asset_rule', array( $this, 'save_asset_rule' ) );
		add_action( 'admin_post_wpa_delete_asset_rule', array( $this, 'delete_asset_rule' ) );
		add_action( 'admin_post_wpa_set_asset_test_mode', array( $this, 'set_asset_test_mode' ) );
		add_action( 'admin_post_wpa_inspect_assets', array( $this, 'inspect_assets_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_wpa_compress_images', array( $this, 'compress_images' ) );
		add_action( 'wp_ajax_wpa_load_media_images', array( $this, 'load_media_images' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_capture_assets' ), 9999 );
		add_action( 'wp_enqueue_scripts', array( $this, 'apply_asset_rules' ), 99999 );
		add_filter( 'script_loader_tag', array( $this, 'delay_script_tag' ), 20, 3 );
		add_action( 'wp_footer', array( $this, 'print_delay_loader' ), 1 );
		add_action( 'wpa_scheduled_audit', array( $this, 'scheduled_audit' ) );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
		add_filter( 'plugins_api', array( $this, 'update_information' ), 20, 3 );
	}

	public function admin_menu() {
		add_menu_page( __( 'Performance Auditor', 'wp-performance-auditor' ), __( 'Performance Auditor', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-auditor', array( $this, 'render_dashboard' ), 'dashicons-chart-line', 81 );
		add_submenu_page( 'wp-performance-auditor', __( 'Image Compressor', 'wp-performance-auditor' ), __( 'Image Compressor', 'wp-performance-auditor' ), 'upload_files', 'wp-performance-image-compressor', array( $this, 'render_compressor' ) );
		add_submenu_page( 'wp-performance-auditor', __( 'Bulk Audits', 'wp-performance-auditor' ), __( 'Bulk Audits', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-bulk-audits', array( $this, 'render_bulk_audits' ) );
		add_submenu_page( 'wp-performance-auditor', __( 'Compare Audits', 'wp-performance-auditor' ), __( 'Compare Audits', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-compare', array( $this, 'render_comparisons' ) );
		add_submenu_page( 'wp-performance-auditor', __( 'Client Report', 'wp-performance-auditor' ), __( 'Client Report', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-client-report', array( $this, 'render_client_report' ) );
		add_submenu_page( 'wp-performance-auditor', __( 'Unused CSS & JS', 'wp-performance-auditor' ), __( 'Unused CSS & JS', 'wp-performance-auditor' ), 'manage_options', 'wp-performance-asset-reduction', array( $this, 'render_asset_reduction' ) );
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
		$page = $_GET['page'] ?? ''; $is_compressor = 'wp-performance-image-compressor' === $page;
		$is_plugin_page = 0 === strpos( $page, 'wp-performance-' );
		if ( false === strpos( $hook, 'wp-performance-auditor' ) && ! $is_plugin_page ) return;
		wp_enqueue_style( 'wpa-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.css', array(), self::VERSION );
		wp_add_inline_style( 'wpa-admin', '.wpa-recommendations{border-left:4px solid #34a853}.wpa-recommendation-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:15px;margin-top:18px}.wpa-recommendation{padding:18px;border:1px solid #dce6f1;border-radius:10px;background:#fbfefd}.wpa-recommendation h3{margin:12px 0 8px}.wpa-recommendation p{margin:8px 0;color:#52606d}.wpa-recommendation small{display:block;min-height:32px;color:#64748b;word-break:break-all}.wpa-recommendation form{margin-top:15px}.wpa-confidence{display:inline-block;padding:3px 7px;border-radius:99px;font-size:11px;font-weight:700}.wpa-confidence.is-high{color:#137333;background:#e6f4ea}.wpa-confidence.is-medium{color:#8a5700;background:#fff4d6}' );
		wp_add_inline_style( 'wpa-admin', '.wpa-compare-form{display:flex;align-items:end;gap:16px;flex-wrap:wrap}.wpa-compare-form label{display:grid;gap:6px;font-weight:700}.wpa-compare-form select{min-width:260px}.wpa-delta{font-size:15px;font-weight:700}.wpa-delta.is-up{color:#137333}.wpa-delta.is-down{color:#b3261e}.wpa-client-report{background:#fff}.wpa-client-report .wpa-compressor-hero .button{background:#fff;color:#102a5c;border:0}@media print{#adminmenumain,#wpadminbar,.notice,.wpa-client-report .button{display:none!important}.wpa-wrap{margin:0!important}}' );
		wp_enqueue_script( 'wpa-admin', plugin_dir_url( __FILE__ ) . 'assets/admin.js', array( 'jquery' ), self::VERSION, true );
		if ( $is_compressor ) {
			wp_enqueue_media();
			wp_enqueue_script( 'wpa-compressor', plugin_dir_url( __FILE__ ) . 'assets/compressor.js', array( 'jquery', 'media-views', 'media-editor', 'wp-util' ), self::VERSION, true );
			wp_localize_script( 'wpa-compressor', 'wpaCompressor', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wpa_compress_images' ) ) );
		}
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
				<form id="wpa-audit-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
					<input type="hidden" name="action" value="wpa_run_audit">
					<?php wp_nonce_field( self::NONCE_ACTION ); ?>
					<label for="wpa-url"><?php esc_html_e( 'URL to audit', 'wp-performance-auditor' ); ?></label>
					<input id="wpa-url" name="url" type="url" class="regular-text" required value="<?php echo esc_attr( home_url( '/' ) ); ?>">
					<p class="description"><?php esc_html_e( 'Both mobile and desktop reports will be run. A Google PSI API key is recommended for reliable quota.', 'wp-performance-auditor' ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-performance-auditor-settings' ) ); ?>"><?php esc_html_e( 'Configure key', 'wp-performance-auditor' ); ?></a></p>
					<?php submit_button( __( 'Run PageSpeed audit', 'wp-performance-auditor' ), 'primary', 'submit', false ); ?><span id="wpa-audit-progress" class="wpa-audit-progress" hidden><span class="spinner is-active"></span><?php esc_html_e( 'Running mobile and desktop PageSpeed checks. This may take up to two minutes—please keep this tab open.', 'wp-performance-auditor' ); ?></span>
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

	public function render_bulk_audits() {
		if ( ! current_user_can( 'manage_options' ) ) return; $schedule = get_option( self::OPTION_SCHEDULE, array( 'frequency' => 'disabled', 'urls' => home_url( '/' ) ) );
		?><div class="wrap wpa-wrap"><div class="wpa-compressor-hero"><div><span class="wpa-eyebrow"><?php esc_html_e( 'MULTI-PAGE MONITORING', 'wp-performance-auditor' ); ?></span><h1><?php esc_html_e( 'Bulk & Scheduled Audits', 'wp-performance-auditor' ); ?></h1><p><?php esc_html_e( 'Audit priority URLs now, then keep watch automatically.', 'wp-performance-auditor' ); ?></p></div></div>
		<?php if ( isset( $_GET['wpa_bulk_complete'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Bulk audit complete: %d URL(s) were saved to audit history.', 'wp-performance-auditor' ), absint( $_GET['wpa_bulk_complete'] ) ) ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['wpa_schedule_saved'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Monitoring schedule saved.', 'wp-performance-auditor' ); ?></p></div><?php endif; ?>
		<div class="wpa-card"><h2><?php esc_html_e( 'Run a bulk audit', 'wp-performance-auditor' ); ?></h2><p class="description"><?php esc_html_e( 'Enter up to 10 public URLs, one per line. Each URL receives a mobile and desktop PageSpeed test.', 'wp-performance-auditor' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wpa-bulk-form"><input type="hidden" name="action" value="wpa_run_bulk_audit"><?php wp_nonce_field( 'wpa_run_bulk_audit' ); ?><textarea name="urls" rows="8" class="large-text code" required placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>"></textarea><?php submit_button( __( 'Run bulk audit', 'wp-performance-auditor' ), 'primary', 'submit', false ); ?><span class="wpa-audit-progress" hidden><span class="spinner is-active"></span><?php esc_html_e( 'Running audits. This can take several minutes—please keep this tab open.', 'wp-performance-auditor' ); ?></span></form></div>
		<div class="wpa-card"><h2><?php esc_html_e( 'Scheduled monitoring', 'wp-performance-auditor' ); ?></h2><p><?php esc_html_e( 'WordPress will run these audits when scheduled traffic triggers WP-Cron. A real server cron is recommended for dependable monitoring.', 'wp-performance-auditor' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpa_save_audit_schedule"><?php wp_nonce_field( 'wpa_save_audit_schedule' ); ?><p><label><?php esc_html_e( 'Frequency', 'wp-performance-auditor' ); ?><br><select name="frequency"><option value="disabled" <?php selected( $schedule['frequency'] ?? '', 'disabled' ); ?>><?php esc_html_e( 'Disabled', 'wp-performance-auditor' ); ?></option><option value="daily" <?php selected( $schedule['frequency'] ?? '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'wp-performance-auditor' ); ?></option><option value="weekly" <?php selected( $schedule['frequency'] ?? '', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'wp-performance-auditor' ); ?></option></select></label></p><p><label><?php esc_html_e( 'URLs to monitor', 'wp-performance-auditor' ); ?><br><textarea name="urls" rows="6" class="large-text code"><?php echo esc_textarea( $schedule['urls'] ?? home_url( '/' ) ); ?></textarea></label></p><?php submit_button( __( 'Save monitoring schedule', 'wp-performance-auditor' ), 'secondary', 'submit', false ); ?></form></div></div><?php
	}

	public function render_comparisons() {
		if ( ! current_user_can( 'manage_options' ) ) return; $history = get_option( self::OPTION_HISTORY, array() ); $older = absint( $_GET['older'] ?? 1 ); $newer = absint( $_GET['newer'] ?? 0 ); $current = $history[ $newer ] ?? null; $previous = $history[ $older ] ?? null;
		?><div class="wrap wpa-wrap"><div class="wpa-compressor-hero"><div><span class="wpa-eyebrow"><?php esc_html_e( 'PERFORMANCE TRENDS', 'wp-performance-auditor' ); ?></span><h1><?php esc_html_e( 'Compare Audits', 'wp-performance-auditor' ); ?></h1><p><?php esc_html_e( 'See whether performance changed between two saved scans.', 'wp-performance-auditor' ); ?></p></div></div><?php if ( count( $history ) < 2 ) : ?><div class="wpa-empty"><p><?php esc_html_e( 'Run at least two audits to compare changes over time.', 'wp-performance-auditor' ); ?></p></div><?php else : ?><form method="get" class="wpa-card wpa-compare-form"><input type="hidden" name="page" value="wp-performance-compare"><label><?php esc_html_e( 'Newer audit', 'wp-performance-auditor' ); ?> <select name="newer"><?php foreach ( $history as $index => $scan ) : ?><option value="<?php echo esc_attr( $index ); ?>" <?php selected( $newer, $index ); ?>><?php echo esc_html( $scan['created_at'] . ' — ' . $scan['url'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Older audit', 'wp-performance-auditor' ); ?> <select name="older"><?php foreach ( $history as $index => $scan ) : if ( $index === $newer ) continue; ?><option value="<?php echo esc_attr( $index ); ?>" <?php selected( $older, $index ); ?>><?php echo esc_html( $scan['created_at'] . ' — ' . $scan['url'] ); ?></option><?php endforeach; ?></select></label><button class="button button-primary"><?php esc_html_e( 'Compare', 'wp-performance-auditor' ); ?></button></form><?php if ( $current && $previous ) : ?><div class="wpa-scores"><?php foreach ( array( 'mobile' => __( 'Mobile', 'wp-performance-auditor' ), 'desktop' => __( 'Desktop', 'wp-performance-auditor' ) ) as $strategy => $label ) : $now = $current[ $strategy ]['score'] ?? null; $then = $previous[ $strategy ]['score'] ?? null; $delta = ( null !== $now && null !== $then ) ? $now - $then : null; ?><div class="wpa-card"><h2><?php echo esc_html( $label ); ?></h2><div class="wpa-score wpa-score-<?php echo esc_attr( $this->score_band( $now ) ); ?>"><?php echo esc_html( null === $now ? '—' : $now ); ?></div><?php if ( null !== $delta ) : ?><p class="wpa-delta <?php echo $delta >= 0 ? 'is-up' : 'is-down'; ?>"><?php echo esc_html( $delta >= 0 ? '+' : '' ); ?><?php echo esc_html( $delta ); ?> <?php esc_html_e( 'points from selected older audit', 'wp-performance-auditor' ); ?></p><?php else : ?><p class="wpa-muted"><?php esc_html_e( 'A score was not available for this comparison.', 'wp-performance-auditor' ); ?></p><?php endif; ?></div><?php endforeach; ?></div><?php endif; ?><?php endif; ?></div><?php
	}

	public function render_client_report() {
		if ( ! current_user_can( 'manage_options' ) ) return; $latest = get_option( self::OPTION_HISTORY, array() )[0] ?? null; ?><div class="wrap wpa-wrap wpa-client-report"><div class="wpa-compressor-hero"><div><span class="wpa-eyebrow">JCQ DIGITAL</span><h1><?php esc_html_e( 'Performance Audit Report', 'wp-performance-auditor' ); ?></h1><p><?php echo $latest ? esc_html( $latest['url'] . ' — ' . $latest['created_at'] ) : esc_html__( 'Run an audit to generate a client-ready report.', 'wp-performance-auditor' ); ?></p></div><button class="button" onclick="window.print()"><?php esc_html_e( 'Print / Save as PDF', 'wp-performance-auditor' ); ?></button></div><?php if ( $latest ) $this->render_report( $latest ); ?></div><?php
	}

	public function render_compressor() {
		if ( ! current_user_can( 'upload_files' ) ) return;
		?>
		<div class="wrap wpa-wrap">
			<div class="wpa-compressor-hero"><div><span class="wpa-eyebrow"><?php esc_html_e( 'MEDIA OPTIMIZATION', 'wp-performance-auditor' ); ?></span><h1><?php esc_html_e( 'Image Compressor', 'wp-performance-auditor' ); ?></h1><p><?php esc_html_e( 'Create lighter WebP copies from one image or an entire batch—without touching your originals.', 'wp-performance-auditor' ); ?></p></div><div class="wpa-hero-badge"><span class="dashicons dashicons-images-alt2"></span><strong><?php esc_html_e( 'Originals protected', 'wp-performance-auditor' ); ?></strong></div></div>
			<div class="wpa-card wpa-compression-tool">
				<div class="wpa-steps"><span><b>1</b><?php esc_html_e( 'Select images', 'wp-performance-auditor' ); ?></span><span><b>2</b><?php esc_html_e( 'Set quality', 'wp-performance-auditor' ); ?></span><span><b>3</b><?php esc_html_e( 'Create copies', 'wp-performance-auditor' ); ?></span></div>
				<div class="wpa-media-header"><div><h2><?php esc_html_e( 'Your image library', 'wp-performance-auditor' ); ?></h2><p><?php esc_html_e( 'Click images to select them. Your original files are never replaced.', 'wp-performance-auditor' ); ?></p></div><span id="wpa-selected-count" class="wpa-selected-count">0 <?php esc_html_e( 'selected', 'wp-performance-auditor' ); ?></span></div>
				<div class="wpa-media-tools"><label><span class="screen-reader-text"><?php esc_html_e( 'Search images', 'wp-performance-auditor' ); ?></span><span class="dashicons dashicons-search"></span><input id="wpa-media-search" type="search" placeholder="<?php esc_attr_e( 'Search images', 'wp-performance-auditor' ); ?>"></label><label><?php esc_html_e( 'Show', 'wp-performance-auditor' ); ?> <select id="wpa-media-status"><option value="all"><?php esc_html_e( 'All images', 'wp-performance-auditor' ); ?></option><option value="needs"><?php esc_html_e( 'Needs optimization', 'wp-performance-auditor' ); ?></option><option value="optimized"><?php esc_html_e( 'Optimized WebP', 'wp-performance-auditor' ); ?></option></select></label><label><?php esc_html_e( 'Sort', 'wp-performance-auditor' ); ?> <select id="wpa-media-sort"><option value="newest"><?php esc_html_e( 'Newest first', 'wp-performance-auditor' ); ?></option><option value="oldest"><?php esc_html_e( 'Oldest first', 'wp-performance-auditor' ); ?></option><option value="name"><?php esc_html_e( 'Name A–Z', 'wp-performance-auditor' ); ?></option></select></label><button type="button" id="wpa-select-visible" class="button button-secondary"><?php esc_html_e( 'Select all on this page', 'wp-performance-auditor' ); ?></button></div>
				<div id="wpa-media-grid" class="wpa-media-grid" aria-label="<?php esc_attr_e( 'Media Library images', 'wp-performance-auditor' ); ?>"><?php echo $this->media_tiles( 1 ); ?></div>
				<nav id="wpa-media-pagination" class="wpa-media-pagination" aria-label="<?php esc_attr_e( 'Image library pages', 'wp-performance-auditor' ); ?>"><?php echo $this->media_pagination( 1 ); ?></nav>
				<div class="wpa-compressor-controls"><div class="wpa-selection-hint"><span class="dashicons dashicons-yes-alt"></span><p><?php esc_html_e( 'Select one image for a quick optimization or select a batch to process together.', 'wp-performance-auditor' ); ?></p></div><div class="wpa-quality-control"><label for="wpa-quality"><?php esc_html_e( 'WebP quality', 'wp-performance-auditor' ); ?> <strong id="wpa-quality-value">82</strong></label><input id="wpa-quality" type="range" min="40" max="95" value="82"><small><?php esc_html_e( '82 is the recommended balance of quality and file size.', 'wp-performance-auditor' ); ?></small></div></div>
				<div id="wpa-selected-images" class="wpa-selected-images" aria-live="polite"></div>
				<div class="wpa-compressor-footer"><span><?php esc_html_e( 'Optimized copies are saved to your Media Library.', 'wp-performance-auditor' ); ?></span><button type="button" class="button button-primary" id="wpa-compress-images" disabled><?php esc_html_e( 'Create optimized WebP copies', 'wp-performance-auditor' ); ?></button></div><div id="wpa-compression-results" class="wpa-results" aria-live="polite"></div>
			</div>
		</div><?php
	}

	public function render_asset_reduction() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		$history = get_option( self::OPTION_HISTORY, array() ); $latest = $history[0] ?? null; $config = $this->asset_config(); $rules = $config['rules']; $discovered = get_user_meta( get_current_user_id(), 'wpa_inspected_assets', true ); $inspected_id = absint( get_user_meta( get_current_user_id(), 'wpa_inspected_content_id', true ) ); $recommendations = $this->asset_recommendations( $latest, $discovered );
		$items = get_posts( array( 'post_type' => array( 'page', 'post' ), 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'modified', 'order' => 'DESC' ) );
		?>
		<div class="wrap wpa-wrap"><div class="wpa-compressor-hero"><div><span class="wpa-eyebrow"><?php esc_html_e( 'PAGESPEED ASSET REVIEW', 'wp-performance-auditor' ); ?></span><h1><?php esc_html_e( 'Unused CSS & JavaScript', 'wp-performance-auditor' ); ?></h1><p><?php esc_html_e( 'Turn PageSpeed findings into a safer WordPress asset-reduction plan.', 'wp-performance-auditor' ); ?></p></div><div class="wpa-hero-badge"><span class="dashicons dashicons-admin-generic"></span><strong><?php esc_html_e( 'Review before removal', 'wp-performance-auditor' ); ?></strong></div></div>
			<div class="wpa-card wpa-rule-status"><div><h2><?php esc_html_e( 'Asset rules are', 'wp-performance-auditor' ); ?> <span class="wpa-mode <?php echo ! empty( $config['test_mode'] ) ? 'is-test' : 'is-live'; ?>"><?php echo ! empty( $config['test_mode'] ) ? esc_html__( 'in Test Mode', 'wp-performance-auditor' ) : esc_html__( 'Live', 'wp-performance-auditor' ); ?></span></h2><p><?php echo ! empty( $config['test_mode'] ) ? esc_html__( 'Rules only affect you when you open a public page with ?wpa_asset_test=1 while logged in as an administrator.', 'wp-performance-auditor' ) : esc_html__( 'Rules are active for visitors, except protected checkout, account, login, and form pages.', 'wp-performance-auditor' ); ?></p></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpa_set_asset_test_mode"><?php wp_nonce_field( 'wpa_asset_mode' ); ?><input type="hidden" name="mode" value="<?php echo ! empty( $config['test_mode'] ) ? 'live' : 'test'; ?>"><button class="button <?php echo ! empty( $config['test_mode'] ) ? 'button-primary' : 'button-secondary'; ?>" type="submit"><?php echo ! empty( $config['test_mode'] ) ? esc_html__( 'Enable live mode', 'wp-performance-auditor' ) : esc_html__( 'Return to test mode', 'wp-performance-auditor' ); ?></button></form></div>
			<div class="wpa-card"><h2><?php esc_html_e( '1. Discover assets on a page', 'wp-performance-auditor' ); ?></h2><p><?php esc_html_e( 'Open a public page to collect the CSS and JavaScript handles registered by WordPress. Inspect representative pages separately when they use different builders or plugins.', 'wp-performance-auditor' ); ?></p><form class="wpa-inspect-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpa_inspect_assets"><?php wp_nonce_field( 'wpa_inspect_assets' ); ?><input type="url" name="url" required value="<?php echo esc_attr( home_url( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Public page URL', 'wp-performance-auditor' ); ?>"><button type="submit" class="button button-secondary"><?php esc_html_e( 'Discover this page’s assets', 'wp-performance-auditor' ); ?></button></form></div>
			<div class="wpa-card wpa-recommendations"><h2><?php esc_html_e( 'Recommended reductions', 'wp-performance-auditor' ); ?></h2><?php if ( ! $discovered ) : ?><p class="description"><?php esc_html_e( 'Discover the assets of a page first, then run a fresh PageSpeed audit. The plugin will match PageSpeed’s unused-resource URLs to WordPress handles here.', 'wp-performance-auditor' ); ?></p><?php elseif ( ! $recommendations ) : ?><p class="description"><?php esc_html_e( 'No confident match yet. Run a new PageSpeed audit after discovery; PageSpeed must report unused CSS or JS resources for this page.', 'wp-performance-auditor' ); ?></p><?php else : ?><p class="description"><?php esc_html_e( 'These are matches between PageSpeed’s unused-resource findings and the WordPress handles discovered on the page. Each button adds a reversible Test Mode rule.', 'wp-performance-auditor' ); ?></p><div class="wpa-recommendation-grid"><?php foreach ( $recommendations as $recommendation ) : ?><div class="wpa-recommendation"><span class="wpa-confidence is-<?php echo esc_attr( $recommendation['confidence'] ); ?>"><?php echo esc_html( ucfirst( $recommendation['confidence'] ) . ' confidence' ); ?></span><h3><code><?php echo esc_html( $recommendation['handle'] ); ?></code></h3><p><strong><?php echo esc_html( $recommendation['savings'] ); ?></strong> <?php echo esc_html( 'style' === $recommendation['asset_type'] ? __( 'estimated CSS savings', 'wp-performance-auditor' ) : __( 'estimated JS savings', 'wp-performance-auditor' ) ); ?></p><small><?php echo esc_html( $recommendation['src'] ); ?></small><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpa_save_asset_rule"><?php wp_nonce_field( 'wpa_save_asset_rule' ); ?><input type="hidden" name="handle" value="<?php echo esc_attr( $recommendation['handle'] ); ?>"><input type="hidden" name="asset_type" value="<?php echo esc_attr( $recommendation['asset_type'] ); ?>"><input type="hidden" name="rule_action" value="disable"><input type="hidden" name="scope" value="<?php echo $inspected_id ? 'selected' : 'global'; ?>"><?php if ( $inspected_id ) : ?><input type="hidden" name="content_ids[]" value="<?php echo esc_attr( $inspected_id ); ?>"><?php endif; ?><input type="hidden" name="protect_critical" value="1"><button class="button button-secondary" type="submit"><?php echo $inspected_id ? esc_html__( 'Add page Test Mode rule', 'wp-performance-auditor' ) : esc_html__( 'Add global Test Mode rule', 'wp-performance-auditor' ); ?></button></form></div><?php endforeach; ?></div><?php endif; ?></div>
			<div class="wpa-card"><h2><?php esc_html_e( '2. Create a reduction rule', 'wp-performance-auditor' ); ?></h2><p class="description"><?php esc_html_e( 'Use Test Mode first. Only delay non-critical JavaScript; delayed dependencies can break a page until interaction.', 'wp-performance-auditor' ); ?></p><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpa-rule-form"><input type="hidden" name="action" value="wpa_save_asset_rule"><?php wp_nonce_field( 'wpa_save_asset_rule' ); ?><p><label><?php esc_html_e( 'Asset handle', 'wp-performance-auditor' ); ?><br><input name="handle" required list="wpa-asset-handles" placeholder="plugin-script-handle"></label><datalist id="wpa-asset-handles"><?php foreach ( is_array( $discovered ) ? $discovered : array() as $asset ) : ?><option value="<?php echo esc_attr( $asset['handle'] ); ?>"><?php echo esc_html( $asset['type'] . ' — ' . $asset['src'] ); ?></option><?php endforeach; ?></datalist></p><p><label><?php esc_html_e( 'Type', 'wp-performance-auditor' ); ?><br><select name="asset_type"><option value="style"><?php esc_html_e( 'CSS stylesheet', 'wp-performance-auditor' ); ?></option><option value="script"><?php esc_html_e( 'JavaScript', 'wp-performance-auditor' ); ?></option></select></label></p><p><label><?php esc_html_e( 'Action', 'wp-performance-auditor' ); ?><br><select name="rule_action"><option value="disable"><?php esc_html_e( 'Disable / unload', 'wp-performance-auditor' ); ?></option><option value="delay"><?php esc_html_e( 'Delay until interaction (JavaScript only)', 'wp-performance-auditor' ); ?></option></select></label></p><p><label><?php esc_html_e( 'Apply on', 'wp-performance-auditor' ); ?><br><select name="scope"><option value="global"><?php esc_html_e( 'Every eligible page', 'wp-performance-auditor' ); ?></option><option value="selected"><?php esc_html_e( 'Only the pages/posts selected below', 'wp-performance-auditor' ); ?></option></select></label></p><p><label><?php esc_html_e( 'Selected pages/posts', 'wp-performance-auditor' ); ?><br><select class="wpa-content-select" name="content_ids[]" multiple size="7"><?php foreach ( $items as $content ) : ?><option value="<?php echo esc_attr( $content->ID ); ?>"><?php echo esc_html( ucfirst( $content->post_type ) . ': ' . $content->post_title ); ?></option><?php endforeach; ?></select></label></p><p><label><input type="checkbox" name="protect_critical" value="1" checked> <?php esc_html_e( 'Keep this rule off checkout, cart, account, login, and form pages', 'wp-performance-auditor' ); ?></label></p><button class="button button-primary" type="submit"><?php esc_html_e( 'Save rule', 'wp-performance-auditor' ); ?></button></form></div>
			<div class="wpa-card"><h2><?php esc_html_e( 'Active reduction rules', 'wp-performance-auditor' ); ?></h2><?php if ( $rules ) : ?><table class="widefat striped wpa-rules"><thead><tr><th><?php esc_html_e( 'Asset', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'Action', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'Scope', 'wp-performance-auditor' ); ?></th><th><?php esc_html_e( 'Protection', 'wp-performance-auditor' ); ?></th><th></th></tr></thead><tbody><?php foreach ( $rules as $id => $rule ) : ?><tr><td><code><?php echo esc_html( $rule['handle'] ); ?></code><br><small><?php echo esc_html( $rule['asset_type'] ); ?></small></td><td><?php echo esc_html( 'delay' === $rule['rule_action'] ? __( 'Delay until interaction', 'wp-performance-auditor' ) : __( 'Disable', 'wp-performance-auditor' ) ); ?></td><td><?php echo 'selected' === $rule['scope'] ? esc_html( sprintf( __( '%d selected page(s)', 'wp-performance-auditor' ), count( $rule['content_ids'] ) ) ) : esc_html__( 'Global', 'wp-performance-auditor' ); ?></td><td><?php echo ! empty( $rule['protect_critical'] ) ? esc_html__( 'Protected', 'wp-performance-auditor' ) : esc_html__( 'Off', 'wp-performance-auditor' ); ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wpa_delete_asset_rule"><input type="hidden" name="rule_id" value="<?php echo esc_attr( $id ); ?>"><?php wp_nonce_field( 'wpa_delete_asset_rule_' . $id ); ?><button class="button-link-delete" type="submit"><?php esc_html_e( 'Re-enable / remove', 'wp-performance-auditor' ); ?></button></form></td></tr><?php endforeach; ?></tbody></table><?php else : ?><p class="description"><?php esc_html_e( 'No asset rules saved. Discover a page, select a handle, then create your first reversible rule.', 'wp-performance-auditor' ); ?></p><?php endif; ?></div>
		<?php if ( ! $latest ) : ?><div class="wpa-empty"><p><?php esc_html_e( 'Run a PageSpeed audit first. Its unused CSS and JavaScript findings will appear here.', 'wp-performance-auditor' ); ?></p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=wp-performance-auditor' ) ); ?>"><?php esc_html_e( 'Run an audit', 'wp-performance-auditor' ); ?></a></div>
		<?php else : foreach ( array( 'mobile' => __( 'Mobile', 'wp-performance-auditor' ), 'desktop' => __( 'Desktop', 'wp-performance-auditor' ) ) as $strategy => $label ) : $items = array_filter( $latest[ $strategy ]['opportunities'] ?? array(), function( $item ) { return in_array( $item['id'], array( 'unused-css-rules', 'unused-javascript' ), true ); } ); ?>
			<div class="wpa-card"><h2><?php echo esc_html( $label ); ?> <?php esc_html_e( 'asset findings', 'wp-performance-auditor' ); ?></h2>
				<?php if ( $items ) : ?><div class="wpa-asset-grid"><?php foreach ( $items as $item ) : ?><div class="wpa-asset-card"><span class="dashicons <?php echo 'unused-css-rules' === $item['id'] ? 'dashicons-art' : 'dashicons-media-code'; ?>"></span><h3><?php echo esc_html( $item['title'] ); ?></h3><strong><?php echo esc_html( $item['savings'] ); ?></strong><p><?php echo wp_kses_post( $this->wp_advice( $item['id'], $item['description'] ) ); ?></p></div><?php endforeach; ?></div><?php else : ?><p class="description"><?php esc_html_e( 'No unused CSS or JavaScript opportunity was returned in the latest audit for this strategy.', 'wp-performance-auditor' ); ?></p><?php endif; ?>
			</div>
		<?php endforeach; endif; ?><div class="wpa-card"><h2><?php esc_html_e( 'Safe implementation order', 'wp-performance-auditor' ); ?></h2><ol class="wpa-priorities"><li><strong><?php esc_html_e( 'Identify the owner.', 'wp-performance-auditor' ); ?></strong><p><?php esc_html_e( 'Check whether the asset is loaded by your theme, a page builder, or a plugin.', 'wp-performance-auditor' ); ?></p></li><li><strong><?php esc_html_e( 'Unload by page or template.', 'wp-performance-auditor' ); ?></strong><p><?php esc_html_e( 'Disable assets only where the related feature is not used; retain sitewide dependencies such as checkout and forms.', 'wp-performance-auditor' ); ?></p></li><li><strong><?php esc_html_e( 'Retest immediately.', 'wp-performance-auditor' ); ?></strong><p><?php esc_html_e( 'Run PageSpeed again and verify critical pages before applying the same rule elsewhere.', 'wp-performance-auditor' ); ?></p></li></ol></div></div><?php
	}

	private function asset_config() {
		$config = get_option( self::OPTION_ASSET_RULES, array() );
		return array( 'test_mode' => ! isset( $config['test_mode'] ) || (bool) $config['test_mode'], 'rules' => is_array( $config['rules'] ?? null ) ? $config['rules'] : array() );
	}

	public function save_asset_rule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); check_admin_referer( 'wpa_save_asset_rule' );
		$handle = sanitize_key( wp_unslash( $_POST['handle'] ?? '' ) ); $type = in_array( $_POST['asset_type'] ?? '', array( 'style', 'script' ), true ) ? $_POST['asset_type'] : 'style'; $action = in_array( $_POST['rule_action'] ?? '', array( 'disable', 'delay' ), true ) ? $_POST['rule_action'] : 'disable';
		if ( 'style' === $type ) $action = 'disable';
		$scope = 'selected' === ( $_POST['scope'] ?? '' ) ? 'selected' : 'global'; $ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['content_ids'] ?? array() ) ) ) );
		if ( ! $handle || ( 'selected' === $scope && ! $ids ) ) { wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-asset-reduction', 'wpa_rule_error' => 1 ), admin_url( 'admin.php' ) ) ); exit; }
		$config = $this->asset_config(); $config['rules'][ 'rule_' . time() . '_' . wp_rand( 100, 999 ) ] = array( 'handle' => $handle, 'asset_type' => $type, 'rule_action' => $action, 'scope' => $scope, 'content_ids' => $ids, 'protect_critical' => ! empty( $_POST['protect_critical'] ) ); update_option( self::OPTION_ASSET_RULES, $config, false );
		wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-asset-reduction', 'wpa_rule_saved' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function delete_asset_rule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); $id = sanitize_key( $_POST['rule_id'] ?? '' ); check_admin_referer( 'wpa_delete_asset_rule_' . $id ); $config = $this->asset_config(); unset( $config['rules'][ $id ] ); update_option( self::OPTION_ASSET_RULES, $config, false ); wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-asset-reduction', 'wpa_rule_deleted' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function set_asset_test_mode() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); check_admin_referer( 'wpa_asset_mode' ); $config = $this->asset_config(); $config['test_mode'] = 'test' === ( $_POST['mode'] ?? '' ); update_option( self::OPTION_ASSET_RULES, $config, false ); wp_safe_redirect( add_query_arg( 'page', 'wp-performance-asset-reduction', admin_url( 'admin.php' ) ) ); exit;
	}

	public function inspect_assets_redirect() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); check_admin_referer( 'wpa_inspect_assets' ); $url = esc_url_raw( wp_unslash( $_POST['url'] ?? home_url( '/' ) ) ); $home = wp_parse_url( home_url() ); $target = wp_parse_url( $url );
		if ( empty( $target['host'] ) || $target['host'] !== $home['host'] ) $url = home_url( '/' );
		wp_safe_redirect( add_query_arg( 'wpa_asset_inspect', '1', $url ) ); exit;
	}

	public function maybe_capture_assets() {
		if ( empty( $_GET['wpa_asset_inspect'] ) || ! current_user_can( 'manage_options' ) ) return;
		global $wp_scripts, $wp_styles; $assets = array();
		foreach ( array( array( 'type' => 'style', 'object' => $wp_styles ), array( 'type' => 'script', 'object' => $wp_scripts ) ) as $group ) foreach ( (array) $group['object']->queue as $handle ) { $registered = $group['object']->registered[ $handle ] ?? null; if ( $registered ) $assets[] = array( 'handle' => $handle, 'type' => $group['type'], 'src' => (string) $registered->src ); }
		update_user_meta( get_current_user_id(), 'wpa_inspected_assets', $assets ); update_user_meta( get_current_user_id(), 'wpa_inspected_content_id', url_to_postid( home_url( add_query_arg( array(), $GLOBALS['wp']->request ) ) ) ); wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-asset-reduction', 'wpa_inspected' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	private function normalized_asset_url( $url ) {
		$url = esc_url_raw( (string) $url ); if ( ! $url ) return ''; $parts = wp_parse_url( $url ); return isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : untrailingslashit( $url );
	}

	private function asset_recommendations( $scan, $assets ) {
		if ( ! $scan || ! is_array( $assets ) ) return array(); $resources = array();
		foreach ( array( 'mobile', 'desktop' ) as $strategy ) foreach ( $scan[ $strategy ]['opportunities'] ?? array() as $opportunity ) {
			$type = 'unused-css-rules' === ( $opportunity['id'] ?? '' ) ? 'style' : ( 'unused-javascript' === ( $opportunity['id'] ?? '' ) ? 'script' : '' ); if ( ! $type ) continue;
			foreach ( $opportunity['resources'] ?? array() as $resource ) { $key = $type . '|' . $this->normalized_asset_url( $resource['url'] ?? '' ); if ( $key && ( ! isset( $resources[ $key ] ) || ( $resource['wasted_bytes'] ?? 0 ) > $resources[ $key ]['wasted_bytes'] ) ) $resources[ $key ] = array_merge( $resource, array( 'asset_type' => $type ) ); }
		}
		$recommendations = array(); foreach ( $assets as $asset ) { $type = $asset['type'] ?? ''; $path = $this->normalized_asset_url( $asset['src'] ?? '' ); if ( ! $path ) continue; foreach ( $resources as $resource ) { if ( $type !== $resource['asset_type'] ) continue; $resource_path = $this->normalized_asset_url( $resource['url'] ?? '' ); $exact = $path === $resource_path; $same_file = basename( $path ) && basename( $path ) === basename( $resource_path ); if ( ! $exact && ! $same_file ) continue; $recommendations[ $type . '|' . $asset['handle'] ] = array( 'handle' => $asset['handle'], 'asset_type' => $type, 'src' => $asset['src'], 'savings' => size_format( (int) ( $resource['wasted_bytes'] ?? 0 ) ), 'confidence' => $exact ? 'high' : 'medium' ); break; } }
		return array_values( $recommendations );
	}

	private function asset_rules_enabled() { $config = $this->asset_config(); return empty( $config['test_mode'] ) || ( ! empty( $_GET['wpa_asset_test'] ) && current_user_can( 'manage_options' ) ); }
	private function is_protected_asset_context() {
		if ( is_admin() || defined( 'WP_CLI' ) && WP_CLI ) return true;
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) return true;
		if ( is_singular() ) { $slug = (string) get_post_field( 'post_name', get_queried_object_id() ); if ( preg_match( '/(cart|checkout|account|login|contact|form)/i', $slug ) ) return true; }
		return false;
	}
	private function rule_matches_page( $rule ) { return 'global' === $rule['scope'] || ( is_singular() && in_array( (int) get_queried_object_id(), array_map( 'absint', (array) $rule['content_ids'] ), true ) ); }
	private function active_asset_rules( $type, $action = '' ) { $config = $this->asset_config(); if ( ! $this->asset_rules_enabled() ) return array(); return array_filter( $config['rules'], function( $rule ) use ( $type, $action ) { return $type === ( $rule['asset_type'] ?? '' ) && ( ! $action || $action === ( $rule['rule_action'] ?? '' ) ) && ( empty( $rule['protect_critical'] ) || ! $this->is_protected_asset_context() ) && $this->rule_matches_page( $rule ); } ); }

	public function apply_asset_rules() {
		if ( is_admin() || ! $this->asset_rules_enabled() ) return;
		foreach ( $this->active_asset_rules( 'style', 'disable' ) as $rule ) wp_dequeue_style( $rule['handle'] );
		foreach ( $this->active_asset_rules( 'script', 'disable' ) as $rule ) wp_dequeue_script( $rule['handle'] );
	}

	public function delay_script_tag( $tag, $handle, $src ) {
		foreach ( $this->active_asset_rules( 'script', 'delay' ) as $rule ) if ( $handle === $rule['handle'] && $src ) { $tag = preg_replace( '/\s+type=("|\').*?\1/i', '', $tag ); $tag = preg_replace( '/\s+src=("|\').*?\1/i', ' data-wpa-src=$1' . esc_url( $src ) . '$1', $tag ); return str_replace( '<script', '<script type="text/plain" class="wpa-delayed-script"', $tag ); }
		return $tag;
	}

	public function print_delay_loader() {
		if ( ! $this->active_asset_rules( 'script', 'delay' ) ) return; ?><script>(function(){var done=false;function run(){if(done)return;done=true;document.querySelectorAll('script.wpa-delayed-script[data-wpa-src]').forEach(function(old){var s=document.createElement('script');s.src=old.getAttribute('data-wpa-src');s.async=false;old.parentNode.replaceChild(s,old);});}['pointerdown','keydown','touchstart','scroll'].forEach(function(event){window.addEventListener(event,run,{once:true,passive:true});});})();</script><?php
	}

	public function compress_images() {
		check_ajax_referer( 'wpa_compress_images', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( array( 'message' => __( 'You do not have permission to compress media.', 'wp-performance-auditor' ) ), 403 );
		$ids = array_map( 'absint', (array) ( $_POST['ids'] ?? array() ) ); $quality = min( 95, max( 40, absint( $_POST['quality'] ?? 82 ) ) );
		$results = array();
		foreach ( array_filter( $ids ) as $id ) {
			$path = get_attached_file( $id ); $mime = get_post_mime_type( $id );
			if ( ! $path || ! file_exists( $path ) || 0 !== strpos( (string) $mime, 'image/' ) ) { $results[] = array( 'id' => $id, 'error' => __( 'Not a valid local image.', 'wp-performance-auditor' ) ); continue; }
			$editor = wp_get_image_editor( $path );
			if ( is_wp_error( $editor ) || ! in_array( 'image/webp', $editor::supports_mime_type( 'image/webp' ) ? array( 'image/webp' ) : array(), true ) ) { $results[] = array( 'id' => $id, 'error' => __( 'This server cannot create WebP images. Enable Imagick or GD with WebP support.', 'wp-performance-auditor' ) ); continue; }
			$editor->set_quality( $quality ); $info = pathinfo( $path ); $destination = $info['dirname'] . '/' . sanitize_file_name( $info['filename'] . '-optimized.webp' ); $saved = $editor->save( $destination, 'image/webp' );
			if ( is_wp_error( $saved ) ) { $results[] = array( 'id' => $id, 'error' => $saved->get_error_message() ); continue; }
			$before_size = filesize( $path ); update_post_meta( $id, '_wpa_original_file', $path ); update_post_meta( $id, '_wpa_original_mime', $mime ); update_attached_file( $id, $saved['path'] ); wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ) );
			require_once ABSPATH . 'wp-admin/includes/image.php'; wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $saved['path'] ) );
			$results[] = array( 'id' => $id, 'url' => wp_get_attachment_url( $id ), 'before' => size_format( $before_size ), 'after' => size_format( filesize( $saved['path'] ) ) );
		}
		wp_send_json_success( array( 'results' => $results ) );
	}

	/** Render a small, fast, selectable view of the site's image attachments. */
	private function media_tiles( $page = 1, $search = '', $sort = 'newest', $status = 'all' ) {
		$args = $this->media_query_args( $page, $search, $sort, $status ); $args['fields'] = 'ids'; $query = new WP_Query( $args );
		if ( ! $query->posts ) return '<p class="wpa-no-media">' . esc_html__( 'No images have been uploaded yet.', 'wp-performance-auditor' ) . '</p>';
		$html = '';
		foreach ( $query->posts as $id ) {
			$thumbnail = wp_get_attachment_image_url( $id, 'medium' );
			if ( ! $thumbnail ) continue;
			$is_webp = 'image/webp' === get_post_mime_type( $id ); $html .= '<button type="button" class="wpa-media-tile" data-id="' . esc_attr( $id ) . '" data-url="' . esc_url( $thumbnail ) . '" data-filename="' . esc_attr( get_the_title( $id ) ) . '" aria-pressed="false"><img src="' . esc_url( $thumbnail ) . '" alt=""><span class="wpa-tile-check dashicons dashicons-yes"></span><span class="wpa-image-status ' . ( $is_webp ? 'is-optimized' : 'is-needs' ) . '">' . esc_html( $is_webp ? __( 'WebP', 'wp-performance-auditor' ) : __( 'Needs optimization', 'wp-performance-auditor' ) ) . '</span><span class="wpa-tile-label">' . esc_html( get_the_title( $id ) ) . '</span></button>';
		}
		return $html;
	}

	private function media_pagination( $page = 1, $search = '', $sort = 'newest', $status = 'all' ) {
		$args = $this->media_query_args( $page, $search, $sort, $status ); $args['fields'] = 'ids'; $query = new WP_Query( $args );
		$total = (int) $query->max_num_pages;
		if ( $total < 2 ) return '';
		$current = max( 1, absint( $page ) );
		$start = max( 1, min( $current - 2, $total - 4 ) ); $end = min( $total, max( 5, $current + 2 ) );
		$html = $current > 1 ? '<button type="button" class="button wpa-page-button" data-page="' . esc_attr( $current - 1 ) . '">&larr; ' . esc_html__( 'Previous', 'wp-performance-auditor' ) . '</button>' : '';
		for ( $i = $start; $i <= $end; $i++ ) $html .= '<button type="button" class="button wpa-page-button' . ( $i === $current ? ' is-current' : '' ) . '" data-page="' . esc_attr( $i ) . '"' . ( $i === $current ? ' aria-current="page"' : '' ) . '>' . esc_html( $i ) . '</button>';
		return $html . ( $current < $total ? '<button type="button" class="button wpa-page-button" data-page="' . esc_attr( $current + 1 ) . '">' . esc_html__( 'Next', 'wp-performance-auditor' ) . ' &rarr;</button>' : '' );
	}

	private function media_query_args( $page, $search, $sort, $status = 'all' ) {
		$args = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 24, 'paged' => max( 1, absint( $page ) ) );
		if ( 'needs' === $status ) $args['post_mime_type'] = array( 'image/jpeg', 'image/png' ); elseif ( 'optimized' === $status ) $args['post_mime_type'] = 'image/webp';
		if ( $search ) $args['s'] = sanitize_text_field( $search );
		if ( 'oldest' === $sort ) { $args['orderby'] = 'date'; $args['order'] = 'ASC'; } elseif ( 'name' === $sort ) { $args['orderby'] = 'title'; $args['order'] = 'ASC'; } else { $args['orderby'] = 'date'; $args['order'] = 'DESC'; }
		return $args;
	}

	public function load_media_images() {
		check_ajax_referer( 'wpa_compress_images', 'nonce' );
		if ( ! current_user_can( 'upload_files' ) ) wp_send_json_error( array( 'message' => __( 'You do not have permission to view media.', 'wp-performance-auditor' ) ), 403 );
		$page = max( 1, absint( $_POST['page'] ?? 1 ) ); $search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) ); $sort = in_array( $_POST['sort'] ?? '', array( 'newest', 'oldest', 'name' ), true ) ? $_POST['sort'] : 'newest'; $status = in_array( $_POST['status'] ?? '', array( 'all', 'needs', 'optimized' ), true ) ? $_POST['status'] : 'all';
		wp_send_json_success( array( 'html' => $this->media_tiles( $page, $search, $sort, $status ), 'pagination' => $this->media_pagination( $page, $search, $sort, $status ) ) );
	}

	public function run_audit() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) );
		check_admin_referer( self::NONCE_ACTION );
		$url = esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) );
		if ( ! wp_http_validate_url( $url ) ) $this->redirect( 'invalid_url' );
		$scan = array( 'url' => $url, 'created_at' => current_time( 'mysql' ), 'mobile' => $this->fetch_strategy( $url, 'mobile' ), 'desktop' => $this->fetch_strategy( $url, 'desktop' ) );
		if ( ! empty( $scan['mobile']['error'] ) && ! empty( $scan['desktop']['error'] ) ) $this->redirect( 'audit_failed', $scan['mobile']['error'] );
		$this->save_scan( $scan );
		$this->redirect( 'success' );
	}

	private function save_scan( $scan ) { $history = get_option( self::OPTION_HISTORY, array() ); array_unshift( $history, $scan ); update_option( self::OPTION_HISTORY, array_slice( $history, 0, 30 ), false ); }
	private function valid_audit_urls( $value ) { $urls = preg_split( '/\r\n|\r|\n/', (string) $value ); $urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ), 'wp_http_validate_url' ) ) ); return array_slice( $urls, 0, 10 ); }
	private function audit_url( $url ) { return array( 'url' => $url, 'created_at' => current_time( 'mysql' ), 'mobile' => $this->fetch_strategy( $url, 'mobile' ), 'desktop' => $this->fetch_strategy( $url, 'desktop' ) ); }

	public function run_bulk_audit() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); check_admin_referer( 'wpa_run_bulk_audit' ); $urls = $this->valid_audit_urls( wp_unslash( $_POST['urls'] ?? '' ) ); foreach ( $urls as $url ) $this->save_scan( $this->audit_url( $url ) ); wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-bulk-audits', 'wpa_bulk_complete' => count( $urls ) ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function save_audit_schedule() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'Unauthorized.', 'wp-performance-auditor' ) ); check_admin_referer( 'wpa_save_audit_schedule' ); $frequency = in_array( $_POST['frequency'] ?? '', array( 'disabled', 'daily', 'weekly' ), true ) ? $_POST['frequency'] : 'disabled'; $urls = implode( "\n", $this->valid_audit_urls( wp_unslash( $_POST['urls'] ?? '' ) ) ); update_option( self::OPTION_SCHEDULE, array( 'frequency' => $frequency, 'urls' => $urls ), false ); wp_clear_scheduled_hook( 'wpa_scheduled_audit' ); if ( 'disabled' !== $frequency && $urls ) wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, 'wpa_scheduled_audit' ); wp_safe_redirect( add_query_arg( array( 'page' => 'wp-performance-bulk-audits', 'wpa_schedule_saved' => 1 ), admin_url( 'admin.php' ) ) ); exit;
	}

	public function scheduled_audit() { $schedule = get_option( self::OPTION_SCHEDULE, array() ); foreach ( $this->valid_audit_urls( $schedule['urls'] ?? '' ) as $url ) $this->save_scan( $this->audit_url( $url ) ); }

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
		$opportunities = array(); $performance_ids = wp_list_pluck( $categories['performance']['auditRefs'] ?? array(), 'id' ); $diagnostic_ids = array( 'bootup-time', 'mainthread-work-breakdown', 'dom-size', 'third-party-summary', 'network-requests', 'network-rtt', 'network-server-latency', 'critical-request-chains', 'largest-contentful-paint-element', 'largest-contentful-paint-image-preload', 'uses-rel-preconnect', 'uses-rel-preload', 'font-display', 'total-byte-weight', 'unminified-css', 'unminified-javascript', 'legacy-javascript', 'efficient-animated-content' );
		foreach ( $audits as $id => $audit ) {
			$audit_type = $audit['details']['type'] ?? ''; $score = $audit['score'] ?? null;
			$is_named_diagnostic = in_array( $id, $diagnostic_ids, true ) && ( null === $score || $score < 0.9 );
			$is_scored_finding = in_array( $audit_type, array( 'opportunity', 'diagnostic' ), true ) && null !== $score && $score < 0.9;
			if ( in_array( $id, $performance_ids, true ) && ( $is_scored_finding || $is_named_diagnostic ) ) {
				$resources = array(); if ( in_array( $id, array( 'unused-css-rules', 'unused-javascript' ), true ) ) foreach ( $audit['details']['items'] ?? array() as $resource ) if ( ! empty( $resource['url'] ) ) $resources[] = array( 'url' => esc_url_raw( $resource['url'] ), 'wasted_bytes' => absint( $resource['wastedBytes'] ?? 0 ) );
				$opportunities[] = array( 'id' => $id, 'title' => $audit['title'] ?? $id, 'description' => $audit['description'] ?? '', 'savings' => $audit['displayValue'] ?? '', 'score' => null === $score ? 0.89 : $score, 'kind' => $audit_type, 'resources' => $resources );
			}
		}
		usort( $opportunities, function( $a, $b ) { return $a['score'] <=> $b['score']; } );
		$priorities = array_slice( $opportunities, 0, 15 ); foreach ( $opportunities as $opportunity ) if ( in_array( $opportunity['id'], array( 'unused-css-rules', 'unused-javascript' ), true ) && ! in_array( $opportunity['id'], wp_list_pluck( $priorities, 'id' ), true ) ) $priorities[] = $opportunity;
		return array( 'score' => isset( $categories['performance']['score'] ) ? (int) round( 100 * $categories['performance']['score'] ) : null, 'metrics' => $metrics, 'categories' => array( 'Accessibility' => isset( $categories['accessibility']['score'] ) ? (int) round( 100 * $categories['accessibility']['score'] ) : null, 'Best practices' => isset( $categories['best-practices']['score'] ) ? (int) round( 100 * $categories['best-practices']['score'] ) : null, 'SEO' => isset( $categories['seo']['score'] ) ? (int) round( 100 * $categories['seo']['score'] ) : null ), 'opportunities' => $priorities );
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
		return (object) array( 'name' => 'WP Performance Auditor', 'slug' => 'wp-performance-auditor', 'version' => $release['version'], 'author' => 'JCQ Digital', 'homepage' => $release['url'], 'download_link' => $release['package'], 'sections' => array( 'description' => __( 'Google PageSpeed Insights audits with WordPress-specific recommendations.', 'wp-performance-auditor' ), 'changelog' => $release['body'] ) );
	}
	private function redirect( $status, $message = '' ) { wp_safe_redirect( add_query_arg( array_filter( array( 'page' => 'wp-performance-auditor', 'wpa_status' => $status, 'wpa_message' => $message ) ), admin_url( 'admin.php' ) ) ); exit; }
	private function notice() { $status = sanitize_key( $_GET['wpa_status'] ?? '' ); if ( 'success' === $status ) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Audit complete.', 'wp-performance-auditor' ) . '</p></div>'; if ( in_array( $status, array( 'invalid_url', 'audit_failed' ), true ) ) echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( $_GET['wpa_message'] ?? __( 'Please enter a valid public URL.', 'wp-performance-auditor' ) ) ) . '</p></div>'; }
}

new WP_Performance_Auditor();

register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'wpa_scheduled_audit' );
} );

