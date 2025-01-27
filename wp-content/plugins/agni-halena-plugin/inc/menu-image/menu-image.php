<?php
/*
Plugin Name: Agni Menu Image
Author: AgniHD
*/

/*  
This is modified version of Alex Davyskiba aka Zviryatko's Menu Image plugin .
*/

/**
 * Provide attaching images to menu items.
 *
 * @package Menu_Image
 */
class Menu_Image_Plugin {

	/**
	 * List of used attachment ids grouped by size.
	 *
	 * Need to list all ids to prevent Jetpack Phonon in image_downsize filter.
	 *
	 * @var array
	 */
	private $used_attachments = array();

	/**
	 * List of file extensions that allowed to resize and display as image.
	 *
	 * @var array
	 */
	private $additionalDisplayableImageExtensions = array( 'ico' );

	/**
	 * Plugin constructor, add all filters and actions.
	 */
	public function __construct() {
		add_filter( 'manage_nav-menus_columns', array( $this, 'menu_image_nav_menu_manage_columns' ), 11 );
		add_action( 'save_post_nav_menu_item', array( $this, 'menu_image_save_post_action' ), 10, 3 );
		add_action( 'admin_head-nav-menus.php', array( $this, 'menu_image_admin_head_nav_menus_action' ) );
		add_filter( 'wp_setup_nav_menu_item', array( $this, 'menu_image_wp_setup_nav_menu_item' ) );
		add_filter( 'walker_nav_menu_start_el', array( $this, 'menu_image_nav_menu_item_filter' ), 10, 4 );
		add_action( 'admin_action_delete-menu-item-image', array( $this, 'menu_image_delete_menu_item_image_action' ) );
		add_action( 'wp_ajax_set-menu-item-thumbnail', array( $this, 'wp_ajax_set_menu_item_thumbnail' ) );
		// Add support for additional image types
		add_filter( 'file_is_displayable_image', array( $this, 'file_is_displayable_image' ), 10, 2 );
		// Add support of WPML menus sync
		add_action( 'wp_update_nav_menu_item', array( $this, 'wp_update_nav_menu_item_action' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'admin_init' ), 99 );
		add_filter( 'jetpack_photon_override_image_downsize', array( $this, 'jetpack_photon_override_image_downsize_filter' ), 10, 2 );
	}

