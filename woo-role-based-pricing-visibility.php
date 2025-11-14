<?php
/**
 * Plugin Name: Woo Role-Based Pricing & Visibility
 * Description: Product/category pricing and visibility based on user role for WooCommerce.
 * Author: Lorenzo Accorinti - https://www.linkedin.com/in/lorenzo-accorinti/
 * Author URI: https://www.linkedin.com/in/lorenzo-accorinti/
 * GitHub: https://github.com/lorenzoaccorinti/woo-role-based-pricing-visibility
 * Version: 1.0.0
 * License: GPLv2 or later
 * Text Domain: wc-rbpv
 */

/**
 * Dev notes:
 * - Le categorie prodotto ora hanno un campo UI "Nascondi categoria" (multi-selezione ruoli) in Aggiungi/Modifica categoria.
 * - Le categorie nascoste per un ruolo vengono escluse da tutte le query, widget, shortcode e liste del sito.
 * - Le categorie nascoste vengono bloccate come 404 se si visita l'archivio direttamente.
 * - I prodotti assegnati a categorie nascoste risultano nascosti per quel ruolo (anche su accesso diretto).
 * - I prodotti ora hanno un campo UI "Nascondi prodotto" (multi-selezione ruoli) nella tab Advanced della scheda prodotto.
 * - I prezzi per ruolo sono gestiti direttamente nella tab General della scheda prodotto, con campi dedicati per ogni ruolo.
 */


if ( ! defined( 'ABSPATH' ) ) exit;

class WCRBPV_Plugin {
    const CAT_META_KEY  = '_wc_rbpv_hidden_roles'; // term meta: array of roles to hide the category from
    const PROD_META_KEY = '_wc_rbpv_hidden_roles'; // post meta: array of roles to hide the product from
    const PRICE_META_PREFIX = '_wc_rbpv_price_'; // post meta: _wc_rbpv_price_{role} = regular price for role
    const SALE_PRICE_META_PREFIX = '_wc_rbpv_sale_price_'; // post meta: _wc_rbpv_sale_price_{role} = sale price for role
    
    private static $filtering_terms = false; // Flag to avoid infinite loops

    public function __construct() {
        // Pricing filters - use role-based prices from post meta
        add_filter( 'woocommerce_product_get_price', [ $this, 'filter_product_price' ], 20, 2 );
        add_filter( 'woocommerce_product_get_regular_price', [ $this, 'filter_product_regular_price' ], 20, 2 );
        add_filter( 'woocommerce_product_get_sale_price', [ $this, 'filter_product_sale_price' ], 20, 2 );
        add_filter( 'woocommerce_variation_prices_price', [ $this, 'filter_variation_price' ], 20, 3 );
        add_filter( 'woocommerce_variation_prices_regular_price', [ $this, 'filter_variation_regular_price' ], 20, 3 );
        add_filter( 'woocommerce_variation_prices_sale_price', [ $this, 'filter_variation_sale_price' ], 20, 3 );
        add_filter( 'woocommerce_get_price_html', [ $this, 'maybe_force_price_html_refresh' ], 20, 2 );

        // Visibility: query side (shop/archive/search/related widgets)
        add_action( 'pre_get_posts', [ $this, 'filter_product_queries' ], 999 );
        add_filter( 'woocommerce_product_query', [ $this, 'filter_product_queries' ], 999 );
        add_filter( 'woocommerce_shortcode_products_query', [ $this, 'filter_woocommerce_shortcode_query' ], 999, 3 );

        // Visibility: single product + archivio categoria accesso diretto
        add_action( 'template_redirect', [ $this, 'block_hidden_single_product' ] );
        add_action( 'template_redirect', [ $this, 'maybe_block_hidden_category_archive' ] );

        // Visibility: filter categories from all queries and widgets
        add_filter( 'get_terms', [ $this, 'filter_hidden_categories_from_terms' ], 10, 4 );
        add_filter( 'woocommerce_product_categories_widget_args', [ $this, 'filter_widget_categories' ] );
        add_filter( 'woocommerce_product_categories', [ $this, 'filter_product_categories_shortcode' ], 10, 2 );

        // Catalog price hash so WC caches per-role
        add_filter( 'woocommerce_get_catalog_price_hash', [ $this, 'catalog_price_hash' ] );

        // ===== UI categoria prodotto: campo "Nascondi categoria" (multi-selezione ruoli) =====
        add_action( 'product_cat_add_form_fields', [ $this, 'render_cat_field_add' ] );
        add_action( 'product_cat_edit_form_fields', [ $this, 'render_cat_field_edit' ] );
        add_action( 'created_product_cat', [ $this, 'save_cat_field' ] );
        add_action( 'edited_product_cat', [ $this, 'save_cat_field' ] );

        // ===== UI prodotto: campo "Nascondi prodotto" (multi-selezione ruoli) nella tab Advanced =====
        add_action( 'woocommerce_product_options_advanced', [ $this, 'render_product_advanced_field' ] );
        
        // ===== UI prodotto: campi prezzi per ruolo nella tab General =====
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_product_price_fields' ] );
        
        // ===== Save product meta (both visibility and prices) =====
        add_action( 'woocommerce_process_product_meta', [ $this, 'save_product_meta' ] );
        
        // ===== UI variazione: campi prezzi per ruolo =====
        add_action( 'woocommerce_product_after_variable_attributes', [ $this, 'render_variation_price_fields' ], 10, 3 );
        add_action( 'woocommerce_save_product_variation', [ $this, 'save_variation_price_fields' ], 10, 2 );
    }

