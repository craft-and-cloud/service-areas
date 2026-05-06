<?php
/**
 * Plugin Name: Service Areas
 * Plugin URI:  https://craftandcloud.com
 * Description: Manage service locations and the services offered in each area, with a styled frontend grid.
 * Version:     1.2.0
 * Author:      Craft & Cloud
 * Author URI:  https://craftandcloud.com
 * License:     GPL-2.0+
 * Text Domain: service-areas
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$cc_sa_header = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
define( 'CC_SA_VERSION', $cc_sa_header['Version'] );

// =====================================================
// AUTO-UPDATES VIA GITHUB
// Requires the plugin-update-checker library to be placed in
// /service-areas/plugin-update-checker/ inside this plugin folder.
// Library: https://github.com/YahnisElsts/plugin-update-checker
// =====================================================
$cc_sa_puc_path = plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $cc_sa_puc_path ) ) {
	require_once $cc_sa_puc_path;
	$cc_sa_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/craft-and-cloud/service-areas',
		__FILE__,
		'service-areas'
	);
	// For private repos, define CC_SA_GITHUB_TOKEN in wp-config.php.
	if ( defined( 'CC_SA_GITHUB_TOKEN' ) ) {
		$cc_sa_update_checker->setAuthentication( CC_SA_GITHUB_TOKEN );
	}
	$cc_sa_update_checker->setBranch( 'main' );
}

// =====================================================
// SANITIZATION HELPERS
// =====================================================
function cc_sa_sanitize_hex( $color ) {
	if ( is_string( $color ) && preg_match( '/^#([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $color ) ) {
		return $color;
	}
	return '';
}
function cc_sa_sanitize_columns( $value ) {
	$v = intval( $value );
	return ( $v >= 1 && $v <= 8 ) ? $v : 1;
}
function cc_sa_sanitize_radius( $value ) {
	$v = intval( $value );
	return ( $v >= 0 && $v <= 60 ) ? $v : 8;
}
function cc_sa_sanitize_border( $value ) {
	$v = intval( $value );
	return ( $v >= 0 && $v <= 20 ) ? $v : 1;
}

// =====================================================
// REGISTER SERVICE CPT
// =====================================================
add_action( 'init', function() {
	register_post_type( 'service', [
		'labels' => [
			'name'                  => 'Services',
			'singular_name'         => 'Service',
			'add_new'               => 'Add New Service',
			'add_new_item'          => 'Add New Service',
			'edit_item'             => 'Edit Service',
			'new_item'              => 'New Service',
			'view_item'             => 'View Service',
			'view_items'            => 'View Services',
			'search_items'          => 'Search Services',
			'not_found'             => 'No services found.',
			'not_found_in_trash'    => 'No services found in trash.',
			'all_items'             => 'All Services',
			'menu_name'             => 'Services',
			'name_admin_bar'        => 'Service',
		],
		'public'        => true,
		'has_archive'   => true,
		'show_in_rest'  => true,
		'show_in_menu'  => 'service-areas', // nest under our custom top-level menu
		'menu_icon'     => 'dashicons-hammer',
		'supports'      => [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ],
		'rewrite'       => [ 'slug' => 'services', 'with_front' => false ],
		'taxonomies'    => [ 'service_location' ],
	] );
} );

// =====================================================
// REGISTER SERVICE LOCATION TAXONOMY
// =====================================================
add_action( 'init', function() {
	register_taxonomy( 'service_location', 'service', [
		'labels' => [
			'name'          => 'Locations',
			'singular_name' => 'Location',
			'menu_name'     => 'Locations',
		],
		'hierarchical'      => false,
		'public'            => true,
		'show_in_rest'      => true,
		'show_admin_column' => true,
		'show_ui'           => false,           // we provide our own UI on the Service Areas page
		'rewrite'           => [ 'slug' => 'service-location', 'with_front' => false ],
	] );
} );

// =====================================================
// REGISTER SHORT TITLE META FIELD
// =====================================================
add_action( 'init', function() {
	register_post_meta( 'service', '_cc_sa_short_title', [
		'show_in_rest'      => true,
		'single'            => true,
		'type'              => 'string',
		'sanitize_callback' => 'sanitize_text_field',
		'auth_callback'     => function() {
			return current_user_can( 'edit_posts' );
		},
	] );
} );

// =====================================================
// SHORT TITLE METABOX (right sidebar, high priority)
// =====================================================
add_action( 'add_meta_boxes_service', function() {
	add_meta_box(
		'cc_sa_short_title',
		'Short Title',
		'cc_sa_render_short_title_metabox',
		'service',
		'side',
		'high'
	);
} );

function cc_sa_render_short_title_metabox( $post ) {
	$value = get_post_meta( $post->ID, '_cc_sa_short_title', true );
	wp_nonce_field( 'cc_sa_save_short_title', 'cc_sa_short_title_nonce' );
	?>
	<p style="margin:0;">
		<input type="text"
			id="cc_sa_short_title_field"
			name="cc_sa_short_title"
			value="<?php echo esc_attr( $value ); ?>"
			placeholder="e.g. Lawn Care"
			style="width:100%;font-size:14px;padding:6px 8px;" />
		<span style="display:block;color:#666;font-size:12px;margin-top:8px;line-height:1.4;">
			Shown on the public service area cards. Leave blank to fall back to the full post title.
		</span>
	</p>
	<?php
}

add_action( 'save_post_service', function( $post_id, $post ) {
	if ( ! isset( $_POST['cc_sa_short_title_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['cc_sa_short_title_nonce'], 'cc_sa_save_short_title' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$value = isset( $_POST['cc_sa_short_title'] ) ? sanitize_text_field( wp_unslash( $_POST['cc_sa_short_title'] ) ) : '';
	update_post_meta( $post_id, '_cc_sa_short_title', $value );
}, 10, 2 );

// =====================================================
// LOCATION METABOX (right sidebar)
// =====================================================
add_action( 'add_meta_boxes_service', function() {
	add_meta_box(
		'cc_sa_service_location',
		'Locations',
		'cc_sa_render_location_metabox',
		'service',
		'side',
		'default'
	);
} );

function cc_sa_render_location_metabox( $post ) {
	$taxonomy = 'service_location';
	$terms = get_terms( [
		'taxonomy'   => $taxonomy,
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	] );
	$assigned = wp_get_post_terms( $post->ID, $taxonomy, [ 'fields' => 'ids' ] );

	wp_nonce_field( 'cc_sa_save_location', 'cc_sa_location_nonce' );

	if ( empty( $terms ) || is_wp_error( $terms ) ) {
		echo '<p style="color:#999;font-style:italic;margin:0;">';
		echo 'No locations exist yet. <a href="' . esc_url( admin_url( 'admin.php?page=service-areas' ) ) . '">Add a location →</a>';
		echo '</p>';
		return;
	}

	echo '<div style="max-height:240px;overflow-y:auto;padding:4px 0;">';
	echo '<ul style="margin:0;padding:0;list-style:none;">';
	foreach ( $terms as $term ) {
		$checked = in_array( $term->term_id, $assigned, true ) ? 'checked' : '';
		printf(
			'<li style="margin-bottom:6px;">
				<label style="cursor:pointer;display:flex;align-items:center;gap:6px;">
					<input type="checkbox" name="cc_sa_location[]" value="%d" %s />
					<span>%s</span>
				</label>
			</li>',
			esc_attr( $term->term_id ),
			$checked,
			esc_html( $term->name )
		);
	}
	echo '</ul></div>';
	echo '<p style="color:#666;font-size:12px;margin:8px 0 0;">Select one or more locations where this service is offered.</p>';
}

add_action( 'save_post_service', function( $post_id, $post ) {
	if ( ! isset( $_POST['cc_sa_location_nonce'] ) ) return;
	if ( ! wp_verify_nonce( $_POST['cc_sa_location_nonce'], 'cc_sa_save_location' ) ) return;
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	if ( ! current_user_can( 'edit_post', $post_id ) ) return;

	$taxonomy = 'service_location';
	$term_ids = isset( $_POST['cc_sa_location'] ) && is_array( $_POST['cc_sa_location'] )
		? array_map( 'intval', $_POST['cc_sa_location'] )
		: [];

	$valid = array_filter( $term_ids, function( $id ) use ( $taxonomy ) {
		return term_exists( $id, $taxonomy );
	} );

	wp_set_post_terms( $post_id, array_values( $valid ), $taxonomy );
}, 10, 2 );

// =====================================================
// ADMIN MENU
// =====================================================
add_action( 'admin_menu', function() {
	add_menu_page(
		'Service Areas',
		'Service Areas',
		'manage_options',
		'service-areas',
		'cc_sa_render_main_page',
		'dashicons-location-alt',
		25
	);

	// Rename the auto-added duplicate first submenu so it reads cleanly.
	add_submenu_page(
		'service-areas',
		'Locations & Card Settings',
		'Locations & Settings',
		'manage_options',
		'service-areas',
		'cc_sa_render_main_page'
	);
} );

// =====================================================
// SETTINGS REGISTRATION
// =====================================================
add_action( 'admin_init', function() {
	register_setting( 'cc_sa_settings', 'cc_sa_card_bg_color',     [ 'default' => '#ffffff', 'sanitize_callback' => 'cc_sa_sanitize_hex' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_card_title_color',  [ 'default' => '#000000', 'sanitize_callback' => 'cc_sa_sanitize_hex' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_card_text_color',   [ 'default' => '#333333', 'sanitize_callback' => 'cc_sa_sanitize_hex' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_card_border_color', [ 'default' => '#0073aa', 'sanitize_callback' => 'cc_sa_sanitize_hex' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_card_border_width', [ 'default' => '1',       'sanitize_callback' => 'cc_sa_sanitize_border' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_card_radius',       [ 'default' => '8',       'sanitize_callback' => 'cc_sa_sanitize_radius' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_columns_wide',      [ 'default' => '4',       'sanitize_callback' => 'cc_sa_sanitize_columns' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_columns_medium',    [ 'default' => '3',       'sanitize_callback' => 'cc_sa_sanitize_columns' ] );
	register_setting( 'cc_sa_settings', 'cc_sa_columns_mobile',    [ 'default' => '1',       'sanitize_callback' => 'cc_sa_sanitize_columns' ] );
} );

// =====================================================
// HANDLE: ADD NEW LOCATION
// =====================================================
add_action( 'admin_post_cc_sa_add_location', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
	check_admin_referer( 'cc_sa_add_location' );

	$name    = isset( $_POST['location_name'] ) ? sanitize_text_field( wp_unslash( $_POST['location_name'] ) ) : '';
	$message = '';

	if ( $name === '' ) {
		$message = 'empty';
	} else {
		$result = wp_insert_term( $name, 'service_location' );
		if ( is_wp_error( $result ) ) {
			$message = $result->get_error_code() === 'term_exists' ? 'exists' : 'error';
		} else {
			$message = 'added';
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=service-areas&cc_sa_msg=' . urlencode( $message ) ) );
	exit;
} );

// =====================================================
// HANDLE: DELETE LOCATION
// =====================================================
add_action( 'admin_post_cc_sa_delete_location', function() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );
	check_admin_referer( 'cc_sa_delete_location' );

	$term_id = isset( $_POST['term_id'] ) ? intval( $_POST['term_id'] ) : 0;

	if ( $term_id > 0 && term_exists( $term_id, 'service_location' ) ) {
		wp_delete_term( $term_id, 'service_location' );
	}

	wp_safe_redirect( admin_url( 'admin.php?page=service-areas&cc_sa_msg=deleted' ) );
	exit;
} );

// =====================================================
// MAIN ADMIN PAGE
// =====================================================
function cc_sa_render_main_page() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied' );

	$message  = isset( $_GET['cc_sa_msg'] ) ? sanitize_text_field( $_GET['cc_sa_msg'] ) : '';
	$messages = [
		'added'   => [ 'success', 'Location added.' ],
		'exists'  => [ 'warning', 'A location with that name already exists.' ],
		'empty'   => [ 'error',   'Location name is required.' ],
		'error'   => [ 'error',   'Could not add location. Please try again.' ],
		'deleted' => [ 'success', 'Location deleted.' ],
	];

	$locations = get_terms( [
		'taxonomy'   => 'service_location',
		'hide_empty' => false,
		'orderby'    => 'name',
		'order'      => 'ASC',
	] );
	if ( is_wp_error( $locations ) ) $locations = [];
	?>
	<div class="wrap" style="max-width:760px;">
		<h1 style="display:flex;align-items:center;gap:10px;">
			<span class="dashicons dashicons-location-alt" style="font-size:30px;width:30px;height:30px;color:#2271b1;"></span>
			Service Areas
		</h1>

		<?php if ( $message && isset( $messages[ $message ] ) ) : ?>
			<div class="notice notice-<?php echo esc_attr( $messages[ $message ][0] ); ?> is-dismissible" style="margin-top:16px;">
				<p><?php echo esc_html( $messages[ $message ][1] ); ?></p>
			</div>
		<?php elseif ( isset( $_GET['settings-updated'] ) ) : ?>
			<div class="notice notice-success is-dismissible" style="margin-top:16px;">
				<p><strong>Card settings saved.</strong></p>
			</div>
		<?php endif; ?>

		<!-- ADD NEW LOCATION -->
		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px 24px;margin-top:20px;border-radius:6px;">
			<h2 style="margin-top:0;font-size:18px;">Add New Location</h2>
			<p style="color:#666;font-size:13px;margin-top:0;">
				Each location creates a tag you can assign to services.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:10px;align-items:center;">
				<?php wp_nonce_field( 'cc_sa_add_location' ); ?>
				<input type="hidden" name="action" value="cc_sa_add_location">
				<input type="text"
					name="location_name"
					placeholder="e.g. Denver, Boulder, Fort Collins"
					required
					style="flex:1;font-size:14px;padding:8px 10px;">
				<button type="submit" class="button button-primary">Add Location</button>
			</form>
		</div>

		<!-- EXISTING LOCATIONS -->
		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px 24px;margin-top:20px;border-radius:6px;">
			<h2 style="margin-top:0;font-size:18px;">Existing Locations</h2>
			<?php if ( empty( $locations ) ) : ?>
				<p style="color:#999;font-style:italic;margin:0;">No locations added yet.</p>
			<?php else : ?>
				<table class="widefat striped" style="border:none;">
					<thead>
						<tr>
							<th>Location</th>
							<th style="width:140px;">Services</th>
							<th style="width:100px;">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $locations as $loc ) :
							$count    = intval( $loc->count );
							$edit_url = admin_url( 'edit.php?post_type=service&service_location=' . urlencode( $loc->slug ) );
						?>
							<tr>
								<td>
									<strong><?php echo esc_html( $loc->name ); ?></strong>
									<br>
									<span style="color:#666;font-size:12px;">slug: <code><?php echo esc_html( $loc->slug ); ?></code></span>
								</td>
								<td>
									<a href="<?php echo esc_url( $edit_url ); ?>">
										<?php echo $count; ?> service<?php echo $count === 1 ? '' : 's'; ?>
									</a>
								</td>
								<td>
									<form method="post"
										action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
										onsubmit="return confirm('Delete this location? Services will lose this tag, but the services themselves will not be deleted.');"
										style="display:inline;">
										<?php wp_nonce_field( 'cc_sa_delete_location' ); ?>
										<input type="hidden" name="action" value="cc_sa_delete_location">
										<input type="hidden" name="term_id" value="<?php echo intval( $loc->term_id ); ?>">
										<button type="submit" class="button button-link-delete">Delete</button>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>

		<!-- CARD SETTINGS -->
		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px 24px;margin-top:20px;border-radius:6px;">
			<h2 style="margin-top:0;font-size:18px;">Card Settings</h2>
			<p style="color:#666;font-size:13px;margin-top:0;">
				Style and layout for the grid that displays on the front end.
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'cc_sa_settings' ); ?>

				<h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-top:20px;margin-bottom:8px;">Colors</h3>
				<table class="form-table" style="margin-top:0;">
					<tr>
						<th scope="row"><label for="cc_sa_card_bg_color">Background Color</label></th>
						<td><input type="color" id="cc_sa_card_bg_color" name="cc_sa_card_bg_color" value="<?php echo esc_attr( get_option( 'cc_sa_card_bg_color', '#ffffff' ) ); ?>" style="width:60px;height:36px;padding:2px;cursor:pointer;"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cc_sa_card_title_color">Title Color</label></th>
						<td><input type="color" id="cc_sa_card_title_color" name="cc_sa_card_title_color" value="<?php echo esc_attr( get_option( 'cc_sa_card_title_color', '#000000' ) ); ?>" style="width:60px;height:36px;padding:2px;cursor:pointer;"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cc_sa_card_text_color">Text Color</label></th>
						<td><input type="color" id="cc_sa_card_text_color" name="cc_sa_card_text_color" value="<?php echo esc_attr( get_option( 'cc_sa_card_text_color', '#333333' ) ); ?>" style="width:60px;height:36px;padding:2px;cursor:pointer;"></td>
					</tr>
					<tr>
						<th scope="row"><label for="cc_sa_card_border_color">Border Color</label></th>
						<td><input type="color" id="cc_sa_card_border_color" name="cc_sa_card_border_color" value="<?php echo esc_attr( get_option( 'cc_sa_card_border_color', '#0073aa' ) ); ?>" style="width:60px;height:36px;padding:2px;cursor:pointer;"></td>
					</tr>
				</table>

				<h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-top:24px;margin-bottom:8px;">Border</h3>
				<table class="form-table" style="margin-top:0;">
					<tr>
						<th scope="row"><label for="cc_sa_card_border_width">Border Width</label></th>
						<td>
							<input type="number" id="cc_sa_card_border_width" name="cc_sa_card_border_width" min="0" max="20" value="<?php echo esc_attr( get_option( 'cc_sa_card_border_width', '1' ) ); ?>" style="width:80px;">
							<span style="color:#666;font-size:13px;margin-left:6px;">px</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cc_sa_card_radius">Border Radius</label></th>
						<td>
							<input type="number" id="cc_sa_card_radius" name="cc_sa_card_radius" min="0" max="60" value="<?php echo esc_attr( get_option( 'cc_sa_card_radius', '8' ) ); ?>" style="width:80px;">
							<span style="color:#666;font-size:13px;margin-left:6px;">px (0 = sharp, 60 = very rounded)</span>
						</td>
					</tr>
				</table>

				<h3 style="font-size:13px;text-transform:uppercase;letter-spacing:.5px;color:#666;margin-top:24px;margin-bottom:8px;">Columns</h3>
				<table class="form-table" style="margin-top:0;">
					<tr>
						<th scope="row">
							<label for="cc_sa_columns_wide">Wide Screen
								<span style="display:block;font-weight:400;color:#666;font-size:12px;">Above 2000px</span>
							</label>
						</th>
						<td><input type="number" id="cc_sa_columns_wide" name="cc_sa_columns_wide" min="1" max="8" value="<?php echo esc_attr( get_option( 'cc_sa_columns_wide', '4' ) ); ?>" style="width:80px;"></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cc_sa_columns_medium">Medium Screen
								<span style="display:block;font-weight:400;color:#666;font-size:12px;">Up to 2000px</span>
							</label>
						</th>
						<td><input type="number" id="cc_sa_columns_medium" name="cc_sa_columns_medium" min="1" max="8" value="<?php echo esc_attr( get_option( 'cc_sa_columns_medium', '3' ) ); ?>" style="width:80px;"></td>
					</tr>
					<tr>
						<th scope="row">
							<label for="cc_sa_columns_mobile">Mobile
								<span style="display:block;font-weight:400;color:#666;font-size:12px;">Under 781px</span>
							</label>
						</th>
						<td><input type="number" id="cc_sa_columns_mobile" name="cc_sa_columns_mobile" min="1" max="4" value="<?php echo esc_attr( get_option( 'cc_sa_columns_mobile', '1' ) ); ?>" style="width:80px;"></td>
					</tr>
				</table>

				<p style="margin-top:24px;">
					<?php submit_button( 'Save Card Settings', 'primary', 'submit', false ); ?>
				</p>
			</form>
		</div>

		<!-- SHORTCODE REFERENCE -->
		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px 24px;margin-top:20px;border-radius:6px;">
			<h2 style="margin-top:0;font-size:18px;">Display the grid</h2>
			<p style="color:#444;font-size:13px;margin-top:0;">Add this shortcode to any page or post:</p>
			<code style="display:inline-block;padding:8px 14px;background:#f0f0f0;border-radius:4px;font-size:14px;">[service_areas_grid]</code>
		</div>

		<p style="margin-top:30px;color:#bbb;font-size:12px;">
			Service Areas — Created by
			<a href="https://craftandcloud.com" target="_blank" rel="noopener noreferrer" style="color:#bbb;">Craft &amp; Cloud</a>
		</p>
	</div>
	<?php
}

// =====================================================
// FRONTEND SHORTCODE
// =====================================================
add_shortcode( 'service_areas_grid', function( $atts ) {
	$atts = shortcode_atts( [
		'orderby' => 'name',
	], $atts, 'service_areas_grid' );

	$orderby   = in_array( $atts['orderby'], [ 'name', 'count', 'slug' ], true ) ? $atts['orderby'] : 'name';
	$locations = get_terms( [
		'taxonomy'   => 'service_location',
		'hide_empty' => false,
		'orderby'    => $orderby,
		'order'      => 'ASC',
	] );

	if ( empty( $locations ) || is_wp_error( $locations ) ) {
		return '<p class="cc-sa-empty">No service locations found.</p>';
	}

	$bg     = cc_sa_sanitize_hex( get_option( 'cc_sa_card_bg_color',     '#ffffff' ) ) ?: '#ffffff';
	$title  = cc_sa_sanitize_hex( get_option( 'cc_sa_card_title_color',  '#000000' ) ) ?: '#000000';
	$text   = cc_sa_sanitize_hex( get_option( 'cc_sa_card_text_color',   '#333333' ) ) ?: '#333333';
	$border = cc_sa_sanitize_hex( get_option( 'cc_sa_card_border_color', '#0073aa' ) ) ?: '#0073aa';
	$bw     = cc_sa_sanitize_border( get_option( 'cc_sa_card_border_width', '1' ) );
	$br     = cc_sa_sanitize_radius( get_option( 'cc_sa_card_radius', '8' ) );

	ob_start();
	?>
	<div class="cc-sa-grid">
		<?php foreach ( $locations as $loc ) :
			$services = get_posts( [
				'post_type'      => 'service',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => [ [
					'taxonomy' => 'service_location',
					'field'    => 'term_id',
					'terms'    => $loc->term_id,
				] ],
			] );
		?>
			<div class="cc-sa-card" style="
				background: <?php echo esc_attr( $bg ); ?>;
				border: <?php echo intval( $bw ); ?>px solid <?php echo esc_attr( $border ); ?>;
				border-radius: <?php echo intval( $br ); ?>px;
				color: <?php echo esc_attr( $text ); ?>;
			">
				<h3 class="cc-sa-card-title" style="
					color: <?php echo esc_attr( $title ); ?>;
					border-bottom-color: <?php echo esc_attr( $border ); ?>;
				">
					<svg class="cc-sa-pin" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="0.95em" height="0.95em" fill="currentColor" aria-hidden="true">
						<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5S10.62 6.5 12 6.5s2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/>
					</svg>
					<span><?php echo esc_html( $loc->name ); ?></span>
				</h3>

				<?php if ( ! empty( $services ) ) : ?>
					<ul class="cc-sa-services">
						<?php foreach ( $services as $svc ) :
							$short = get_post_meta( $svc->ID, '_cc_sa_short_title', true );
							$label = ! empty( $short ) ? $short : $svc->post_title;
						?>
							<li>
								<a href="<?php echo esc_url( get_permalink( $svc->ID ) ); ?>"
									style="color: <?php echo esc_attr( $text ); ?>;">
									<?php echo esc_html( $label ); ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p class="cc-sa-no-services">No services listed yet.</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
} );

// =====================================================
// FRONTEND CSS (responsive grid)
// =====================================================
add_action( 'wp_enqueue_scripts', function() {
	wp_register_style( 'cc-sa-style', false, [], CC_SA_VERSION );
	wp_enqueue_style( 'cc-sa-style' );

	$cols_wide   = cc_sa_sanitize_columns( get_option( 'cc_sa_columns_wide',   '4' ) );
	$cols_medium = cc_sa_sanitize_columns( get_option( 'cc_sa_columns_medium', '3' ) );
	$cols_mobile = cc_sa_sanitize_columns( get_option( 'cc_sa_columns_mobile', '1' ) );

	$css = "
		.cc-sa-grid {
			display: grid;
			gap: 20px;
			margin: 24px 0;
			grid-template-columns: repeat({$cols_medium}, 1fr);
		}
		@media (min-width: 2001px) {
			.cc-sa-grid { grid-template-columns: repeat({$cols_wide}, 1fr); }
		}
		@media (max-width: 780px) {
			.cc-sa-grid { grid-template-columns: repeat({$cols_mobile}, 1fr); }
		}
		.cc-sa-card {
			padding: 20px 24px;
			box-sizing: border-box;
		}
		.cc-sa-card-title {
			margin: 0 0 12px;
			font-size: 1.25rem;
			display: flex;
			align-items: center;
			gap: 8px;
			border-bottom-style: solid;
			border-bottom-width: 2px;
			padding-bottom: 10px;
		}
		.cc-sa-pin {
			flex-shrink: 0;
		}
		.cc-sa-services {
			list-style: disc;
			margin: 0;
			padding-left: 22px;
		}
		.cc-sa-services li {
			margin-bottom: 6px;
		}
		.cc-sa-services a {
			text-decoration: none;
		}
		.cc-sa-services a:hover {
			text-decoration: underline;
		}
		.cc-sa-no-services {
			color: #999;
			font-style: italic;
			margin: 0;
		}
		.cc-sa-empty {
			color: #999;
			font-style: italic;
		}
	";

	wp_add_inline_style( 'cc-sa-style', $css );
} );

// =====================================================
// ACTIVATION / DEACTIVATION
// Flush rewrite rules so the /services/ URLs route correctly.
// We use a flag because activation runs before our init callbacks,
// so the CPT isn't registered yet at activation time.
// =====================================================
register_activation_hook( __FILE__, function() {
	update_option( 'cc_sa_flush_rewrites', '1' );
} );

register_deactivation_hook( __FILE__, function() {
	flush_rewrite_rules();
} );

add_action( 'init', function() {
	if ( get_option( 'cc_sa_flush_rewrites' ) ) {
		delete_option( 'cc_sa_flush_rewrites' );
		flush_rewrite_rules();
	}
}, 99 );