	/**
	 * Admin init action with lowest execution priority
	 */
	public function admin_init() {
		// Add custom field for menu edit walker
		if ( !has_action( 'wp_nav_menu_item_custom_fields' ) ) {
			add_filter( 'wp_edit_nav_menu_walker', array( $this, 'menu_image_edit_nav_menu_walker_filter' ) );
		}
		add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'menu_item_custom_fields' ), 10, 4 );
	}

	/**
	 * Filter adds additional validation for image type
	 *
	 * @param bool   $result
	 * @param string $path
	 *
	 * @return bool
	 */
	public function file_is_displayable_image( $result, $path ) {
		if ( $result ) {
			return true;
		}
		$fileExtension = pathinfo( $path, PATHINFO_EXTENSION );

		return in_array( $fileExtension, $this->additionalDisplayableImageExtensions );
	}


	/**
	 * Adding images as screen options.
	 *
	 * If not checked screen option 'image', uploading form not showed.
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function menu_image_nav_menu_manage_columns( $columns ) {
		return $columns + array( 'image' => __( 'Image', 'agni-halena-plugin' ) );
	}

	/**
	 * Saving post action.
	 *
	 * Saving uploaded images and attach/detach to image post type.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function menu_image_save_post_action( $post_id, $post ) {
		$menu_image_settings = array(
			'menu_item_image_size',
			'menu_item_image_title_position'
		);
		foreach ( $menu_image_settings as $setting_name ) {
			if ( isset( $_POST[ $setting_name ][ $post_id ] ) && !empty( $_POST[ $setting_name ][ $post_id ] ) ) {
				if ( $post->{"_$setting_name"} != $_POST[ $setting_name ][ $post_id ] ) {
					update_post_meta( $post_id, "_$setting_name", esc_sql( $_POST[ $setting_name ][ $post_id ] ) );
				}
			}
		}
	}

	/**
	 * Save item settings while WPML sync menus.
	 *
	 * @param $item_menu_id
	 * @param $menu_item_db_id
	 */
	public function wp_update_nav_menu_item_action( $item_menu_id, $menu_item_db_id ) {
		global $sitepress, $icl_menus_sync;
		if ( class_exists( 'SitePress' ) && $sitepress instanceof SitePress && class_exists( 'ICLMenusSync' ) && $icl_menus_sync instanceof ICLMenusSync ) {
			static $run_times = array();
			$menu_image_settings = array(
				'menu_item_image_size',
				'menu_item_image_title_position',
				'thumbnail_id',
				'thumbnail_hover_id',
			);

			// iterate synchronized menus
			foreach ( $icl_menus_sync->menus as $menu_id => $menu_data ) {
				if ( !isset( $_POST[ 'sync' ][ 'add' ][ $menu_id ] ) ) {
					continue;
				}

				// remove cache and get language current item menu
				$cache_key   = md5( serialize( array( $item_menu_id, 'tax_nav_menu' ) ) );
				$cache_group = 'get_language_for_element';
				wp_cache_delete( $cache_key, $cache_group );
				$lang = $sitepress->get_language_for_element( $item_menu_id, 'tax_nav_menu' );

				if ( !isset( $run_times[ $menu_id ][ $lang ] ) ) {
					$run_times[ $menu_id ][ $lang ] = 0;
				}

				// Count static var for each menu id and saved item language
				// and get original item id from counted position of synchronized
				// items from POST data. That's all magic.
				$post_item_ids = array();
				foreach ( $_POST[ 'sync' ][ 'add' ][ $menu_id ] as $id => $lang_array ) {
					if ( array_key_exists( $lang, $lang_array ) ) {
						$post_item_ids[ ] = $id;
					}
				}
				if ( !array_key_exists( $run_times[ $menu_id ][ $lang ], $post_item_ids ) ) {
					continue;
				}
				$orig_item_id = $post_item_ids[ $run_times[ $menu_id ][ $lang ] ];

				// iterate all item settings and save it for new item
				$orig_item_meta = get_metadata( 'post', $orig_item_id );
				foreach ( $menu_image_settings as $meta ) {
					if ( isset( $orig_item_meta[ "_$meta" ] ) && isset( $orig_item_meta[ "_$meta" ][ 0 ] ) ) {
						update_post_meta( $menu_item_db_id, "_$meta", $orig_item_meta[ "_$meta" ][ 0 ] );
					}
				}
				$run_times[ $menu_id ][ $lang ] ++;
				break;
			}
		}
	}

	/**
	 * Replacement edit menu walker class.
	 *
	 * @return string
	 */
	public function menu_image_edit_nav_menu_walker_filter() {
		return 'Menu_Image_Walker_Nav_Menu_Edit';
	}

	/**
	 * Load menu image meta for each menu item.
	 *
	 * @since 2.0
	 */
	public function menu_image_wp_setup_nav_menu_item( $item ) {
		if ( !isset( $item->thumbnail_id ) ) {
			$item->thumbnail_id = get_post_thumbnail_id( $item->ID );
		}

		return $item;
	}

	/**
	 * Replacement default menu item output.
	 *
	 * @param string $item_output Default item output
	 * @param object $item        Menu item data object.
	 * @param int    $depth       Depth of menu item. Used for padding.
	 * @param object $args
	 *
	 * @return string
	 */
	public function menu_image_nav_menu_item_filter( $item_output, $item, $depth, $args ) {
		$attributes = !empty( $item->attr_title ) ? ' title="' . esc_attr( $item->attr_title ) . '"' : '';
		$attributes .= !empty( $item->target ) ? ' target="' . esc_attr( $item->target ) . '"' : '';
		$attributes .= !empty( $item->xfn ) ? ' rel="' . esc_attr( $item->xfn ) . '"' : '';
		$attributes .= !empty( $item->url ) ? ' href="' . esc_attr( $item->url ) . '"' : '';
		$attributes_array = (!empty($attributes))?shortcode_parse_atts($attributes):'';

		$image_size = $item->image_size ? $item->image_size : apply_filters( 'menu_image_default_size', 'full' );
		$this->setUsedAttachments( $image_size, $item->thumbnail_id );
		$image = '';

		if ( $item->thumbnail_id ) {
			$image = wp_get_attachment_image( $item->thumbnail_id, $image_size, false, array( 'class' => 'menu-image' ) );
		}

		/**
		 * Filter the menu link attributes.
		 *
		 * @since 2.6.7
		 *
		 * @param array  $attributes An array of attributes.
		 * @param object $item      Menu item data object.
		 * @param int    $depth     Depth of menu item. Used for padding.
		 * @param object $args
		 */
		$attributes_array = apply_filters( 'menu_image_link_attributes', $attributes_array, $item, $depth, $args );
		$attributes = '';
		foreach ( $attributes_array as $attr_name => $attr_value ) {
			$attributes .= "{$attr_name}=\"$attr_value\" ";
		}
		$attributes = trim($attributes);

		$item_output = "{$args->before}<a {$attributes}>";
		$link        = $args->link_before . apply_filters( 'the_title', $item->title, $item->ID ) . $args->link_after;

		$item_args = array( $image, $link );
		if ( $item->thumbnail_id ) {
			$item_output .= vsprintf( '%s<span class="menu-image-title">%s</span>', $item_args );
		}
		else{
			$item_output .= vsprintf( '%s', $link );
		}
		$item_output .= "</a>{$args->after}";

		return $item_output;
	}

	/**
	 * Loading media-editor script ot nav-menus page.
	 *
	 * @since 2.0
	 */
	public function menu_image_admin_head_nav_menus_action() {
		wp_enqueue_script( 'menu-image-admin', plugin_dir_url(__FILE__) . 'menu-image-admin.js', array( 'jquery' ) );
		wp_localize_script(
			'menu-image-admin', 'menuImage', array(
				'l10n'     => array(
					'uploaderTitle'      => __( 'Chose menu image', 'agni-halena-plugin' ),
					'uploaderButtonText' => __( 'Select', 'agni-halena-plugin' ),
				),
				'settings' => array(
					'nonce' => wp_create_nonce( 'update-menu-item' ),
				),
			)
		);
		wp_enqueue_media();
		wp_enqueue_style( 'editor-buttons' );
	}

	/**
	 * When menu item removed remove menu image metadata.
	 */
	public function menu_image_delete_menu_item_image_action() {

		$menu_item_id = (int) $_REQUEST[ 'menu-item' ];

		check_admin_referer( 'delete-menu_item_image_' . $menu_item_id );

		if ( is_nav_menu_item( $menu_item_id ) && has_post_thumbnail( $menu_item_id ) ) {
			delete_post_thumbnail( $menu_item_id );
		}
	}

	/**
	 * Output HTML for the menu item images.
	 *
	 * @since 2.0
	 *
	 * @param int $item_id The post ID or object associated with the thumbnail, defaults to global $post.
	 *
	 * @return string html
	 */
	public function wp_post_thumbnail_only_html( $item_id ) {
		$default_size = 'thumbnail'; //apply_filters( 'menu_image_default_size', 'full' );
		$markup       = '<p class="description description-thin" ><label>%s<br /><a title="%s" href="#" class="set-post-thumbnail button%s" data-item-id="%s" style="height: auto;">%s</a>%s</label></p>';

		$thumbnail_id = get_post_thumbnail_id( $item_id );
		$content      = sprintf(
			$markup,
			esc_html__( 'Menu image', 'agni-halena-plugin' ),
			$thumbnail_id ? esc_attr__( 'Change menu item image', 'agni-halena-plugin' ) : esc_attr__( 'Set menu item image', 'agni-halena-plugin' ),
			'',
			$item_id,
			$thumbnail_id ? wp_get_attachment_image( $thumbnail_id, $default_size ) : esc_html__( 'Set image', 'agni-halena-plugin' ),
			$thumbnail_id ? '<a href="#" class="remove-post-thumbnail">' . __( 'Remove image', 'agni-halena-plugin' ) . '</a>' : ''
		);

		return $content;
	}

	/**
	 * Output HTML for the menu item images section.
	 *
	 * @since 2.0
	 *
	 * @param int $item_id The post ID or object associated with the thumbnail, defaults to global $post.
	 *
	 * @return string html
	 */
	public function wp_post_thumbnail_html( $item_id ) {
		$default_size = apply_filters( 'menu_image_default_size', 'full' );
		$content      = $this->wp_post_thumbnail_only_html( $item_id );

		$content = "<div class='menu-item-images' style='min-height:70px'>$content</div>";

		/**
		 * Filter the admin menu item thumbnail HTML markup to return.
		 *
		 * @since 2.0
		 *
		 * @param string $content Admin menu item images HTML markup.
		 * @param int    $item_id Post ID.
		 */
		return apply_filters( 'admin_menu_item_thumbnail_html', $content, $item_id );
	}

	/**
	 * Update item thumbnail via ajax action.
	 *
	 * @since 2.0
	 */
	public function wp_ajax_set_menu_item_thumbnail() {
		$json = !empty( $_REQUEST[ 'json' ] );

		$post_ID = intval( $_POST[ 'post_id' ] );
		if ( !current_user_can( 'edit_post', $post_ID ) ) {
			wp_die( - 1 );
		}

		$thumbnail_id = intval( $_POST[ 'thumbnail_id' ] );
		$is_hovered   = (bool) $_POST[ 'is_hover' ];

		check_ajax_referer( "update-menu-item" );

		if ( $thumbnail_id == '-1' ) {
			$success = delete_post_thumbnail( $post_ID );
		} else {
			$success = set_post_thumbnail( $post_ID, $thumbnail_id );
		}

		if ( $success ) {
			$return = $this->wp_post_thumbnail_only_html( $post_ID );
			$json ? wp_send_json_success( $return ) : wp_die( $return );
		}

		wp_die( 0 );
	}

	/**
	 * Add custom fields to menu item.
	 *
	 * @param int    $item_id
	 * @param object $item
	 * @param int    $depth
	 * @param array  $args
	 *
	 * @see http://web.archive.org/web/20141021012233/http://shazdeh.me/2014/06/25/custom-fields-nav-menu-items
	 * @see https://core.trac.wordpress.org/ticket/18584
	 */
	public function menu_item_custom_fields( $item_id, $item, $depth, $args ) {
		if (!$item_id && isset($item->ID)) {
			$item_id = $item->ID;
		}
		?>
		<div class="field-image hide-if-no-js wp-media-buttons">
			<?php echo wp_kses_post( $this->wp_post_thumbnail_html( $item_id ) ); ?>
		</div>
	<?php
	}

	/**
	 * Prevent jetpack Phonon applied for menu item images.
	 *
	 * @param bool  $prevent
	 * @param array $data
	 *
	 * @return bool
	 */
	public function jetpack_photon_override_image_downsize_filter( $prevent, $data ) {
		return $this->isAttachmentUsed( $data[ 'size' ], $data[ 'attachment_id' ] );
	}

	/**
	 * Set used attachment ids.
	 *
	 * @param string $size
	 * @param int    $id
	 */
	public function setUsedAttachments( $size, $id ) {
		$this->used_attachments[ $size ][ ] = $id;
	}

	/**
	 * Check if attachment is used in menu items.
	 *
	 * @param string $size
	 * @param int    $id
	 *
	 * @return bool
	 */
	public function isAttachmentUsed( $size, $id ) {
		return is_string($size) && isset( $this->used_attachments[ $size ] ) && in_array( $id, $this->used_attachments[ $size ] );
	}

}

