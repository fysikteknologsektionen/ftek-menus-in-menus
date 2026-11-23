<?php
/*
Plugin Name: Menus In Menus
Description: Avoid giant Nav Menus by aggregating multiple "submenus" into a single Menu.
Version: 1.4
Author: Jeff Stieler
License: GPL2
*/

if ( ! class_exists( 'Voce_Submenu_Nav_Menu_Items' ) ) {
	class Voce_Submenu_Nav_Menu_Items {

		public static function init() {
			add_action(
				'admin_head-nav-menus.php',
				array( __CLASS__, 'add_nav_menu_metabox' )
			);

			add_filter(
				'wp_nav_menu_objects',
				array( __CLASS__, 'expand_submenu_items' )
			);
		}

		/**
		 * Adds the Navigation Menus metabox to Appearance > Menus.
		 */
		public static function add_nav_menu_metabox() {
			$taxonomy = get_taxonomy( 'nav_menu' );

			if ( ! $taxonomy || ! function_exists( 'wp_nav_menu_item_taxonomy_meta_box' ) ) {
				return;
			}

			add_meta_box(
				'add-' . $taxonomy->name,
				$taxonomy->labels->name,
				array( __CLASS__, 'nav_menu_metabox' ),
				'nav-menus',
				'side',
				'default',
				$taxonomy
			);
		}

		/**
		 * Displays the metabox while excluding the menu being edited.
		 *
		 * @param object $object  Taxonomy object.
		 * @param object $box     Metabox arguments.
		 */
		public static function nav_menu_metabox( $object, $box = null ) {
			add_filter(
				'get_terms_args',
				array( __CLASS__, 'exclude_current_menu_from_metabox' )
			);

			wp_nav_menu_item_taxonomy_meta_box( $object, $box );

			remove_filter(
				'get_terms_args',
				array( __CLASS__, 'exclude_current_menu_from_metabox' )
			);
		}

		/**
		 * Excludes the menu currently being edited from the metabox query.
		 *
		 * @param array $args Term query arguments.
		 * @return array
		 */
		public static function exclude_current_menu_from_metabox( $args ) {
			global $nav_menu_selected_id;

			if ( ! empty( $nav_menu_selected_id ) ) {
				$existing = isset( $args['exclude'] ) ? (array) $args['exclude'] : array();
				$args['exclude'] = array_unique(
					array_merge( $existing, array( (int) $nav_menu_selected_id ) )
				);
			}

			return $args;
		}


		/**
		 * Replaces submenu references with the items from those menus, recursively.
		 *
		 * @param array $sorted_menu_items Menu items, in display order.
		 * @return array
		 */
		public static function expand_submenu_items( $sorted_menu_items ) {
			if ( ! is_array( $sorted_menu_items ) ) {
				return $sorted_menu_items;
			}

			return self::expand_menu_items( $sorted_menu_items, array(), 0 );
		}

		/**
		 * Recursively expands submenu references.
		 *
		 * @param array $items       Menu items to process.
		 * @param array $menu_stack  Menu IDs currently being expanded.
		 * @param int   $depth       Current expansion depth.
		 * @return array
		 */
		private static function expand_menu_items( $items, $menu_stack, $depth ) {
			// Protect against excessively deep or circular menu references.
			$max_depth = 20;

			if ( $depth >= $max_depth ) {
				return $items;
			}

			$expanded = array();

			foreach ( $items as $menu_item ) {
				if (
					! isset( $menu_item->type, $menu_item->object, $menu_item->object_id ) ||
						'taxonomy' !== $menu_item->type ||
						'nav_menu' !== $menu_item->object
				) {
					$expanded[] = $menu_item;
					continue;
				}

				$submenu_id = (int) $menu_item->object_id;

				// Leave the reference unchanged if this menu is already in the recursion chain.
				if ( in_array( $submenu_id, $menu_stack, true ) ) {
					$expanded[] = $menu_item;
					continue;
				}

				$submenu_items = wp_get_nav_menu_items( $submenu_id );

				// Keep the reference if the target menu is empty or unavailable.
				if ( ! is_array( $submenu_items ) || empty( $submenu_items ) ) {
					$expanded[] = $menu_item;
					continue;
				}

				$items_with_children = array();

				foreach ( $submenu_items as $submenu_item ) {
					if ( ! empty( $submenu_item->menu_item_parent ) ) {
						$items_with_children[ (int) $submenu_item->menu_item_parent ] = true;
					}
				}

				$parent_id = (int) $menu_item->menu_item_parent;

				foreach ( $submenu_items as $submenu_item ) {
					// Attach roots of the inserted menu where the reference item was attached.
					if ( empty( $submenu_item->menu_item_parent ) ) {
						$submenu_item->menu_item_parent = $parent_id;
					}

					if ( isset( $items_with_children[ (int) $submenu_item->ID ] ) ) {
						$classes = (array) $submenu_item->classes;
						$classes[] = 'menu-item-has-children';
						$submenu_item->classes = array_values( array_unique( $classes ) );
					}
				}

				$next_menu_stack   = $menu_stack;
				$next_menu_stack[] = $submenu_id;

				$expanded_submenu = self::expand_menu_items(
					$submenu_items,
					$next_menu_stack,
					$depth + 1
				);

				foreach ( $expanded_submenu as $expanded_item ) {
					$expanded[] = $expanded_item;
				}
			}

			return $expanded;
		}
	}
}
add_action( 'init', array( 'Voce_Submenu_Nav_Menu_Items', 'init' ) );