    /* --------------------------------------
     * Settings helpers
     * ------------------------------------ */

    public static function get_current_role() : string {
        if ( is_user_logged_in() ) {
            $user  = wp_get_current_user();
            $roles = (array) $user->roles;
            if ( ! empty( $roles ) ) {
                // Return the first role (primary role)
                // For administrators, this will be 'administrator'
                return $roles[0];
            }
        }
        return 'guest';
    }

    /** Returns the list of available roles + guest */
    public static function get_all_roles() : array {
        global $wp_roles; if ( ! isset( $wp_roles ) ) $wp_roles = new WP_Roles();
        $roles = array_keys( $wp_roles->roles );
        array_unshift( $roles, 'guest' );
        return array_unique( $roles );
    }

    /* --------------------------------------
     * PRICING - Role-based prices from post meta
     * ------------------------------------ */

    /**
     * Gets the price for role from post meta, or returns null if not set
     */
    public static function get_role_price( $product_id, $role, $price_type = 'regular' ) {
        $meta_key = ( $price_type === 'sale' ) ? self::SALE_PRICE_META_PREFIX : self::PRICE_META_PREFIX;
        $meta_key .= $role;
        $price = get_post_meta( $product_id, $meta_key, true );
        if ( $price === '' || $price === false ) {
            return null;
        }
        return wc_format_decimal( $price, wc_get_price_decimals() );
    }

    public function filter_product_price( $price, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) return $price;
        if ( ! $product ) return $price;
        
        $role = self::get_current_role();
        $product_id = $product->get_id();
        
        // Check first if there's a sale price for role
        $sale_price = self::get_role_price( $product_id, $role, 'sale' );
        if ( $sale_price !== null && $sale_price !== '' ) {
            return $sale_price;
        }
        
        // Otherwise check the regular price for role
        $role_price = self::get_role_price( $product_id, $role, 'regular' );
        if ( $role_price !== null && $role_price !== '' ) {
            return $role_price;
        }
        