$menu_image = new Menu_Image_Plugin();

require_once(ABSPATH . 'wp-admin/includes/nav-menu.php');

/**
 * Navigation Menu API: Walker_Nav_Menu_Edit class 4.4.0
 */
class Menu_Image_Walker_Nav_Menu_Edit extends Walker_Nav_Menu_Edit { 

	/**
	 * Starts the list before the elements are added.
	 *
	 * @see Walker_Nav_Menu::start_lvl()
	 *
	 * @since 3.0.0
	 *
	 * @param string $output Passed by reference.
	 * @param int    $depth  Depth of menu item. Used for padding.
	 * @param array  $args   Not used.
	 */
	public function start_lvl( &$output, $depth = 0, $args = array() ) {}

	/**
	 * Ends the list of after the elements are added.
	 *
	 * @see Walker_Nav_Menu::end_lvl()
	 *
	 * @since 3.0.0
	 *
	 * @param string $output Passed by reference.
	 * @param int    $depth  Depth of menu item. Used for padding.
	 * @param array  $args   Not used.
	 */
	public function end_lvl( &$output, $depth = 0, $args = array() ) {}

	/**
	 * Start the element output.
	 *
	 * @see Walker_Nav_Menu::start_el()
	 * @since 3.0.0
	 *
	 * @global int $_wp_nav_menu_max_depth
	 *
	 * @param string $output Passed by reference. Used to append additional content.
	 * @param object $item   Menu item data object.
	 * @param int    $depth  Depth of menu item. Used for padding.
	 * @param array  $args   Not used.
	 * @param int    $id     Not used.
	 */
	public function start_el( &$output, $item, $depth = 0, $args = array(), $id = 0 ) {
		global $_wp_nav_menu_max_depth;
		$_wp_nav_menu_max_depth = $depth > $_wp_nav_menu_max_depth ? $depth : $_wp_nav_menu_max_depth;

		ob_start();
		$item_id = esc_attr( $item->ID );
		$removed_args = array(
			'action',
			'customlink-tab',
			'edit-menu-item',
			'menu-item',
			'page-tab',
			'_wpnonce',
		);

		$original_title = false;
		if ( 'taxonomy' == $item->type ) {
			$original_title = get_term_field( 'name', $item->object_id, $item->object, 'raw' );
			if ( is_wp_error( $original_title ) )
				$original_title = false;
		} elseif ( 'post_type' == $item->type ) {
			$original_object = get_post( $item->object_id );
			$original_title = get_the_title( $original_object->ID );
		} elseif ( 'post_type_archive' == $item->type ) {
			$original_object = get_post_type_object( $item->object );
			if ( $original_object ) {
				$original_title = $original_object->labels->archives;
			}
		}

		$classes = array(
			'menu-item menu-item-depth-' . $depth,
			'menu-item-' . esc_attr( $item->object ),
			'menu-item-edit-' . ( ( isset( $_GET['edit-menu-item'] ) && $item_id == $_GET['edit-menu-item'] ) ? 'active' : 'inactive'),
		);

		$title = $item->title;

		if ( ! empty( $item->_invalid ) ) {
			$classes[] = 'menu-item-invalid';
			/* translators: %s: title of menu item which is invalid */
			$title = sprintf( __( '%s (Invalid)', 'agni-halena-plugin' ), $item->title );
		} elseif ( isset( $item->post_status ) && 'draft' == $item->post_status ) {
			$classes[] = 'pending';
			/* translators: %s: title of menu item in draft status */
			$title = sprintf( __('%s (Pending)', 'agni-halena-plugin'), $item->title );
		}

		$title = ( ! isset( $item->label ) || '' == $item->label ) ? $title : $item->label;

		$submenu_text = '';
		if ( 0 == $depth )
			$submenu_text = 'style="display: none;"';

		?>
		<li id="menu-item-<?php echo esc_attr( $item_id ); ?>" class="<?php echo implode(' ', $classes ); ?>">
			<div class="menu-item-bar">
				<div class="menu-item-handle">
					<span class="item-title"><span class="menu-item-title"><?php echo esc_html( $title ); ?></span> <span class="is-submenu" <?php echo esc_attr( $submenu_text ); ?>><?php _e( 'sub item', 'agni-halena-plugin' ); ?></span></span>
					<span class="item-controls">
						<span class="item-type"><?php echo esc_html( $item->type_label ); ?></span>
						<span class="item-order hide-if-js">
							<a href="<?php
								echo wp_nonce_url(
									add_query_arg(
										array(
											'action' => 'move-up-menu-item',
											'menu-item' => $item_id,
										),
										remove_query_arg($removed_args, admin_url( 'nav-menus.php' ) )
									),
									'move-menu_item'
								);
							?>" class="item-move-up" aria-label="<?php esc_attr_e( 'Move up', 'agni-halena-plugin' ) ?>">&#8593;</a>
							|
							<a href="<?php
								echo wp_nonce_url(
									add_query_arg(
										array(
											'action' => 'move-down-menu-item',
											'menu-item' => $item_id,
										),
										remove_query_arg($removed_args, admin_url( 'nav-menus.php' ) )
									),
									'move-menu_item'
								);
							?>" class="item-move-down" aria-label="<?php esc_attr_e( 'Move down', 'agni-halena-plugin' ) ?>">&#8595;</a>
						</span>
						<a class="item-edit" id="edit-<?php echo esc_attr( $item_id ); ?>" href="<?php
							echo ( isset( $_GET['edit-menu-item'] ) && $item_id == $_GET['edit-menu-item'] ) ? admin_url( 'nav-menus.php' ) : add_query_arg( 'edit-menu-item', $item_id, remove_query_arg( $removed_args, admin_url( 'nav-menus.php#menu-item-settings-' . $item_id ) ) );
						?>" aria-label="<?php esc_attr_e( 'Edit menu item', 'agni-halena-plugin' ); ?>"><?php _e( 'Edit', 'agni-halena-plugin' ); ?></a>
					</span>
				</div>
			</div>

			<div class="menu-item-settings wp-clearfix" id="menu-item-settings-<?php echo esc_attr( $item_id ); ?>">
				<?php if ( 'custom' == $item->type ) : ?>
					<p class="field-url description description-wide">
						<label for="edit-menu-item-url-<?php echo esc_attr( $item_id ); ?>">
							<?php _e( 'URL', 'agni-halena-plugin' ); ?><br />
							<input type="text" id="edit-menu-item-url-<?php echo esc_attr( $item_id ); ?>" class="widefat code edit-menu-item-url" name="menu-item-url[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->url ); ?>" />
						</label>
					</p>
				<?php endif; ?>
				<p class="description description-wide">
					<label for="edit-menu-item-title-<?php echo esc_attr( $item_id ); ?>">
						<?php _e( 'Navigation Label', 'agni-halena-plugin' ); ?><br />
						<input type="text" id="edit-menu-item-title-<?php echo esc_attr( $item_id ); ?>" class="widefat edit-menu-item-title" name="menu-item-title[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->title ); ?>" />
					</label>
				</p>
				<p class="field-title-attribute field-attr-title description description-wide">
					<label for="edit-menu-item-attr-title-<?php echo esc_attr( $item_id ); ?>">
						<?php _e( 'Title Attribute', 'agni-halena-plugin' ); ?><br />
						<input type="text" id="edit-menu-item-attr-title-<?php echo esc_attr( $item_id ); ?>" class="widefat edit-menu-item-attr-title" name="menu-item-attr-title[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->post_excerpt ); ?>" />
					</label>
				</p>
				<p class="field-link-target description">
					<label for="edit-menu-item-target-<?php echo esc_attr( $item_id ); ?>">
						<input type="checkbox" id="edit-menu-item-target-<?php echo esc_attr( $item_id ); ?>" value="_blank" name="menu-item-target[<?php echo esc_attr( $item_id ); ?>]"<?php checked( $item->target, '_blank' ); ?> />
						<?php _e( 'Open link in a new tab', 'agni-halena-plugin' ); ?>
					</label>
				</p>
				<p class="field-css-classes description description-thin">
					<label for="edit-menu-item-classes-<?php echo esc_attr( $item_id ); ?>">
						<?php _e( 'CSS Classes (optional)', 'agni-halena-plugin' ); ?><br />
						<input type="text" id="edit-menu-item-classes-<?php echo esc_attr( $item_id ); ?>" class="widefat code edit-menu-item-classes" name="menu-item-classes[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( implode(' ', $item->classes ) ); ?>" />
					</label>
				</p>
				<p class="field-xfn description description-thin">
					<label for="edit-menu-item-xfn-<?php echo esc_attr( $item_id ); ?>">
						<?php _e( 'Link Relationship (XFN)', 'agni-halena-plugin' ); ?><br />
						<input type="text" id="edit-menu-item-xfn-<?php echo esc_attr( $item_id ); ?>" class="widefat code edit-menu-item-xfn" name="menu-item-xfn[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->xfn ); ?>" />
					</label>
				</p>
				<?php
				// This is the added section
				do_action( 'wp_nav_menu_item_custom_fields', $item_id, $item, $depth, $args );
				// end added section
				?>
				<p class="field-description description description-wide">
					<label for="edit-menu-item-description-<?php echo esc_attr( $item_id ); ?>">
						<?php _e( 'Description', 'agni-halena-plugin' ); ?><br />
						<textarea id="edit-menu-item-description-<?php echo esc_attr( $item_id ); ?>" class="widefat edit-menu-item-description" rows="3" cols="20" name="menu-item-description[<?php echo esc_attr( $item_id ); ?>]"><?php echo esc_html( $item->description ); // textarea_escaped ?></textarea>
						<span class="description"><?php _e('The description will be displayed in the menu if the current theme supports it.', 'agni-halena-plugin'); ?></span>
					</label>
				</p>

				<fieldset class="field-move hide-if-no-js description description-wide">
					<span class="field-move-visual-label" aria-hidden="true"><?php _e( 'Move', 'agni-halena-plugin' ); ?></span>
					<button type="button" class="button-link menus-move menus-move-up" data-dir="up"><?php _e( 'Up one', 'agni-halena-plugin' ); ?></button>
					<button type="button" class="button-link menus-move menus-move-down" data-dir="down"><?php _e( 'Down one', 'agni-halena-plugin' ); ?></button>
					<button type="button" class="button-link menus-move menus-move-left" data-dir="left"></button>
					<button type="button" class="button-link menus-move menus-move-right" data-dir="right"></button>
					<button type="button" class="button-link menus-move menus-move-top" data-dir="top"><?php _e( 'To the top', 'agni-halena-plugin' ); ?></button>
				</fieldset>

				<div class="menu-item-actions description-wide submitbox">
					<?php if ( 'custom' != $item->type && $original_title !== false ) : ?>
						<p class="link-to-original">
							<?php printf( __('Original: %s', 'agni-halena-plugin'), '<a href="' . esc_attr( $item->url ) . '">' . esc_html( $original_title ) . '</a>' ); ?>
						</p>
					<?php endif; ?>
					<a class="item-delete submitdelete deletion" id="delete-<?php echo esc_attr( $item_id ); ?>" href="<?php
					echo wp_nonce_url(
						add_query_arg(
							array(
								'action' => 'delete-menu-item',
								'menu-item' => $item_id,
							),
							admin_url( 'nav-menus.php' )
						),
						'delete-menu_item_' . $item_id
					); ?>"><?php _e( 'Remove menu item', 'agni-halena-plugin' ); ?></a> <span class="meta-sep hide-if-no-js"> | </span> <a class="item-cancel submitcancel hide-if-no-js" id="cancel-<?php echo esc_attr( $item_id ); ?>" href="<?php echo esc_url( add_query_arg( array( 'edit-menu-item' => $item_id, 'cancel' => time() ), admin_url( 'nav-menus.php' ) ) );
						?>#menu-item-settings-<?php echo esc_attr( $item_id ); ?>"><?php _e('Cancel', 'agni-halena-plugin'); ?></a>
				</div>

				<input class="menu-item-data-db-id" type="hidden" name="menu-item-db-id[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item_id ); ?>" />
				<input class="menu-item-data-object-id" type="hidden" name="menu-item-object-id[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->object_id ); ?>" />
				<input class="menu-item-data-object" type="hidden" name="menu-item-object[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->object ); ?>" />
				<input class="menu-item-data-parent-id" type="hidden" name="menu-item-parent-id[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->menu_item_parent ); ?>" />
				<input class="menu-item-data-position" type="hidden" name="menu-item-position[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->menu_order ); ?>" />
				<input class="menu-item-data-type" type="hidden" name="menu-item-type[<?php echo esc_attr( $item_id ); ?>]" value="<?php echo esc_attr( $item->type ); ?>" />
			</div><!-- .menu-item-settings-->
			<ul class="menu-item-transport"></ul>
		<?php
		$output .= ob_get_clean();
	}
}