        // If no role price, return original price
        return $price;
    }

    public function filter_product_regular_price( $price, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) return $price;
        if ( ! $product ) return $price;
        
        $role = self::get_current_role();
        $product_id = $product->get_id();
        
        $role_price = self::get_role_price( $product_id, $role, 'regular' );
        if ( $role_price !== null && $role_price !== '' ) {
            return $role_price;
        }
        
        return $price;
    }

    public function filter_product_sale_price( $price, $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) return $price;
        if ( ! $product ) return $price;
        
        $role = self::get_current_role();
        $product_id = $product->get_id();
        
        $role_price = self::get_role_price( $product_id, $role, 'sale' );
        if ( $role_price !== null && $role_price !== '' ) {
            return $role_price;
        }
        
        return $price;
    }

    public function filter_variation_price( $price, $variation, $product ) {
        if ( ! $variation ) return $price;
        
        $role = self::get_current_role();
        $variation_id = $variation->get_id();
        
        // Check first if there's a sale price for role
        $sale_price = self::get_role_price( $variation_id, $role, 'sale' );
        if ( $sale_price !== null ) {
            return $sale_price;
        }
        
        // Otherwise check the regular price for role
        $role_price = self::get_role_price( $variation_id, $role, 'regular' );
        if ( $role_price !== null ) {
            return $role_price;
        }
        
        return $price;
    }

    public function filter_variation_regular_price( $price, $variation, $product ) {
        if ( ! $variation ) return $price;
        
        $role = self::get_current_role();
        $variation_id = $variation->get_id();
        
        $role_price = self::get_role_price( $variation_id, $role, 'regular' );
        if ( $role_price !== null ) {
            return $role_price;
        }
        
        return $price;
    }

    public function filter_variation_sale_price( $price, $variation, $product ) {
        if ( ! $variation ) return $price;
        
        $role = self::get_current_role();
        $variation_id = $variation->get_id();
        
        $role_price = self::get_role_price( $variation_id, $role, 'sale' );
        if ( $role_price !== null ) {
            return $role_price;
        }
        
        return $price;
    }

    public function maybe_force_price_html_refresh( $price_html, $product ) {
        if ( $product && $product->is_type( 'variable' ) ) {
            $product->get_variation_price( 'min', true );
            $product->get_variation_price( 'max', true );
        }
        return $price_html;
    }

    public function catalog_price_hash( $hash ) {
        $role = self::get_current_role();
        $hash['wc_rbpv_role'] = $role;
        return $hash;
    }

    /* --------------------------------------
     * VISIBILITY – categories and products via UI
     * ------------------------------------ */

    /**
     * Returns true if the category (term_id) is hidden for the current role
     */
    public static function is_term_hidden_for_current_role( $term_id ) : bool {
        $hidden = get_term_meta( $term_id, self::CAT_META_KEY, true );
        $hidden = is_array( $hidden ) ? $hidden : [];
        $role = self::get_current_role();
        return in_array( $role, $hidden, true );
    }

    /**
     * Returns true if the product (post_id) is hidden for the current role
     * 
     * A product is hidden if EITHER:
     * 1. The product is directly hidden for the role (via product meta)
     * 2. The product belongs to a category that is hidden for the role
     * 
     * @param int $product_id The product post ID
     * @return bool True if product should be hidden, false otherwise
     */
    public static function is_product_hidden_for_current_role( $product_id ) : bool {
        $role = self::get_current_role();
        
        // Level 1: Check if product is directly hidden (post meta)
        $hidden = get_post_meta( $product_id, self::PROD_META_KEY, true );
        $hidden = is_array( $hidden ) ? $hidden : [];
        if ( in_array( $role, $hidden, true ) ) {
            return true;
        }
        
        // Level 2: Check if product belongs to any hidden category
        $terms = wp_get_post_terms( $product_id, 'product_cat', [ 'fields' => 'ids' ] );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
            foreach ( (array) $terms as $term_id ) {
                if ( self::is_term_hidden_for_current_role( $term_id ) ) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Finds all categories to hide for the current role
     */
    public static function get_hidden_category_term_ids_for_current_role() : array {
        global $wpdb;
        $role = self::get_current_role();
        $meta_key = self::CAT_META_KEY;
        
        // Always use direct database query to avoid get_terms filter conflicts
        // Get all term_ids that have the meta_key set
        $all_term_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT term_id 
            FROM {$wpdb->termmeta} 
            WHERE meta_key = %s",
            $meta_key
        ) );
        
        if ( empty( $all_term_ids ) ) {
            return [];
        }
        
        // Check each term to see if the role is in the hidden roles array
        $hidden_term_ids = [];
        foreach ( $all_term_ids as $term_id ) {
            $hidden_roles = get_term_meta( $term_id, $meta_key, true );
            if ( ! is_array( $hidden_roles ) ) {
                continue;
            }
            if ( in_array( $role, $hidden_roles, true ) ) {
                $hidden_term_ids[] = $term_id;
            }
        }
        
        return array_map( 'absint', $hidden_term_ids );
    }

    /**
     * Finds all products to hide for the current role
     * 
     * A product is hidden if EITHER:
     * 1. The product is directly hidden for the role (via product meta)
     * 2. The product belongs to a category that is hidden for the role
     * 
     * @return array Array of product post IDs that should be hidden
     */
    public static function get_hidden_product_ids_for_current_role() : array {
        global $wpdb;
        $role = self::get_current_role();
        $meta_key = self::PROD_META_KEY;
        $hidden_ids = [];
        
        // Get directly hidden products (from post meta)
        // Use direct database query to avoid WP_Query filter conflicts and ensure accuracy
        $all_product_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s",
            $meta_key
        ) );
        
        if ( ! empty( $all_product_ids ) ) {
            foreach ( $all_product_ids as $product_id ) {
                $hidden_roles = get_post_meta( $product_id, $meta_key, true );
                if ( ! is_array( $hidden_roles ) ) {
                    continue;
                }
                if ( in_array( $role, $hidden_roles, true ) ) {
                    $hidden_ids[] = $product_id;
                }
            }
        }
        
        // Get products in hidden categories (second level of hiding)
        $hide_cats_ids = self::get_hidden_category_term_ids_for_current_role();
        if ( ! empty( $hide_cats_ids ) ) {
            // Use direct database query to avoid potential issues with WP_Query filters
            // First get term_taxonomy_id from term_id, then find products
            $placeholders = implode( ',', array_fill( 0, count( $hide_cats_ids ), '%d' ) );
            
            // Get term_taxonomy_id for the hidden category term_ids
            $term_taxonomy_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT term_taxonomy_id 
                FROM {$wpdb->term_taxonomy} 
                WHERE taxonomy = 'product_cat'
                AND term_id IN ($placeholders)",
                ...$hide_cats_ids
            ) );
            
            if ( ! empty( $term_taxonomy_ids ) ) {
                $tt_placeholders = implode( ',', array_fill( 0, count( $term_taxonomy_ids ), '%d' ) );
                $products_in_hidden_cats = $wpdb->get_col( $wpdb->prepare(
                    "SELECT DISTINCT tr.object_id 
                    FROM {$wpdb->term_relationships} tr
                    WHERE tr.term_taxonomy_id IN ($tt_placeholders)
                    AND tr.object_id IN (
                        SELECT ID FROM {$wpdb->posts} 
                        WHERE post_type = 'product' 
                        AND post_status = 'publish'
                    )",
                    ...$term_taxonomy_ids
                ) );
                
                if ( ! empty( $products_in_hidden_cats ) ) {
                    $products_in_hidden_cats = array_map( 'absint', $products_in_hidden_cats );
                    $hidden_ids = array_unique( array_merge( $hidden_ids, $products_in_hidden_cats ) );
                }
            }
        }
        
        return array_map( 'absint', $hidden_ids );
    }

    /** Filters product queries (shop/archive/search/WooCommerce queries) */
    public function filter_product_queries( $q ) {
        if ( is_admin() ) return;
        
        // Check if this is a product query
        $post_type = $q->get( 'post_type' );
        $is_product_query = ( $post_type === 'product' || ( is_array( $post_type ) && in_array( 'product', $post_type, true ) ) );
        
        // Also check for shop/archive/search queries
        $is_product_context = $is_product_query || 
                              $q->is_post_type_archive( 'product' ) || 
                              $q->is_tax( [ 'product_cat', 'product_tag' ] ) || 
                              ( $q->is_search() && $is_product_query );
        
        if ( ! $is_product_context ) return;

        // Get all hidden products (includes products in hidden categories)
        $hide_prod_ids = self::get_hidden_product_ids_for_current_role();
        
        // Exclude all hidden products
        if ( ! empty( $hide_prod_ids ) ) {
            $post__not_in = (array) $q->get( 'post__not_in' );
            $q->set( 'post__not_in', array_unique( array_merge( $post__not_in, $hide_prod_ids ) ) );
        }
    }

    /** Filters WooCommerce shortcode queries */
    public function filter_woocommerce_shortcode_query( $query_args, $atts, $type ) {
        if ( is_admin() ) return $query_args;
        
        // Get all hidden products (includes products in hidden categories)
        $hide_prod_ids = self::get_hidden_product_ids_for_current_role();
        
        // Exclude all hidden products
        if ( ! empty( $hide_prod_ids ) ) {
            if ( ! isset( $query_args['post__not_in'] ) ) {
                $query_args['post__not_in'] = [];
            }
            $query_args['post__not_in'] = array_unique( array_merge( (array) $query_args['post__not_in'], $hide_prod_ids ) );
        }
        
        return $query_args;
    }


    /** 404 on hidden product due to hidden category or explicitly hidden product */
    public function block_hidden_single_product() {
        if ( ! is_singular( 'product' ) ) return;
        global $post; if ( ! $post ) return;

        // Use the unified method that checks both direct hiding and category-based hiding
        $is_hidden = self::is_product_hidden_for_current_role( $post->ID );
        
        if ( $is_hidden ) {
            if ( current_user_can( 'manage_woocommerce' ) ) return;
            global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); include get_query_template('404'); exit;
        }
    }

    /** 404 on category archive if hidden for the role */
    public function maybe_block_hidden_category_archive() {
        if ( ! is_tax( 'product_cat' ) ) return;
        $term = get_queried_object();
        if ( $term && isset( $term->term_id ) && self::is_term_hidden_for_current_role( $term->term_id ) ) {
            if ( current_user_can( 'manage_woocommerce' ) ) return;
            global $wp_query; $wp_query->set_404(); status_header(404); nocache_headers(); include get_query_template('404'); exit;
        }
    }

    /**
     * Filters hidden categories from all get_terms queries
     */
    public function filter_hidden_categories_from_terms( $terms, $taxonomies, $args, $term_query ) {
        // Skip if not product categories or in admin (except AJAX)
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $terms;
        }

        // Skip if not filtering product_cat taxonomy
        if ( ! in_array( 'product_cat', (array) $taxonomies, true ) ) {
            return $terms;
        }

        // Avoid infinite loops: if already filtering, return terms without filtering
        if ( self::$filtering_terms ) {
            return $terms;
        }

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return $terms;
        }

        // Set flag to avoid recursion
        self::$filtering_terms = true;
        
        try {
            $hidden_ids = self::get_hidden_category_term_ids_for_current_role();
        } finally {
            // Remove flag even on error
            self::$filtering_terms = false;
        }
        
        if ( empty( $hidden_ids ) ) {
            return $terms;
        }

        // Filter out hidden categories (no admin bypass - categories hidden for a role should be hidden even for admins)
        $filtered = [];
        foreach ( $terms as $term ) {
            if ( is_object( $term ) && isset( $term->term_id ) ) {
                if ( ! in_array( (int) $term->term_id, $hidden_ids, true ) ) {
                    $filtered[] = $term;
                }
            } elseif ( is_numeric( $term ) ) {
                if ( ! in_array( (int) $term, $hidden_ids, true ) ) {
                    $filtered[] = $term;
                }
            } else {
                $filtered[] = $term;
            }
        }

        return $filtered;
    }

    /**
     * Filters categories in WooCommerce Product Categories widget and shortcode
     */
    public function filter_widget_categories( $args ) {
        $hidden_ids = self::get_hidden_category_term_ids_for_current_role();
        if ( empty( $hidden_ids ) ) {
            return $args;
        }

        // Add exclude parameter (no admin bypass - categories hidden for a role should be hidden even for admins)
        if ( ! isset( $args['exclude'] ) || empty( $args['exclude'] ) ) {
            $args['exclude'] = $hidden_ids;
        } else {
            // Merge with existing excludes
            $existing = is_array( $args['exclude'] ) ? $args['exclude'] : explode( ',', $args['exclude'] );
            $args['exclude'] = array_unique( array_merge( array_map( 'absint', $existing ), $hidden_ids ) );
        }

        return $args;
    }

    /**
     * Filters categories in [product_categories] shortcode
     */
    public function filter_product_categories_shortcode( $args, $atts ) {
        $hidden_ids = self::get_hidden_category_term_ids_for_current_role();
        if ( empty( $hidden_ids ) ) {
            return $args;
        }

        // Add exclude parameter (no admin bypass - categories hidden for a role should be hidden even for admins)
        if ( ! isset( $args['exclude'] ) || empty( $args['exclude'] ) ) {
            $args['exclude'] = implode( ',', $hidden_ids );
        } else {
            // Merge with existing excludes
            $existing = is_array( $args['exclude'] ) ? $args['exclude'] : explode( ',', $args['exclude'] );
            $args['exclude'] = implode( ',', array_unique( array_merge( array_map( 'absint', $existing ), $hidden_ids ) ) );
        }

        return $args;
    }


    /* --------------------------------------
     * UI Category: rendering & saving multi-select roles field
     * ------------------------------------ */

    public function render_cat_field_add( $taxonomy ) {
        $roles = self::get_all_roles();
        echo '<div class="form-field">';
        echo '<label for="wc_rbpv_hidden_roles">' . esc_html__( 'Hide category', 'wc-rbpv' ) . '</label>';
        echo '<select name="wc_rbpv_hidden_roles[]" id="wc_rbpv_hidden_roles" multiple style="min-width:280px;">';
        foreach ( $roles as $role ) {
            echo '<option value="' . esc_attr( $role ) . '">' . esc_html( $role ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Select roles for which this category will not be visible (including products within it).', 'wc-rbpv' ) . '</p>';
        echo '</div>';
    }

    public function render_cat_field_edit( $term ) {
        $roles = self::get_all_roles();
        $saved = get_term_meta( $term->term_id, self::CAT_META_KEY, true );
        $saved = is_array( $saved ) ? $saved : [];
        echo '<tr class="form-field">';
        echo '<th scope="row"><label for="wc_rbpv_hidden_roles">' . esc_html__( 'Hide category', 'wc-rbpv' ) . '</label></th>';
        echo '<td>';
        echo '<select name="wc_rbpv_hidden_roles[]" id="wc_rbpv_hidden_roles" multiple style="min-width:280px;">';
        foreach ( $roles as $role ) {
            $sel = in_array( $role, $saved, true ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $role ) . '"' . $sel . '>' . esc_html( $role ) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Select roles for which this category will not be visible (including products within it).', 'wc-rbpv' ) . '</p>';
        echo '</td>';
        echo '</tr>';
    }

    public function save_cat_field( $term_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $roles = isset( $_POST['wc_rbpv_hidden_roles'] ) ? (array) $_POST['wc_rbpv_hidden_roles'] : [];
        $roles = array_values( array_intersect( $roles, self::get_all_roles() ) ); // whitelist roles
        update_term_meta( $term_id, self::CAT_META_KEY, $roles );
    }

    /* --------------------------------------
     * UI Product: "Hide product" field in Advanced tab (multi-select roles)
     * ------------------------------------ */

    public function render_product_advanced_field() {
        global $post;
        if ( ! $post || ! current_user_can( 'manage_woocommerce' ) ) return;
        
        $roles = self::get_all_roles();
        $saved = get_post_meta( $post->ID, self::PROD_META_KEY, true );
        $saved = is_array( $saved ) ? $saved : [];
        
        echo '<div class="options_group">';
        echo '<p class="form-field">';
        echo '<label for="wc_rbpv_product_hidden_roles">' . esc_html__( 'Hide product for role', 'wc-rbpv' ) . '</label>';
        echo '<select name="wc_rbpv_product_hidden_roles[]" id="wc_rbpv_product_hidden_roles" multiple style="width:100%;min-height:120px;">';
        foreach ( $roles as $role ) {
            $sel = in_array( $role, $saved, true ) ? ' selected' : '';
            echo '<option value="' . esc_attr( $role ) . '"' . $sel . '>' . esc_html( $role ) . '</option>';
        }
        echo '</select>';
        echo '<span class="description">' . esc_html__( 'Select roles for which this product will not be visible in the catalog and will not be directly accessible.', 'wc-rbpv' ) . '</span>';
        echo '</p>';
        echo '</div>';
    }

    /* --------------------------------------
     * UI Product: role-based price fields in General tab
     * ------------------------------------ */

    public function render_product_price_fields() {
        global $post;
        if ( ! $post || ! current_user_can( 'manage_woocommerce' ) ) return;
        
        $roles = self::get_all_roles();
        
        echo '<div class="options_group wc-rbpv-price-fields">';
        echo '<h3 style="padding:10px 0 10px 10px;">' . esc_html__( 'Prices by Role', 'wc-rbpv' ) . '</h3>';
        echo '<p class="description" style="padding:0 0 10px 10px;">' . esc_html__( 'Set specific prices for each role. Leave empty to use the standard price.', 'wc-rbpv' ) . '</p>';
        
        foreach ( $roles as $role ) {
            $regular_key = self::PRICE_META_PREFIX . $role;
            $sale_key = self::SALE_PRICE_META_PREFIX . $role;
            $regular_value = get_post_meta( $post->ID, $regular_key, true );
            $sale_value = get_post_meta( $post->ID, $sale_key, true );
            
            echo '<div class="wc-rbpv-role-price-group" style="border-top:1px solid #eee;padding:10px 0;margin:10px 0;">';
            echo '<h4 style="margin:0 0 10px 10px;">' . esc_html( ucfirst( $role ) ) . '</h4>';
            
            woocommerce_wp_text_input( [
                'id'          => $regular_key,
                'name'        => $regular_key,
                'label'       => __( 'Regular price', 'wc-rbpv' ),
                'placeholder' => __( 'Standard price', 'wc-rbpv' ),
                'value'       => $regular_value,
                'type'        => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
            ] );
            
            woocommerce_wp_text_input( [
                'id'          => $sale_key,
                'name'        => $sale_key,
                'label'       => __( 'Sale price', 'wc-rbpv' ),
                'placeholder' => __( 'Sale price', 'wc-rbpv' ),
                'value'       => $sale_value,
                'type'        => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
            ] );
            
            echo '</div>';
        }
        
        echo '</div>';
    }

    /**
     * Saves product meta (visibility and prices) in a single method
     */
    public function save_product_meta( $post_id ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        
        if ( get_post_type( $post_id ) !== 'product' ) {
            return;
        }
        
        // Save visibility roles
        $roles = isset( $_POST['wc_rbpv_product_hidden_roles'] ) ? (array) $_POST['wc_rbpv_product_hidden_roles'] : [];
        $roles = array_values( array_intersect( $roles, self::get_all_roles() ) ); // whitelist roles
        update_post_meta( $post_id, self::PROD_META_KEY, $roles );
        
        // Save role-based prices
        $all_roles = self::get_all_roles();
        foreach ( $all_roles as $role ) {
            $regular_key = self::PRICE_META_PREFIX . $role;
            $sale_key = self::SALE_PRICE_META_PREFIX . $role;
            
            // Save regular price
            if ( isset( $_POST[ $regular_key ] ) ) {
                $regular_price = sanitize_text_field( $_POST[ $regular_key ] );
                if ( $regular_price === '' ) {
                    delete_post_meta( $post_id, $regular_key );
                } else {
                    update_post_meta( $post_id, $regular_key, wc_format_decimal( $regular_price ) );
                }
            }
            
            // Save sale price
            if ( isset( $_POST[ $sale_key ] ) ) {
                $sale_price = sanitize_text_field( $_POST[ $sale_key ] );
                if ( $sale_price === '' ) {
                    delete_post_meta( $post_id, $sale_key );
                } else {
                    update_post_meta( $post_id, $sale_key, wc_format_decimal( $sale_price ) );
                }
            }
        }
    }

    /* --------------------------------------
     * UI Variation: role-based price fields
     * ------------------------------------ */

    public function render_variation_price_fields( $loop, $variation_data, $variation ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        
        $roles = self::get_all_roles();
        $variation_id = $variation->ID;
        
        // Add custom CSS for better layout
        echo '<style>
            .wc-rbpv-variation-price-fields {
                clear: both;
            }
            .wc-rbpv-variation-role-price-group {
                margin-bottom: 15px;
            }
            .wc-rbpv-variation-role-price-group strong {
                display: block;
                margin-bottom: 10px;
                font-size: 13px;
                color: #23282d;
            }
            .wc-rbpv-variation-role-price-group .form-row {
                display: inline-block;
                width: 48%;
                margin-right: 2%;
                vertical-align: top;
            }
            .wc-rbpv-variation-role-price-group .form-row-last {
                margin-right: 0;
            }
            .wc-rbpv-variation-role-price-group .form-row label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            .wc-rbpv-variation-role-price-group .form-row input[type="number"] {
                width: 100%;
                box-sizing: border-box;
            }
        </style>';
        
        echo '<div class="wc-rbpv-variation-price-fields" style="border-top:1px solid #eee;padding:15px 0;margin:15px 0;clear:both;">';
        echo '<h4 style="margin:0 0 15px;">' . esc_html__( 'Prices by Role', 'wc-rbpv' ) . '</h4>';
        echo '<p class="description" style="margin:0 0 15px;">' . esc_html__( 'Set specific prices for each role. Leave empty to use the standard variation price.', 'wc-rbpv' ) . '</p>';
        
        foreach ( $roles as $role ) {
            $regular_key = self::PRICE_META_PREFIX . $role;
            $sale_key = self::SALE_PRICE_META_PREFIX . $role;
            $regular_value = get_post_meta( $variation_id, $regular_key, true );
            $sale_value = get_post_meta( $variation_id, $sale_key, true );
            
            echo '<div class="wc-rbpv-variation-role-price-group" style="border:1px solid #ddd;padding:15px;margin:10px 0;background:#f9f9f9;clear:both;overflow:hidden;">';
            echo '<strong style="display:block;margin-bottom:10px;font-size:13px;color:#23282d;">' . esc_html( ucfirst( $role ) ) . '</strong>';
            echo '<div class="form-row" style="display:inline-block;width:48%;margin-right:2%;vertical-align:top;">';
            
            woocommerce_wp_text_input( [
                'id'            => $regular_key . '_' . $loop,
                'name'          => $regular_key . '[' . $loop . ']',
                'label'         => __( 'Prezzo regolare', 'wc-rbpv' ),
                'placeholder'   => __( 'Prezzo standard', 'wc-rbpv' ),
                'value'         => $regular_value,
                'wrapper_class' => '',
                'type'          => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
            ] );
            
            echo '</div>';
            echo '<div class="form-row form-row-last" style="display:inline-block;width:48%;margin-right:0;vertical-align:top;">';
            
            woocommerce_wp_text_input( [
                'id'            => $sale_key . '_' . $loop,
                'name'          => $sale_key . '[' . $loop . ']',
                'label'         => __( 'Prezzo scontato', 'wc-rbpv' ),
                'placeholder'   => __( 'Prezzo standard', 'wc-rbpv' ),
                'value'         => $sale_value,
                'wrapper_class' => '',
                'type'          => 'number',
                'custom_attributes' => [
                    'step' => '0.01',
                    'min'  => '0',
                ],
            ] );
            
            echo '</div>';
            echo '<div style="clear:both;"></div>';
            echo '</div>';
        }
        
        echo '</div>';
    }

    public function save_variation_price_fields( $variation_id, $loop ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        
        $roles = self::get_all_roles();
        
        foreach ( $roles as $role ) {
            $regular_key = self::PRICE_META_PREFIX . $role;
            $sale_key = self::SALE_PRICE_META_PREFIX . $role;
            
            // Save regular price
            if ( isset( $_POST[ $regular_key ][ $loop ] ) ) {
                $regular_price = sanitize_text_field( $_POST[ $regular_key ][ $loop ] );
                if ( $regular_price === '' ) {
                    delete_post_meta( $variation_id, $regular_key );
                } else {
                    update_post_meta( $variation_id, $regular_key, wc_format_decimal( $regular_price ) );
                }
            }
            
            // Save sale price
            if ( isset( $_POST[ $sale_key ][ $loop ] ) ) {
                $sale_price = sanitize_text_field( $_POST[ $sale_key ][ $loop ] );
                if ( $sale_price === '' ) {
                    delete_post_meta( $variation_id, $sale_key );
                } else {
                    update_post_meta( $variation_id, $sale_key, wc_format_decimal( $sale_price ) );
                }
            }
        }
    }
}

// Bootstrap
add_action( 'plugins_loaded', function(){
    if ( class_exists( 'WooCommerce' ) ) {
        new WCRBPV_Plugin();
    }
} );