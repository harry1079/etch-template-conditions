<?php
/**
 * Plugin Name: Etch Template Conditions
 * Plugin URI:  https://github.com/your-repo/etch-template-conditions
 * Description: Enables taxonomy-based template conditions for single posts in FSE/EtchWP.
 *              Allows different single templates per taxonomy term (e.g., single-holiday
 *              can resolve to single-holiday-rule-1, single-holiday-eu-event, etc.)
 *              Rules are managed via the admin UI or programmatically via the etch_tc_rules filter.
 * Version:     0.2.0
 * Author:      BMB Holidays
 * License:     GPL-2.0-or-later
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  HOW IT WORKS
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. You define "template condition rules" via the admin UI (Templates menu)
 *     or programmatically via the etch_tc_rules filter.
 *     Each rule maps a CPT + taxonomy + term  →  a template slug.
 *
 *  2. When WordPress resolves the FSE template for a single post, this plugin:
 *     a) Checks if the current post matches any rule.
 *     b) Looks for the target template in this order:
 *        - FSE database (wp_template CPT — i.e. a template you've built in Etch)
 *        - Theme file   (your-theme/templates/{slug}.html)
 *        - Plugin file   (this plugin's /templates/{slug}.html as fallback)
 *     c) Injects the matching template into the FSE resolution pipeline.
 *
 *  3. Templates use a naming convention:
 *        single-{cpt}-rule-{N}
 *     e.g.  single-holiday-rule-1
 *           single-uk-venues-rule-2
 *
 *  4. Rules are checked in order — first match wins. Database rules are
 *     checked before programmatic (filter) rules.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Get all template condition rules.
 *
 * Reads from the database first (wp_options), then merges any
 * programmatic rules added via the etch_tc_rules filter.
 * Database rules take priority (checked first).
 *
 * @return array Array of normalised rule arrays.
 */
function etch_tc_get_rules() {
    $db_rules = get_option( 'etch_tc_rules', array() );
    if ( ! is_array( $db_rules ) ) {
        $db_rules = array();
    }

    $filter_rules = apply_filters( 'etch_tc_rules', array() );
    if ( ! is_array( $filter_rules ) ) {
        $filter_rules = array();
    }

    return array_merge( $db_rules, $filter_rules );
}

/**
 * Get only the database-stored rules.
 *
 * @return array
 */
function etch_tc_get_db_rules() {
    $rules = get_option( 'etch_tc_rules', array() );
    return is_array( $rules ) ? $rules : array();
}

/**
 * Get only the filter-added (programmatic) rules.
 *
 * @return array
 */
function etch_tc_get_filter_rules() {
    $rules = apply_filters( 'etch_tc_rules', array() );
    return is_array( $rules ) ? $rules : array();
}

/**
 * Normalise a rule into a consistent format.
 *
 * Supports two formats:
 *   Simple:  { taxonomy, term }                       → converted to { conditions: [{ taxonomy, term }], match: 'any' }
 *   Multi:   { conditions: [{ taxonomy, term }, ...], match: 'all'|'any' }
 *
 * @param array $rule Raw rule.
 * @return array Normalised rule with 'conditions' and 'match' keys.
 */
function etch_tc_normalise_rule( $rule ) {
    if ( ! isset( $rule['conditions'] ) ) {
        $rule['conditions'] = array(
            array(
                'taxonomy' => $rule['taxonomy'] ?? '',
                'term'     => $rule['term'] ?? '',
            ),
        );
        $rule['match'] = 'any';
        unset( $rule['taxonomy'], $rule['term'] );
    }
    if ( ! isset( $rule['match'] ) ) {
        $rule['match'] = 'any';
    }
    return $rule;
}

/**
 * Generate a human-readable description of a rule's conditions.
 *
 * @param array $rule      Normalised rule (must have 'conditions' and 'match').
 * @param bool  $technical If true, use taxonomy=term format. If false, use friendly labels.
 * @return string
 */
function etch_tc_describe_conditions( $rule, $technical = false ) {
    $rule  = etch_tc_normalise_rule( $rule );
    $parts = array();
    $glue  = ( $rule['match'] === 'all' ) ? ' AND ' : ' OR ';

    foreach ( $rule['conditions'] as $cond ) {
        if ( $technical ) {
            $parts[] = $cond['taxonomy'] . '=' . $cond['term'];
        } else {
            $parts[] = ucfirst( str_replace( array( '-', '_' ), ' ', $cond['term'] ) );
        }
    }

    $desc = implode( $glue, $parts );

    if ( $technical ) {
        return 'post_type=' . $rule['post_type'] . ', ' . $desc;
    }

    return $desc;
}


// ─────────────────────────────────────────────────────────────────────────────
//  CORE: Template Handler
// ─────────────────────────────────────────────────────────────────────────────

class Etch_Template_Conditions {

    private $matched_rule = null;

    public function __construct() {
        add_filter( 'get_block_templates', array( $this, 'swap_block_template' ), 20, 3 );
        add_filter( 'single_template_hierarchy', array( $this, 'add_to_hierarchy' ) );
    }

    private function get_matched_rule() {
        if ( $this->matched_rule !== null ) {
            return $this->matched_rule;
        }

        $this->matched_rule = false;

        if ( ! is_singular() ) {
            return false;
        }

        $post = get_queried_object();
        if ( ! $post || ! isset( $post->post_type ) ) {
            return false;
        }

        $rules = etch_tc_get_rules();
        foreach ( $rules as $rule ) {
            $rule = etch_tc_normalise_rule( $rule );

            if ( $post->post_type !== $rule['post_type'] ) {
                continue;
            }

            $match_mode = $rule['match'];
            $results    = array();

            foreach ( $rule['conditions'] as $condition ) {
                $results[] = has_term( $condition['term'], $condition['taxonomy'], $post->ID );
            }

            if ( $match_mode === 'all' ) {
                $matched = ! in_array( false, $results, true );
            } else {
                $matched = in_array( true, $results, true );
            }

            if ( $matched ) {
                $this->matched_rule = $rule;
                return $this->matched_rule;
            }
        }

        return false;
    }

    public function swap_block_template( $query_result, $query, $template_type ) {
        if ( 'wp_template' !== $template_type ) {
            return $query_result;
        }

        if ( is_admin() || ! is_singular() ) {
            return $query_result;
        }

        $rule = $this->get_matched_rule();
        if ( ! $rule ) {
            return $query_result;
        }

        $target_slug = $rule['template'];
        $theme       = wp_get_theme();

        // Step 1: Check FSE database.
        $existing_db_template = $this->find_db_template( $target_slug, $theme->stylesheet );
        if ( $existing_db_template ) {
            return $this->replace_single_template( $query_result, $existing_db_template, $rule );
        }

        // Step 2: Check theme /templates/ folder.
        $theme_file = $theme->get_template_directory() . '/templates/' . $target_slug . '.html';
        if ( file_exists( $theme_file ) ) {
            $content  = file_get_contents( $theme_file );
            $template = $this->build_block_template( $target_slug, $content, 'theme', $theme, $rule );
            return $this->replace_single_template( $query_result, $template, $rule );
        }

        // Step 3: Check plugin /templates/ folder as fallback.
        $plugin_file = plugin_dir_path( __FILE__ ) . 'templates/' . $target_slug . '.html';
        if ( file_exists( $plugin_file ) ) {
            $content  = file_get_contents( $plugin_file );
            $template = $this->build_block_template( $target_slug, $content, 'plugin', $theme, $rule );
            return $this->replace_single_template( $query_result, $template, $rule );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Etch Template Conditions] Rule matched (%s) but template "%s" was not found.',
                etch_tc_describe_conditions( $rule, true ),
                $target_slug
            ) );
        }

        return $query_result;
    }

    private function find_db_template( $slug, $theme ) {
        $wp_query_args = array(
            'post_name__in'  => array( $slug ),
            'post_type'      => 'wp_template',
            'post_status'    => array( 'publish', 'auto-draft' ),
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => $theme,
                ),
            ),
        );

        $template_query = new WP_Query( $wp_query_args );

        if ( ! empty( $template_query->posts ) ) {
            $post = $template_query->posts[0];
            return _build_block_template_result_from_post( $post );
        }

        return false;
    }

    private function build_block_template( $slug, $content, $source, $theme, $rule ) {
        $template              = new WP_Block_Template();
        $template->type        = 'wp_template';
        $template->theme       = $theme->stylesheet;
        $template->slug        = $slug;
        $template->id          = $theme->stylesheet . '//' . $slug;
        $template->title       = sprintf(
            'Single %s — %s',
            ucfirst( $rule['post_type'] ),
            etch_tc_describe_conditions( $rule )
        );
        $template->description = sprintf(
            'Auto-loaded by Etch Template Conditions: %s',
            etch_tc_describe_conditions( $rule, true )
        );
        $template->source         = $source;
        $template->status         = 'publish';
        $template->has_theme_file = ( $source === 'theme' );
        $template->is_custom      = true;
        $template->content        = $content;
        $template->post_types     = array( $rule['post_type'] );

        return $template;
    }

    private function replace_single_template( $query_result, $new_template, $rule ) {
        $default_slug = 'single-' . $rule['post_type'];

        $replaced = false;
        foreach ( $query_result as $i => $template ) {
            if ( $template->slug === $default_slug || $template->slug === 'single' ) {
                $query_result[ $i ] = $new_template;
                $replaced = true;
                break;
            }
        }

        if ( ! $replaced ) {
            array_unshift( $query_result, $new_template );
        }

        return $query_result;
    }

    public function add_to_hierarchy( $templates ) {
        $rule = $this->get_matched_rule();
        if ( $rule ) {
            array_unshift( $templates, $rule['template'] );
        }
        return $templates;
    }
}

add_action( 'wp', function() {
    new Etch_Template_Conditions();
} );


// ─────────────────────────────────────────────────────────────────────────────
//  ADMIN: Redirect post.php for wp_template to Site Editor
// ─────────────────────────────────────────────────────────────────────────────
//  WordPress doesn't allow post.php editing of wp_template posts. When Etch's
//  "Back to editor" link (or any other path) tries to open post.php for a
//  wp_template or wp_template_part, redirect to the Site Editor instead.
// ─────────────────────────────────────────────────────────────────────────────

add_action( 'admin_init', function() {
    global $pagenow;
    if ( 'post.php' !== $pagenow ) {
        return;
    }

    $post_id = isset( $_GET['post'] ) ? intval( $_GET['post'] ) : 0;
    if ( ! $post_id ) {
        return;
    }

    $post_type = get_post_type( $post_id );
    if ( ! in_array( $post_type, array( 'wp_template', 'wp_template_part' ), true ) ) {
        return;
    }

    $post  = get_post( $post_id );
    $theme = wp_get_theme()->stylesheet;
    $slug  = $post->post_name;

    $url = admin_url( sprintf(
        'site-editor.php?postType=%s&postId=%s&canvas=edit',
        urlencode( $post_type ),
        urlencode( $theme . '//' . $slug )
    ) );

    wp_safe_redirect( $url );
    exit;
} );


// ─────────────────────────────────────────────────────────────────────────────
//  ADMIN: Template Dashboard
// ─────────────────────────────────────────────────────────────────────────────

class Etch_TC_Admin {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_etch_tc_get_taxonomies', array( $this, 'ajax_get_taxonomies' ) );
        add_action( 'wp_ajax_etch_tc_get_terms',      array( $this, 'ajax_get_terms' ) );
        add_action( 'wp_ajax_etch_tc_save_rules',      array( $this, 'ajax_save_rules' ) );
        add_action( 'wp_ajax_etch_tc_delete_rule',     array( $this, 'ajax_delete_rule' ) );
        add_action( 'wp_ajax_etch_tc_reorder_rules',   array( $this, 'ajax_reorder_rules' ) );
        add_action( 'wp_ajax_etch_tc_toggle_etch_flag', array( $this, 'ajax_toggle_etch_flag' ) );
        add_action( 'wp_ajax_etch_tc_save_template_name', array( $this, 'ajax_save_template_name' ) );
        add_action( 'wp_ajax_etch_tc_toggle_status', array( $this, 'ajax_toggle_status' ) );
    }

    public function add_menu_page() {
        add_menu_page(
            'Template Dashboard',
            'Templates',
            'manage_options',
            'etch-template-dashboard',
            array( $this, 'render_page' ),
            'dashicons-layout',
            30
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'toplevel_page_etch-template-dashboard' !== $hook ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', $this->get_admin_css() );

        wp_enqueue_script( 'jquery-ui-sortable' );

        wp_localize_script( 'jquery-ui-sortable', 'etchTC', array(
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'etch_tc_nonce' ),
            'rules'       => etch_tc_get_db_rules(),
            'nextRuleNum' => (int) get_option( 'etch_tc_rule_counter', 1 ),
        ) );
    }

    // ── AJAX Handlers ────────────────────────────────────────────────────

    public function ajax_get_taxonomies() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $post_type  = sanitize_text_field( wp_unslash( $_POST['post_type'] ?? '' ) );
        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $result     = array();

        foreach ( $taxonomies as $tax ) {
            $result[] = array(
                'slug'  => $tax->name,
                'label' => $tax->labels->singular_name ?: $tax->label,
            );
        }

        wp_send_json_success( $result );
    }

    public function ajax_get_terms() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $taxonomy = sanitize_text_field( wp_unslash( $_POST['taxonomy'] ?? '' ) );
        $terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
        $result   = array();

        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $result[] = array(
                    'slug' => $term->slug,
                    'name' => $term->name,
                );
            }
        }

        wp_send_json_success( $result );
    }

    public function ajax_save_rules() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $raw_rules = json_decode( wp_unslash( $_POST['rules'] ?? '[]' ), true );
        if ( ! is_array( $raw_rules ) ) {
            wp_send_json_error( 'Invalid rules data' );
        }

        $clean_rules = array();
        foreach ( $raw_rules as $rule ) {
            $clean = array(
                'id'         => sanitize_text_field( $rule['id'] ?? '' ),
                'post_type'  => sanitize_key( $rule['post_type'] ?? '' ),
                'match'      => in_array( $rule['match'] ?? 'any', array( 'all', 'any' ), true ) ? $rule['match'] : 'any',
                'template'   => sanitize_file_name( $rule['template'] ?? '' ),
                'conditions' => array(),
            );
            foreach ( ( $rule['conditions'] ?? array() ) as $cond ) {
                $clean['conditions'][] = array(
                    'taxonomy' => sanitize_key( $cond['taxonomy'] ?? '' ),
                    'term'     => sanitize_title_with_dashes( $cond['term'] ?? '' ),
                );
            }
            if ( $clean['post_type'] && $clean['template'] && ! empty( $clean['conditions'] ) ) {
                $clean_rules[] = $clean;
            }
        }

        update_option( 'etch_tc_rules', $clean_rules );

        // Increment the rule counter if a new rule was added.
        $next_num = (int) get_option( 'etch_tc_rule_counter', 1 );
        if ( ( $_POST['increment_counter'] ?? '' ) === '1' ) {
            $next_num++;
            update_option( 'etch_tc_rule_counter', $next_num );
        }

        wp_send_json_success( array( 'rules' => $clean_rules, 'nextRuleNum' => $next_num ) );
    }

    public function ajax_delete_rule() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $rule_id = sanitize_text_field( wp_unslash( $_POST['rule_id'] ?? '' ) );
        $rules   = get_option( 'etch_tc_rules', array() );
        $rules   = array_values( array_filter( $rules, function( $r ) use ( $rule_id ) {
            return ( $r['id'] ?? '' ) !== $rule_id;
        } ) );

        update_option( 'etch_tc_rules', $rules );
        wp_send_json_success( array( 'rules' => $rules ) );
    }

    public function ajax_reorder_rules() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $order = json_decode( wp_unslash( $_POST['order'] ?? '[]' ), true );
        if ( ! is_array( $order ) ) {
            wp_send_json_error( 'Invalid order data' );
        }

        $rules       = get_option( 'etch_tc_rules', array() );
        $rules_by_id = array();
        foreach ( $rules as $rule ) {
            $rules_by_id[ $rule['id'] ] = $rule;
        }

        $reordered = array();
        foreach ( $order as $id ) {
            $id = sanitize_text_field( $id );
            if ( isset( $rules_by_id[ $id ] ) ) {
                $reordered[] = $rules_by_id[ $id ];
            }
        }

        update_option( 'etch_tc_rules', $reordered );
        wp_send_json_success( array( 'rules' => $reordered ) );
    }

    public function ajax_toggle_etch_flag() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $slug    = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        $checked = ( $_POST['checked'] ?? '' ) === '1';
        $flags   = get_option( 'etch_tc_etch_flags', array() );

        if ( $checked ) {
            $flags[ $slug ] = true;
        } else {
            unset( $flags[ $slug ] );
        }

        update_option( 'etch_tc_etch_flags', $flags );
        wp_send_json_success();
    }

    public function ajax_save_template_name() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $names = get_option( 'etch_tc_template_names', array() );

        if ( $name !== '' ) {
            $names[ $slug ] = $name;
        } else {
            unset( $names[ $slug ] );
        }

        update_option( 'etch_tc_template_names', $names );
        wp_send_json_success();
    }

    public function ajax_toggle_status() {
        check_ajax_referer( 'etch_tc_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $slug = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
        $theme = $this->get_theme()->stylesheet;

        $posts = get_posts( array(
            'post_type'   => 'wp_template',
            'post_status' => array( 'publish', 'draft' ),
            'name'        => $slug,
            'tax_query'   => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => $theme,
                ),
            ),
            'posts_per_page' => 1,
        ) );

        if ( empty( $posts ) ) {
            wp_send_json_error( 'Template not found' );
        }

        $post       = $posts[0];
        $new_status = ( $post->post_status === 'publish' ) ? 'draft' : 'publish';
        wp_update_post( array(
            'ID'          => $post->ID,
            'post_status' => $new_status,
        ) );

        wp_send_json_success( array( 'new_status' => $new_status ) );
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function get_theme() {
        return wp_get_theme();
    }

    private function get_all_db_templates() {
        $theme = $this->get_theme()->stylesheet;
        $query = new WP_Query( array(
            'post_type'      => 'wp_template',
            'post_status'    => array( 'publish', 'auto-draft', 'draft' ),
            'posts_per_page' => 200,
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => $theme,
                ),
            ),
            'orderby'        => 'post_name',
            'order'          => 'ASC',
        ) );
        return $query->posts;
    }

    private function get_theme_file_templates() {
        $dir = $this->get_theme()->get_template_directory() . '/templates/';
        if ( ! is_dir( $dir ) ) {
            return array();
        }
        $files = glob( $dir . '*.html' );
        return array_map( function( $f ) {
            return basename( $f, '.html' );
        }, $files ?: array() );
    }

    private function find_template_post( $slug ) {
        $theme = $this->get_theme()->stylesheet;
        $query = new WP_Query( array(
            'post_name__in'  => array( $slug ),
            'post_type'      => 'wp_template',
            'post_status'    => array( 'publish', 'auto-draft', 'draft' ),
            'posts_per_page' => 1,
            'no_found_rows'  => true,
            'tax_query'      => array(
                array(
                    'taxonomy' => 'wp_theme',
                    'field'    => 'name',
                    'terms'    => $theme,
                ),
            ),
        ) );
        return ! empty( $query->posts ) ? $query->posts[0] : false;
    }

    private function plugin_file_exists( $slug ) {
        $path = plugin_dir_path( __FILE__ ) . 'templates/' . $slug . '.html';
        return file_exists( $path ) ? $path : false;
    }

    // ── URL builders ────────────────────────────────────────────────────

    private function get_site_editor_url( $slug, $type = 'wp_template' ) {
        $theme_slug = $this->get_theme()->stylesheet;
        return admin_url( sprintf(
            'site-editor.php?postType=%s&postId=%s&canvas=edit',
            $type,
            urlencode( $theme_slug . '//' . $slug )
        ) );
    }

    private function get_post_edit_url( $post_id ) {
        return admin_url( 'post.php?post=' . intval( $post_id ) . '&action=edit' );
    }

    // ── Classify templates ──────────────────────────────────────────────

    private function classify_template( $slug ) {
        $rules      = etch_tc_get_rules();
        $rule_slugs = wp_list_pluck( $rules, 'template' );

        if ( in_array( $slug, $rule_slugs, true ) ) {
            return 'condition';
        }
        if ( preg_match( '/^single-/', $slug ) ) {
            return 'single';
        }
        if ( preg_match( '/^archive-/', $slug ) ) {
            return 'archive';
        }
        if ( preg_match( '/^taxonomy-/', $slug ) ) {
            return 'taxonomy';
        }
        if ( preg_match( '/^category-/', $slug ) ) {
            return 'category';
        }
        if ( in_array( $slug, array( 'index', 'front-page', 'home', 'singular', 'search', '404', 'page', 'single', 'archive' ), true ) ) {
            return 'core';
        }
        return 'custom';
    }

    private function get_type_label( $type ) {
        $labels = array(
            'condition' => 'Condition Rule',
            'core'      => 'Core',
            'single'    => 'Single',
            'archive'   => 'Archive',
            'taxonomy'  => 'Taxonomy Archive',
            'category'  => 'Category Archive',
            'custom'    => 'Custom',
            'part'      => 'Template Part',
        );
        return $labels[ $type ] ?? $type;
    }

    private function get_type_badge_class( $type ) {
        $classes = array(
            'condition' => 'badge-condition',
            'core'      => 'badge-core',
            'single'    => 'badge-single',
            'archive'   => 'badge-archive',
            'taxonomy'  => 'badge-taxonomy',
            'category'  => 'badge-taxonomy',
            'custom'    => 'badge-custom',
            'part'      => 'badge-part',
        );
        return $classes[ $type ] ?? 'badge-custom';
    }

    // ── Render ───────────────────────────────────────────────────────────

    public function render_page() {
        $theme          = $this->get_theme();
        $etch_flags     = get_option( 'etch_tc_etch_flags', array() );
        $template_names = get_option( 'etch_tc_template_names', array() );
        $all_rules      = etch_tc_get_rules();
        $all_rules  = array_map( 'etch_tc_normalise_rule', $all_rules );
        $rule_slugs = wp_list_pluck( $all_rules, 'template' );
        $rule_map   = array();
        foreach ( $all_rules as $rule ) {
            $rule_map[ $rule['template'] ] = $rule;
        }

        $filter_rules = etch_tc_get_filter_rules();
        $filter_rules = array_map( 'etch_tc_normalise_rule', $filter_rules );

        // Gather all templates from DB + theme files.
        $db_templates      = $this->get_all_db_templates();

        $theme_file_slugs  = $this->get_theme_file_templates();


        // Build unified list of template slugs.
        $all_template_slugs = array();
        foreach ( $db_templates as $tpl ) {
            $all_template_slugs[ $tpl->post_name ] = $tpl;
        }
        foreach ( $theme_file_slugs as $slug ) {
            if ( ! isset( $all_template_slugs[ $slug ] ) ) {
                $all_template_slugs[ $slug ] = null;
            }
        }
        foreach ( $rule_slugs as $slug ) {
            if ( ! isset( $all_template_slugs[ $slug ] ) ) {
                $all_template_slugs[ $slug ] = null;
            }
        }
        ksort( $all_template_slugs );

        // Get available CPTs for the rule form.
        $cpt_types = get_post_types( array( 'public' => true ), 'objects' );
        $excluded_types = array( 'post', 'page', 'attachment' );

        ?>
        <div class="wrap etch-tc-wrap">

            <h1 class="etch-tc-title">Template Dashboard</h1>
            <p class="etch-tc-subtitle">
                This is your single source of truth — including templates that don't appear in Etch's Template Manager.
            </p>

            <?php
                // Split templates into non-Etch and Etch groups.
                $non_etch_templates = array();
                $etch_templates     = array();
                foreach ( $all_template_slugs as $slug => $db_post ) {
                    if ( ! empty( $etch_flags[ $slug ] ) ) {
                        $etch_templates[ $slug ] = $db_post;
                    } else {
                        $non_etch_templates[ $slug ] = $db_post;
                    }
                }
            ?>

            <!-- Tab Navigation -->
            <div class="etch-tc-tabs">
                <button class="etch-tc-tab active" data-tab="all-templates">
                    All Templates <span class="count"><?php echo count( $non_etch_templates ); ?></span>
                </button>
                <button class="etch-tc-tab" data-tab="condition-rules">
                    Condition Rules <span class="count"><?php echo count( $all_rules ); ?></span>
                </button>
                <button class="etch-tc-tab" data-tab="etch-templates">
                    Created in Etch <span class="count"><?php echo count( $etch_templates ); ?></span>
                </button>
                <button class="etch-tc-tab" data-tab="help">
                    Help & Setup
                </button>
            </div>

            <!-- Tab: All Templates (non-Etch) -->
            <div class="etch-tc-panel active" id="tab-all-templates">
                <table class="widefat striped etch-tc-table">
                    <thead>
                        <tr>
                            <th style="width: 28%;">Template</th>
                            <th style="width: 10%; text-align: center;">Type</th>
                            <th style="width: 8%;">Status</th>
                            <th style="width: 10%;">Source</th>
                            <th style="width: 5%; text-align: center;">Etch</th>
                            <th style="width: 14%;">Last Modified</th>
                            <th style="width: 25%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $non_etch_templates as $slug => $db_post ) :
                        $type       = $this->classify_template( $slug );
                        $has_db     = ! empty( $db_post );
                        $has_theme  = in_array( $slug, $theme_file_slugs, true );
                        $has_plugin = (bool) $this->plugin_file_exists( $slug );
                        $is_rule    = isset( $rule_map[ $slug ] );

                        if ( $has_db && $has_theme ) {
                            $source = 'DB + Theme';
                        } elseif ( $has_db ) {
                            $source = 'Database';
                        } elseif ( $has_theme ) {
                            $source = 'Theme file';
                        } elseif ( $has_plugin ) {
                            $source = 'Plugin fallback';
                        } else {
                            $source = '—';
                        }

                        if ( $has_db ) {
                            $status_class = ( $db_post->post_status === 'publish' ) ? 'status-publish' : 'status-draft';
                            $status_text  = ( $db_post->post_status === 'publish' ) ? 'Published' : 'Draft';
                        } elseif ( $has_theme || $has_plugin ) {
                            $status_class = 'status-file';
                            $status_text  = 'File only';
                        } else {
                            $status_class = 'status-missing';
                            $status_text  = 'Missing';
                        }

                        $modified = $has_db ? date( 'j M Y, H:i', strtotime( $db_post->post_modified ) ) : '—';
                        $custom_name = $template_names[ $slug ] ?? '';
                        $title = $custom_name !== '' ? $custom_name : ( $has_db && ! empty( $db_post->post_title ) ? $db_post->post_title : ucfirst( str_replace( array( '-', '_', '--' ), ' ', $slug ) ) );
                        $row_classes = array();
                        if ( $is_rule ) $row_classes[] = 'etch-tc-row-rule';
                    ?>
                        <tr class="<?php echo esc_attr( implode( ' ', $row_classes ) ); ?>">
                            <td>
                                <strong class="etch-tc-template-name etch-tc-editable-name" data-slug="<?php echo esc_attr( $slug ); ?>" title="Click to edit name"><?php echo esc_html( $title ); ?></strong>
                                <br><code class="etch-tc-slug"><?php echo esc_html( $slug ); ?></code>
                                <?php if ( $is_rule ) : ?>
                                    <br><small class="etch-tc-rule-info">
                                        &rarr; <?php echo esc_html( $this->describe_rule_inline( $rule_map[ $slug ] ) ); ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;"><span class="etch-tc-badge <?php echo esc_attr( $this->get_type_badge_class( $type ) ); ?>"><?php echo esc_html( $this->get_type_label( $type ) ); ?></span></td>
                            <td><span class="etch-tc-status <?php echo esc_attr( $status_class ); ?><?php echo $has_db ? ' etch-tc-status-toggle' : ''; ?>" <?php echo $has_db ? 'data-slug="' . esc_attr( $slug ) . '" title="Click to toggle status"' : ''; ?>><?php echo esc_html( $status_text ); ?></span></td>
                            <td><?php echo esc_html( $source ); ?></td>
                            <td style="text-align:center;">
                                <input type="checkbox" class="etch-tc-etch-flag" data-slug="<?php echo esc_attr( $slug ); ?>">
                            </td>
                            <td><?php echo esc_html( $modified ); ?></td>
                            <td class="etch-tc-actions">
                                <?php echo $this->render_action_buttons( $slug, $db_post, 'wp_template', $is_rule ? $rule_map[ $slug ] : null ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab: Created in Etch -->
            <div class="etch-tc-panel" id="tab-etch-templates">
                <p>Templates flagged as created in Etch. Uncheck the Etch checkbox to move a template back to the All Templates tab.</p>
                <table class="widefat striped etch-tc-table">
                    <thead>
                        <tr>
                            <th style="width: 30%;">Template</th>
                            <th style="width: 10%; text-align: center;">Type</th>
                            <th style="width: 8%;">Status</th>
                            <th style="width: 10%;">Source</th>
                            <th style="width: 5%; text-align: center;">Etch</th>
                            <th style="width: 14%;">Last Modified</th>
                            <th style="width: 23%; text-align: center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $etch_templates as $slug => $db_post ) :
                        $type       = $this->classify_template( $slug );
                        $has_db     = ! empty( $db_post );
                        $has_theme  = in_array( $slug, $theme_file_slugs, true );
                        $has_plugin = (bool) $this->plugin_file_exists( $slug );
                        $is_rule    = isset( $rule_map[ $slug ] );

                        if ( $has_db && $has_theme ) {
                            $source = 'DB + Theme';
                        } elseif ( $has_db ) {
                            $source = 'Database';
                        } elseif ( $has_theme ) {
                            $source = 'Theme file';
                        } elseif ( $has_plugin ) {
                            $source = 'Plugin fallback';
                        } else {
                            $source = '—';
                        }

                        if ( $has_db ) {
                            $status_class = ( $db_post->post_status === 'publish' ) ? 'status-publish' : 'status-draft';
                            $status_text  = ( $db_post->post_status === 'publish' ) ? 'Published' : 'Draft';
                        } elseif ( $has_theme || $has_plugin ) {
                            $status_class = 'status-file';
                            $status_text  = 'File only';
                        } else {
                            $status_class = 'status-missing';
                            $status_text  = 'Missing';
                        }

                        $modified = $has_db ? date( 'j M Y, H:i', strtotime( $db_post->post_modified ) ) : '—';
                        $custom_name = $template_names[ $slug ] ?? '';
                        $title = $custom_name !== '' ? $custom_name : ( $has_db && ! empty( $db_post->post_title ) ? $db_post->post_title : ucfirst( str_replace( array( '-', '_', '--' ), ' ', $slug ) ) );
                    ?>
                        <tr>
                            <td>
                                <strong class="etch-tc-template-name etch-tc-editable-name" data-slug="<?php echo esc_attr( $slug ); ?>" title="Click to edit name"><?php echo esc_html( $title ); ?></strong>
                                <br><code class="etch-tc-slug"><?php echo esc_html( $slug ); ?></code>
                            </td>
                            <td style="text-align: center;"><span class="etch-tc-badge <?php echo esc_attr( $this->get_type_badge_class( $type ) ); ?>"><?php echo esc_html( $this->get_type_label( $type ) ); ?></span></td>
                            <td><span class="etch-tc-status <?php echo esc_attr( $status_class ); ?><?php echo $has_db ? ' etch-tc-status-toggle' : ''; ?>" <?php echo $has_db ? 'data-slug="' . esc_attr( $slug ) . '" title="Click to toggle status"' : ''; ?>><?php echo esc_html( $status_text ); ?></span></td>
                            <td><?php echo esc_html( $source ); ?></td>
                            <td style="text-align:center;">
                                <input type="checkbox" class="etch-tc-etch-flag" data-slug="<?php echo esc_attr( $slug ); ?>" checked>
                            </td>
                            <td><?php echo esc_html( $modified ); ?></td>
                            <td class="etch-tc-actions">
                                <?php echo $this->render_action_buttons( $slug, $db_post, 'wp_template', $is_rule ? $rule_map[ $slug ] : null ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab: Condition Rules -->
            <div class="etch-tc-panel" id="tab-condition-rules">
                <p>Rules are evaluated top-to-bottom — <strong>the first matching rule wins</strong>.
                Order only matters if a post could match multiple rules (e.g. it has terms from more than one rule).
                Drag to reorder. Database rules are checked before any programmatic (filter) rules.</p>

                <button class="button button-primary" id="etch-tc-add-rule">+ Add Rule</button>

                <!-- Inline Add/Edit Form -->
                <div id="etch-tc-rule-form" class="etch-tc-rule-form" style="display:none;">
                    <h3 id="etch-tc-form-title">Add New Rule</h3>
                    <input type="hidden" id="etch-tc-editing-id" value="">

                    <div class="etch-tc-form-row">
                        <label for="etch-tc-post-type">Post Type</label>
                        <select id="etch-tc-post-type">
                            <option value="">-- Select --</option>
                            <?php foreach ( $cpt_types as $pt ) :
                                if ( in_array( $pt->name, $excluded_types, true ) ) continue;
                            ?>
                                <option value="<?php echo esc_attr( $pt->name ); ?>"><?php echo esc_html( $pt->labels->singular_name ); ?> (<?php echo esc_html( $pt->name ); ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="etch-tc-form-row">
                        <label>Conditions</label>
                        <div id="etch-tc-conditions-list"></div>
                        <button class="button" id="etch-tc-add-condition" style="margin-top:4px;">+ Add Condition</button>
                    </div>

                    <div class="etch-tc-form-row" id="etch-tc-match-row" style="display:none;">
                        <label for="etch-tc-match-mode">Match Mode</label>
                        <select id="etch-tc-match-mode">
                            <option value="any">ANY condition matches (OR)</option>
                            <option value="all">ALL conditions must match (AND)</option>
                        </select>
                    </div>

                    <div class="etch-tc-form-row" id="etch-tc-slug-row">
                        <label for="etch-tc-template-slug">Template Slug</label>
                        <input type="text" id="etch-tc-template-slug" placeholder="Auto-generated, e.g. single-uk-venues-rule-1">
                        <p class="description" id="etch-tc-slug-suggestion"></p>
                    </div>

                    <div class="etch-tc-form-actions">
                        <button class="button button-primary" id="etch-tc-save-rule">Save Rule</button>
                        <button class="button" id="etch-tc-cancel-rule">Cancel</button>
                    </div>
                </div>

                <!-- Rules Table (DB rules — rendered by JS) -->
                <table class="widefat striped etch-tc-table" id="etch-tc-rules-table">
                    <thead>
                        <tr>
                            <th style="width:4%;"></th>
                            <th style="width:5%;">#</th>
                            <th style="width:12%;">Post Type</th>
                            <th style="width:28%;">Conditions</th>
                            <th style="width:8%;">Logic</th>
                            <th style="width:18%;">Template</th>
                            <th style="width:8%;">Status</th>
                            <th style="width:17%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="etch-tc-rules-body">
                        <!-- Rendered by JavaScript -->
                    </tbody>
                </table>
                <p id="etch-tc-empty-state" style="display:none; color:#787c82; padding:1em 0;">
                    No rules configured yet. Click <strong>+ Add Rule</strong> to create your first template condition.
                </p>

                <?php if ( ! empty( $filter_rules ) ) : ?>
                <div class="etch-tc-filter-rules-section">
                    <h3>Programmatic Rules (read-only)</h3>
                    <p>These rules are added via the <code>etch_tc_rules</code> filter in your theme or another plugin. They are checked after database rules.</p>
                    <table class="widefat striped etch-tc-table">
                        <thead>
                            <tr>
                                <th style="width:12%;">Post Type</th>
                                <th style="width:34%;">Conditions</th>
                                <th style="width:10%;">Logic</th>
                                <th style="width:22%;">Template</th>
                                <th style="width:22%;">Source</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ( $filter_rules as $rule ) :
                            $rule    = etch_tc_normalise_rule( $rule );
                            $is_and  = $rule['match'] === 'all';
                            $is_multi = count( $rule['conditions'] ) > 1;
                        ?>
                            <tr>
                                <td><code><?php echo esc_html( $rule['post_type'] ); ?></code></td>
                                <td>
                                    <?php foreach ( $rule['conditions'] as $ci => $cond ) : ?>
                                        <span class="etch-tc-condition-pill">
                                            <code><?php echo esc_html( $cond['taxonomy'] ); ?></code>
                                            =
                                            <code><?php echo esc_html( $cond['term'] ); ?></code>
                                        </span>
                                        <?php if ( $ci < count( $rule['conditions'] ) - 1 ) : ?>
                                            <span class="etch-tc-logic-label"><?php echo $is_and ? 'AND' : 'OR'; ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if ( $is_multi ) : ?>
                                        <span class="etch-tc-badge <?php echo $is_and ? 'badge-and' : 'badge-or'; ?>">
                                            <?php echo $is_and ? 'ALL (AND)' : 'ANY (OR)'; ?>
                                        </span>
                                    <?php else : ?>
                                        <span class="etch-tc-badge badge-core">Single</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?php echo esc_html( $rule['template'] ); ?></code></td>
                                <td><em>etch_tc_rules filter</em></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <!-- Tab: Help -->
            <div class="etch-tc-panel" id="tab-help">
                <div class="etch-tc-help-grid">

                    <div class="etch-tc-help-card">
                        <h3>Adding Rules via the UI</h3>
                        <ol>
                            <li>Go to the <strong>Condition Rules</strong> tab</li>
                            <li>Click <strong>+ Add Rule</strong></li>
                            <li>Select the <strong>Post Type</strong> (e.g. your CPT)</li>
                            <li>Choose a <strong>Taxonomy</strong> and <strong>Term</strong> to match</li>
                            <li>Optionally add more conditions and set AND/OR logic</li>
                            <li>Set the <strong>Template Slug</strong> (auto-suggested, or customise)</li>
                            <li>Click <strong>Save Rule</strong></li>
                        </ol>
                        <p>Rules are checked top-to-bottom — the first matching rule wins. Order only matters if a post could match multiple rules (e.g. it has terms from more than one rule). Drag to reorder if needed.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>Managing Templates in the Table</h3>
                        <p><strong>Rename:</strong> Click any template name in the All Templates or Created in Etch tabs to give it a descriptive label. This is purely visual — it does not affect the slug or template matching.</p>
                        <p><strong>Status:</strong> Click the <strong>Publish</strong> or <strong>Draft</strong> status label to toggle a template between published and draft. Draft templates will not be used on the frontend, so this is a quick way to disable a template without deleting it.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>Creating the Template</h3>
                        <p>After adding a rule, you need to create the actual template. The template <strong>must</strong> use the exact slug shown in your rule (e.g. <code>single-uk-venues-rule-1</code>) as its name — this is how the plugin matches rules to templates.</p>
                        <p><strong>Method 1 — From the All Templates tab (easiest):</strong></p>
                        <ol>
                            <li>Find the rule's template row (status will show <strong>Missing</strong>)</li>
                            <li>Click <strong>Create in Site Editor</strong> — the slug is automatically copied to your clipboard</li>
                            <li>In the Site Editor, add a new <strong>Custom Template</strong></li>
                            <li>Paste the slug into the template name field</li>
                            <li>Design it in Etch, then save</li>
                        </ol>
                        <p><strong>Method 2 — From a post:</strong></p>
                        <ol>
                            <li>Edit any post that has the target term assigned</li>
                            <li>In the Gutenberg sidebar, click the <strong>Template</strong> panel</li>
                            <li>Click <strong>"Add template"</strong></li>
                            <li>Choose <strong>Custom Template</strong> and enter the exact slug from your rule</li>
                            <li>Design it in Etch, then save</li>
                        </ol>
                        <p><strong>Tip:</strong> Template names in the All Templates table are editable — click any name to add a descriptive label for your own reference. This is purely visual and does not affect the slug or template matching.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>About the Action Buttons</h3>
                        <ul>
                            <li><strong>Site Editor</strong> — Opens the template in WordPress's Site Editor where you can edit it with Etch.</li>
                            <li><strong>Create in Site Editor</strong> — Shown for templates that don't exist yet. Copies the template slug to your clipboard and opens the Site Editor so you can create it.</li>
                        </ul>
                        <p>Templates created outside of Etch's Template Manager <strong>won't appear there</strong> — that's expected. This dashboard is your way to find and access them.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>The Etch Checkbox</h3>
                        <p>The <strong>Etch</strong> checkbox lets you flag templates that were created natively in Etch's Template Manager. Checking it moves the template out of the All Templates tab and into the <strong>Created in Etch</strong> tab, keeping your main table focused on condition-based and custom templates.</p>
                        <p>This is a manual flag — the plugin cannot auto-detect which templates were created in Etch. Uncheck it at any time to move a template back to the All Templates tab.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>Advanced: Adding Rules via Code</h3>
                        <p>For developers, rules can also be added via the <code>etch_tc_rules</code> filter in your theme's <code>functions.php</code> or a snippet plugin:</p>
<pre>add_filter( 'etch_tc_rules', function( $rules ) {
    $rules[] = array(
        'post_type' => 'property',
        'taxonomy'  => 'property-type',
        'term'      => 'villa',
        'template'  => 'single-property--villa',
    );
    return $rules;
} );</pre>
                        <p>Programmatic rules appear in the Condition Rules tab as read-only entries. Database rules (added via the UI) are always checked first.</p>
                    </div>

                    <div class="etch-tc-help-card">
                        <h3>Important Notes</h3>
                        <ul>
                            <li>Rule template slugs follow the format <code>single-{post-type}-rule-{N}</code> (e.g. <code>single-holiday-rule-1</code>). The <code>-rule-</code> suffix distinguishes them from WordPress's native template hierarchy.</li>
                            <li>The plugin hooks into <code>get_block_templates</code> at priority 20, so Etch (which typically runs at priority 10) processes first.</li>
                            <li>If caching plugins are active, purge the cache after changing a post's taxonomy term.</li>
                            <li>Template swapping only applies to <strong>frontend singular views</strong> — the admin/editor always uses the default template.</li>
                        </ul>
                    </div>

                </div>
            </div>

        </div>

        <script>
        jQuery(document).ready(function($) {
            // ── Tab switching ──
            var tabs = document.querySelectorAll('.etch-tc-tab');
            var panels = document.querySelectorAll('.etch-tc-panel');

            function activateTab(tabName) {
                tabs.forEach(function(t) { t.classList.remove('active'); });
                panels.forEach(function(p) { p.classList.remove('active'); });
                var target = document.querySelector('.etch-tc-tab[data-tab="' + tabName + '"]');
                if (target) {
                    target.classList.add('active');
                    document.getElementById('tab-' + tabName).classList.add('active');
                }
            }

            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    activateTab(tab.dataset.tab);
                    window.location.hash = tab.dataset.tab;
                });
            });

            // Restore active tab from URL hash on page load.
            if (window.location.hash) {
                var hashTab = window.location.hash.substring(1);
                if (document.querySelector('.etch-tc-tab[data-tab="' + hashTab + '"]')) {
                    activateTab(hashTab);
                }
            }

            // ── State ──
            var state = {
                rules: (typeof etchTC !== 'undefined' && etchTC.rules) ? etchTC.rules : [],
                editing: null,
                autoSlug: '',
                nextRuleNum: (typeof etchTC !== 'undefined' && etchTC.nextRuleNum) ? etchTC.nextRuleNum : 1
            };

            // ── Render rules table ──
            function renderRulesTable() {
                var $tbody = $('#etch-tc-rules-body');
                $tbody.empty();

                if (state.rules.length === 0) {
                    $('#etch-tc-rules-table').hide();
                    $('#etch-tc-empty-state').show();
                    return;
                }

                $('#etch-tc-rules-table').show();
                $('#etch-tc-empty-state').hide();

                state.rules.forEach(function(rule, index) {
                    var condHtml = '';
                    var isAnd = rule.match === 'all';
                    var isMulti = rule.conditions.length > 1;

                    rule.conditions.forEach(function(cond, ci) {
                        condHtml += '<span class="etch-tc-condition-pill">' +
                            '<code>' + escHtml(cond.taxonomy) + '</code> = <code>' + escHtml(cond.term) + '</code>' +
                            '</span>';
                        if (ci < rule.conditions.length - 1) {
                            condHtml += ' <span class="etch-tc-logic-label">' + (isAnd ? 'AND' : 'OR') + '</span> ';
                        }
                    });

                    var logicHtml = '';
                    if (isMulti) {
                        logicHtml = '<span class="etch-tc-badge ' + (isAnd ? 'badge-and' : 'badge-or') + '">' +
                            (isAnd ? 'ALL (AND)' : 'ANY (OR)') + '</span>';
                    } else {
                        logicHtml = '<span class="etch-tc-badge badge-core">Single</span>';
                    }

                    // Template status — we check against the localized template status data
                    var statusHtml = '<span class="etch-tc-status status-draft">--</span>';

                    var $tr = $('<tr data-rule-id="' + escAttr(rule.id) + '">' +
                        '<td class="etch-tc-drag-handle" title="Drag to reorder">&#x2630;</td>' +
                        '<td class="etch-tc-priority">' + (index + 1) + '</td>' +
                        '<td><code>' + escHtml(rule.post_type) + '</code></td>' +
                        '<td>' + condHtml + '</td>' +
                        '<td>' + logicHtml + '</td>' +
                        '<td><code>' + escHtml(rule.template) + '</code></td>' +
                        '<td>' + statusHtml + '</td>' +
                        '<td class="etch-tc-actions">' +
                            '<button class="button etch-tc-btn etch-tc-rule-edit" data-id="' + escAttr(rule.id) + '">Edit</button> ' +
                            '<button class="button etch-tc-btn etch-tc-rule-delete" data-id="' + escAttr(rule.id) + '">Delete</button>' +
                        '</td>' +
                        '</tr>');

                    $tbody.append($tr);
                });

                initSortable();
            }

            // ── Sortable ──
            function initSortable() {
                $('#etch-tc-rules-body').sortable({
                    handle: '.etch-tc-drag-handle',
                    axis: 'y',
                    placeholder: 'etch-tc-sortable-placeholder',
                    update: function() {
                        var order = [];
                        $('#etch-tc-rules-body tr').each(function() {
                            order.push($(this).data('rule-id'));
                        });

                        // Update local state order.
                        var rulesById = {};
                        state.rules.forEach(function(r) { rulesById[r.id] = r; });
                        state.rules = order.map(function(id) { return rulesById[id]; }).filter(Boolean);

                        // Update priority numbers.
                        $('#etch-tc-rules-body tr').each(function(i) {
                            $(this).find('.etch-tc-priority').text(i + 1);
                        });

                        // Save to server.
                        $.post(etchTC.ajaxUrl, {
                            action: 'etch_tc_reorder_rules',
                            nonce: etchTC.nonce,
                            order: JSON.stringify(order)
                        }).fail(handleAjaxError);
                    }
                });
            }

            // ── Form: Open ──
            function openForm(ruleId) {
                var $form = $('#etch-tc-rule-form');
                resetForm();

                if (ruleId) {
                    var rule = state.rules.find(function(r) { return r.id === ruleId; });
                    if (!rule) return;

                    state.editing = ruleId;
                    $('#etch-tc-form-title').text('Edit Rule');
                    $('#etch-tc-editing-id').val(ruleId);
                    $('#etch-tc-post-type').val(rule.post_type);
                    $('#etch-tc-match-mode').val(rule.match || 'any');
                    $('#etch-tc-template-slug').val(rule.template).prop('readonly', true);
                    $('#etch-tc-slug-suggestion').html('<small style="color:#787c82;">Template slug is locked after creation to preserve the link to the template.</small>');

                    // Load taxonomies for this post type, then populate conditions.
                    loadTaxonomies(rule.post_type, function() {
                        rule.conditions.forEach(function(cond, i) {
                            addConditionRow(cond.taxonomy, cond.term);
                        });
                        updateMatchVisibility();
                    });
                } else {
                    state.editing = null;
                    $('#etch-tc-form-title').text('Add New Rule');
                    $('#etch-tc-editing-id').val('');
                    $('#etch-tc-template-slug').prop('readonly', false);
                }

                $form.slideDown(200);
                $('html, body').animate({ scrollTop: $form.offset().top - 50 }, 200);
            }

            // ── Form: Close ──
            function closeForm() {
                $('#etch-tc-rule-form').slideUp(200);
                resetForm();
            }

            function resetForm() {
                state.editing = null;
                state.autoSlug = '';
                $('#etch-tc-editing-id').val('');
                $('#etch-tc-post-type').val('');
                $('#etch-tc-conditions-list').empty();
                $('#etch-tc-match-mode').val('any');
                $('#etch-tc-match-row').hide();
                $('#etch-tc-template-slug').val('').prop('readonly', false);
                $('#etch-tc-slug-suggestion').text('');
            }

            // ── Taxonomy/Term AJAX ──
            var cachedTaxonomies = {};
            var cachedTerms = {};

            function loadTaxonomies(postType, callback) {
                if (cachedTaxonomies[postType]) {
                    if (callback) callback(cachedTaxonomies[postType]);
                    return;
                }
                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_get_taxonomies',
                    nonce: etchTC.nonce,
                    post_type: postType
                }, function(response) {
                    if (response.success) {
                        cachedTaxonomies[postType] = response.data;
                        if (callback) callback(response.data);
                    }
                }).fail(handleAjaxError);
            }

            function loadTerms(taxonomy, callback) {
                if (cachedTerms[taxonomy]) {
                    if (callback) callback(cachedTerms[taxonomy]);
                    return;
                }
                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_get_terms',
                    nonce: etchTC.nonce,
                    taxonomy: taxonomy
                }, function(response) {
                    if (response.success) {
                        cachedTerms[taxonomy] = response.data;
                        if (callback) callback(response.data);
                    }
                }).fail(handleAjaxError);
            }

            // ── Condition rows ──
            function addConditionRow(selectedTaxonomy, selectedTerm) {
                var postType = $('#etch-tc-post-type').val();
                var $row = $('<div class="etch-tc-condition-row">' +
                    '<select class="etch-tc-tax-select"><option value="">-- Taxonomy --</option></select>' +
                    '<select class="etch-tc-term-select"><option value="">-- Term --</option></select>' +
                    '<button type="button" class="etch-tc-remove-condition" title="Remove condition">&times;</button>' +
                    '</div>');

                $('#etch-tc-conditions-list').append($row);

                // Populate taxonomy dropdown.
                var taxes = cachedTaxonomies[postType] || [];
                var $taxSelect = $row.find('.etch-tc-tax-select');
                taxes.forEach(function(tax) {
                    $taxSelect.append('<option value="' + escAttr(tax.slug) + '">' + escHtml(tax.label) + ' (' + escHtml(tax.slug) + ')</option>');
                });

                if (selectedTaxonomy) {
                    $taxSelect.val(selectedTaxonomy);
                    // Load terms for this taxonomy.
                    loadTerms(selectedTaxonomy, function(terms) {
                        var $termSelect = $row.find('.etch-tc-term-select');
                        $termSelect.empty().append('<option value="">-- Term --</option>');
                        terms.forEach(function(term) {
                            $termSelect.append('<option value="' + escAttr(term.slug) + '">' + escHtml(term.name) + ' (' + escHtml(term.slug) + ')</option>');
                        });
                        if (selectedTerm) {
                            $termSelect.val(selectedTerm);
                        }
                    });
                }

                updateMatchVisibility();
            }

            function updateMatchVisibility() {
                var count = $('#etch-tc-conditions-list .etch-tc-condition-row').length;
                if (count >= 2) {
                    $('#etch-tc-match-row').show();
                } else {
                    $('#etch-tc-match-row').hide();
                }
            }

            // ── Auto-suggest slug ──
            function suggestSlug() {
                // Only auto-generate for new rules, not edits (slug is locked on edit).
                if (state.editing) return;

                var postType = $('#etch-tc-post-type').val();
                if (!postType) return;

                var suggested = 'single-' + postType + '-rule-' + state.nextRuleNum;
                $('#etch-tc-template-slug').val(suggested);
                state.autoSlug = suggested;
                $('#etch-tc-slug-suggestion').text('Will be auto-generated as: ' + suggested);
            }

            // ── Save rule ──
            function saveRule() {
                var postType = $('#etch-tc-post-type').val();
                var matchMode = $('#etch-tc-match-mode').val();
                var templateSlug = $('#etch-tc-template-slug').val().trim();

                if (!postType) { alert('Please select a Post Type.'); return; }
                if (!templateSlug) { alert('Please enter a Template Slug.'); return; }

                var conditions = [];
                $('#etch-tc-conditions-list .etch-tc-condition-row').each(function() {
                    var tax = $(this).find('.etch-tc-tax-select').val();
                    var term = $(this).find('.etch-tc-term-select').val();
                    if (tax && term) {
                        conditions.push({ taxonomy: tax, term: term });
                    }
                });

                if (conditions.length === 0) {
                    alert('Please add at least one condition with a taxonomy and term selected.');
                    return;
                }

                // Check for duplicate rules (same post type + same conditions).
                var condKey = function(r) {
                    var pairs = (r.conditions || []).map(function(c) {
                        return c.taxonomy + ':' + c.term;
                    }).sort();
                    return r.post_type + '|' + pairs.join(',');
                };
                var newKey = condKey({ post_type: postType, conditions: conditions });
                var duplicate = state.rules.find(function(r) {
                    return condKey(r) === newKey && r.id !== state.editing;
                });
                if (duplicate) {
                    alert('A rule with the same post type and conditions already exists (' + duplicate.id + '). Please edit the existing rule instead.');
                    return;
                }

                var isNew = !state.editing;
                var ruleId = state.editing || ('rule-' + state.nextRuleNum);

                var rule = {
                    id: ruleId,
                    post_type: postType,
                    conditions: conditions,
                    match: conditions.length > 1 ? matchMode : 'any',
                    template: templateSlug
                };

                // Update or add.
                if (state.editing) {
                    var idx = state.rules.findIndex(function(r) { return r.id === state.editing; });
                    if (idx !== -1) {
                        state.rules[idx] = rule;
                    }
                } else {
                    state.rules.push(rule);
                }

                // Save all rules to server.
                var $saveBtn = $('#etch-tc-save-rule');
                $saveBtn.prop('disabled', true).text('Saving...');

                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_save_rules',
                    nonce: etchTC.nonce,
                    rules: JSON.stringify(state.rules),
                    increment_counter: isNew ? '1' : '0'
                }, function(response) {
                    if (response.success) {
                        window.location.hash = 'condition-rules';
                        location.reload();
                    } else {
                        $saveBtn.prop('disabled', false).text('Save Rule');
                        alert('Error saving rules: ' + (response.data || 'Unknown error'));
                    }
                }).fail(function() {
                    $saveBtn.prop('disabled', false).text('Save Rule');
                    handleAjaxError();
                });
            }

            // ── Delete rule ──
            function deleteRule(ruleId) {
                if (!confirm('Delete this rule? This cannot be undone.')) return;

                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_delete_rule',
                    nonce: etchTC.nonce,
                    rule_id: ruleId
                }, function(response) {
                    if (response.success) {
                        window.location.hash = 'condition-rules';
                        location.reload();
                    }
                }).fail(handleAjaxError);
            }

            // ── Error handler ──
            function handleAjaxError(xhr) {
                if (xhr && xhr.status === 403) {
                    alert('Session expired. Please refresh the page and try again.');
                } else {
                    alert('An error occurred. Please try again.');
                }
            }

            // ── Utility ──
            function escHtml(str) {
                var div = document.createElement('div');
                div.appendChild(document.createTextNode(str || ''));
                return div.innerHTML;
            }

            function escAttr(str) {
                return (str || '').replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            }

            // ── Event Bindings ──

            // Add Rule button.
            $('#etch-tc-add-rule').on('click', function() {
                openForm(null);
            });

            // Cancel button.
            $('#etch-tc-cancel-rule').on('click', function() {
                closeForm();
            });

            // Save button.
            $('#etch-tc-save-rule').on('click', function() {
                saveRule();
            });

            // Post Type change — load taxonomies, add first condition row, auto-generate slug.
            $('#etch-tc-post-type').on('change', function() {
                var postType = $(this).val();
                $('#etch-tc-conditions-list').empty();
                updateMatchVisibility();
                state.autoSlug = '';
                $('#etch-tc-template-slug').val('');
                $('#etch-tc-slug-suggestion').text('');

                if (!postType) return;

                suggestSlug();
                loadTaxonomies(postType, function() {
                    addConditionRow();
                });
            });

            // Add Condition button.
            $('#etch-tc-add-condition').on('click', function() {
                if (!$('#etch-tc-post-type').val()) {
                    alert('Please select a Post Type first.');
                    return;
                }
                addConditionRow();
            });

            // Taxonomy dropdown change (delegated).
            $('#etch-tc-conditions-list').on('change', '.etch-tc-tax-select', function() {
                var $row = $(this).closest('.etch-tc-condition-row');
                var taxonomy = $(this).val();
                var $termSelect = $row.find('.etch-tc-term-select');

                $termSelect.empty().append('<option value="">-- Term --</option>');

                if (!taxonomy) return;

                loadTerms(taxonomy, function(terms) {
                    terms.forEach(function(term) {
                        $termSelect.append('<option value="' + escAttr(term.slug) + '">' + escHtml(term.name) + ' (' + escHtml(term.slug) + ')</option>');
                    });
                });
            });

            // Term dropdown change (delegated).
            $('#etch-tc-conditions-list').on('change', '.etch-tc-term-select', function() {
                suggestSlug();
            });

            // Remove condition (delegated).
            $('#etch-tc-conditions-list').on('click', '.etch-tc-remove-condition', function() {
                $(this).closest('.etch-tc-condition-row').remove();
                updateMatchVisibility();
                suggestSlug();
            });

            // Edit button (delegated on table).
            $('#etch-tc-rules-body').on('click', '.etch-tc-rule-edit', function() {
                openForm($(this).data('id'));
            });

            // Delete button (delegated on table).
            $('#etch-tc-rules-body').on('click', '.etch-tc-rule-delete', function() {
                deleteRule($(this).data('id'));
            });

            // ── Etch flag checkboxes ──
            $(document).on('change', '.etch-tc-etch-flag', function() {
                var $cb = $(this);
                $cb.prop('disabled', true);
                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_toggle_etch_flag',
                    nonce: etchTC.nonce,
                    slug: $cb.data('slug'),
                    checked: $cb.is(':checked') ? '1' : '0'
                }).done(function() {
                    location.reload();
                }).fail(handleAjaxError);
            });

            // ── Editable template names ──
            $(document).on('click', '.etch-tc-editable-name', function() {
                var $el = $(this);
                if ($el.find('input').length) return;
                var current = $el.text().trim();
                var slug = $el.data('slug');
                var $input = $('<input type="text" class="etch-tc-name-input">').val(current);
                $el.empty().append($input);
                $input.focus().select();

                function saveName() {
                    var newName = $input.val().trim();
                    $el.text(newName || current);
                    if (newName !== current) {
                        $.post(etchTC.ajaxUrl, {
                            action: 'etch_tc_save_template_name',
                            nonce: etchTC.nonce,
                            slug: slug,
                            name: newName
                        }).fail(handleAjaxError);
                    }
                }

                $input.on('blur', saveName);
                $input.on('keydown', function(e) {
                    if (e.key === 'Enter') { e.preventDefault(); $input.blur(); }
                    if (e.key === 'Escape') { $el.text(current); }
                });
            });

            // ── Toggle template status ──
            $(document).on('click', '.etch-tc-status-toggle', function() {
                var $el = $(this);
                var slug = $el.data('slug');
                $el.css('opacity', '0.4');
                $.post(etchTC.ajaxUrl, {
                    action: 'etch_tc_toggle_status',
                    nonce: etchTC.nonce,
                    slug: slug
                }).done(function(resp) {
                    if (resp.success) {
                        var s = resp.data.new_status;
                        var label = (s === 'publish') ? 'Published' : 'Draft';
                        $el.text(label)
                           .removeClass('status-publish status-draft')
                           .addClass(s === 'publish' ? 'status-publish' : 'status-draft')
                           .css('opacity', '');
                    }
                }).fail(function() {
                    $el.css('opacity', '');
                    handleAjaxError.apply(this, arguments);
                });
            });

            // ── Copy slug on Create in Site Editor ──
            $(document).on('click', '.etch-tc-copy-slug', function() {
                var slug = $(this).data('slug');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(slug);
                }
            });

            // ── Init ──
            renderRulesTable();

        });
        </script>
        <?php
    }

    /**
     * Render action buttons for a template row.
     */
    private function render_action_buttons( $slug, $db_post, $post_type = 'wp_template', $rule = null ) {
        $buttons = '';
        $has_db  = ! empty( $db_post );

        if ( $has_db ) {
            // Primary: Site Editor (required for wp_template posts).
            $se_url = $this->get_site_editor_url( $slug, $post_type );
            $buttons .= sprintf(
                '<a href="%s" class="button button-primary etch-tc-btn" title="Opens the Site Editor — click Edit in Etch from there">Site Editor</a> ',
                esc_url( $se_url )
            );
        } else {
            $se_url = admin_url( 'site-editor.php?postType=' . $post_type );
            $buttons .= sprintf(
                '<a href="%s" class="button etch-tc-btn etch-tc-copy-slug" data-slug="%s" title="Copies slug to clipboard, then opens Site Editor">Create in Site Editor</a>',
                esc_url( $se_url ),
                esc_attr( $slug )
            );

            if ( $rule ) {
                $first_cond = $rule['conditions'][0] ?? array();
                $buttons .= sprintf(
                    '<br><small class="etch-tc-create-hint">Or edit any <code>%s</code> post with term "<code>%s</code>" &rarr; Sidebar &rarr; Template &rarr; Add new</small>',
                    esc_html( $rule['post_type'] ),
                    esc_html( $first_cond['term'] ?? '' )
                );
            }
        }

        return $buttons;
    }

    private function describe_rule_inline( $rule ) {
        $parts = array();
        $glue  = ( $rule['match'] === 'all' ) ? ' AND ' : ' OR ';
        foreach ( $rule['conditions'] as $cond ) {
            $parts[] = $cond['taxonomy'] . ' = ' . $cond['term'];
        }
        return implode( $glue, $parts );
    }

    /**
     * Inline CSS for the admin page.
     */
    private function get_admin_css() {
        return '
        .etch-tc-wrap { max-width: 100%; }
        .etch-tc-title { font-size: 1.6em; margin-bottom: 0.2em; }
        .etch-tc-subtitle { color: #50575e; font-size: 14px; margin-bottom: 1.5em; }

        /* Tabs */
        .etch-tc-tabs {
            display: flex; gap: 0; border-bottom: 2px solid #c3c4c7;
            margin-bottom: 0;
        }
        .etch-tc-tab {
            padding: 10px 18px; background: #f0f0f1; border: 1px solid #c3c4c7;
            border-bottom: none; cursor: pointer; font-size: 13px; font-weight: 500;
            margin-right: -1px; border-radius: 4px 4px 0 0; color: #50575e;
        }
        .etch-tc-tab:hover { background: #e0e0e0; }
        .etch-tc-tab.active {
            background: #fff; color: #1d2327; border-bottom: 2px solid #fff;
            margin-bottom: -2px; font-weight: 600;
        }
        .etch-tc-tab .count {
            background: #dcdcde; border-radius: 10px; padding: 1px 8px;
            font-size: 11px; margin-left: 4px;
        }
        .etch-tc-tab.active .count { background: #2271b1; color: #fff; }

        /* Panels */
        .etch-tc-panel {
            display: none; background: #fff; border: 1px solid #c3c4c7;
            border-top: none; padding: 1.5em; border-radius: 0 0 4px 4px;
        }
        .etch-tc-panel.active { display: block; }

        /* Table */
        .etch-tc-table { border: none; margin-top: 1em; }
        .etch-tc-table th { font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.03em; color: #50575e; vertical-align: middle; }
        .etch-tc-table td { vertical-align: middle; }
        .etch-tc-template-name { font-size: 13px; }
        .etch-tc-slug { font-size: 11px; color: #787c82; }
        .etch-tc-rule-info { color: #2271b1; }
        .etch-tc-row-rule { background: #f0f6fc !important; }
        .etch-tc-etch-flag { display: block; margin: 0 auto; }
        .etch-tc-editable-name { cursor: pointer; border-bottom: 1px dashed #c3c4c7; }
        .etch-tc-editable-name:hover { border-bottom-color: #2271b1; color: #2271b1; }
        .etch-tc-name-input { font-size: 13px; font-weight: 600; width: 90%; padding: 2px 4px; }

        /* Badges */
        .etch-tc-badge {
            display: inline-block; padding: 2px 8px; border-radius: 3px;
            font-size: 11px; font-weight: 500; white-space: nowrap;
        }
        .badge-condition { background: #f0f6fc; color: #2271b1; border: 1px solid #2271b1; }
        .badge-core { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }
        .badge-single { background: #fef8ee; color: #9a6700; border: 1px solid #dba617; }
        .badge-archive { background: #f0faf0; color: #00a32a; border: 1px solid #00a32a; }
        .badge-taxonomy { background: #faf0f6; color: #8c1749; border: 1px solid #c3559b; }
        .badge-custom { background: #f0f0f5; color: #5b4b8a; border: 1px solid #8b7fc7; }
        .badge-part { background: #f0f5fa; color: #2271b1; border: 1px solid #72aee6; }
        .badge-and { background: #fef8ee; color: #9a6700; border: 1px solid #dba617; }
        .badge-or { background: #f0f0f1; color: #50575e; border: 1px solid #c3c4c7; }

        /* Condition pills */
        .etch-tc-condition-pill {
            display: inline-block; background: #f6f7f7; border: 1px solid #dcdcde;
            border-radius: 3px; padding: 2px 8px; margin: 2px 2px; font-size: 12px;
        }
        .etch-tc-logic-label {
            display: inline-block; font-size: 10px; font-weight: 700; color: #9a6700;
            background: #fef8ee; border: 1px solid #dba617; border-radius: 3px;
            padding: 1px 6px; margin: 2px 4px; vertical-align: middle;
        }
        .etch-tc-row-and { background: #fefcf5 !important; }
        .etch-tc-priority { font-weight: 600; color: #787c82; text-align: center; }

        /* Status */
        .etch-tc-status { font-weight: 500; font-size: 12px; }
        .status-publish { color: #00a32a; }
        .status-draft { color: #dba617; }
        .status-file { color: #787c82; }
        .status-missing { color: #d63638; }
        .etch-tc-status-toggle { cursor: pointer; border-bottom: 1px dashed currentColor; }
        .etch-tc-status-toggle:hover { opacity: 0.7; }

        /* Actions */
        .etch-tc-actions { white-space: nowrap; text-align: center; }
        .etch-tc-btn { font-size: 12px !important; margin: 2px 4px 2px 0 !important; }
        .etch-tc-create-hint { display: inline-block; margin-top: 6px; color: #787c82; }

        /* Rule Form */
        .etch-tc-rule-form {
            background: #f9f9f9; border: 1px solid #c3c4c7; border-radius: 4px;
            padding: 1.5em; margin: 1em 0;
        }
        .etch-tc-rule-form h3 { margin-top: 0; font-size: 15px; }
        .etch-tc-form-row { margin-bottom: 1.2em; }
        .etch-tc-form-row > label { display: block; font-weight: 600; margin-bottom: 4px; font-size: 13px; }
        .etch-tc-form-row select,
        .etch-tc-form-row input[type="text"] { min-width: 320px; max-width: 100%; }
        .etch-tc-form-actions { margin-top: 1.5em; padding-top: 1em; border-top: 1px solid #dcdcde; }
        .etch-tc-form-actions .button { margin-right: 8px; }

        /* Condition Rows */
        .etch-tc-condition-row {
            display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
            padding: 8px 10px; background: #fff; border: 1px solid #dcdcde; border-radius: 3px;
        }
        .etch-tc-condition-row select { min-width: 200px; }
        .etch-tc-remove-condition {
            color: #d63638; cursor: pointer; font-size: 20px; padding: 0 6px;
            background: none; border: none; line-height: 1;
        }
        .etch-tc-remove-condition:hover { color: #a00; }

        /* Drag Handle */
        .etch-tc-drag-handle {
            cursor: grab; color: #787c82; font-size: 16px; padding: 4px;
            user-select: none; text-align: center;
        }
        .etch-tc-drag-handle:active { cursor: grabbing; }

        /* Sortable Placeholder */
        .etch-tc-sortable-placeholder {
            height: 40px; background: #f0f6fc; border: 2px dashed #2271b1;
        }

        /* Rule action buttons */
        .etch-tc-rule-edit { }
        .etch-tc-rule-delete { color: #d63638 !important; border-color: #d63638 !important; }
        .etch-tc-rule-delete:hover { background: #d63638 !important; color: #fff !important; }

        /* Filter rules section */
        .etch-tc-filter-rules-section {
            margin-top: 2em; padding-top: 1.5em; border-top: 1px solid #dcdcde;
        }
        .etch-tc-filter-rules-section h3 { color: #787c82; font-size: 13px; text-transform: uppercase; letter-spacing: 0.03em; }
        .etch-tc-filter-rules-section tr { opacity: 0.7; }

        /* Help grid */
        .etch-tc-help-grid {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1.5em;
        }
        @media (max-width: 960px) {
            .etch-tc-help-grid { grid-template-columns: 1fr; }
        }
        .etch-tc-help-card {
            background: #f9f9f9; border: 1px solid #dcdcde; border-radius: 4px;
            padding: 1.2em 1.5em;
        }
        .etch-tc-help-card h3 { margin-top: 0; font-size: 14px; }
        .etch-tc-help-card pre {
            background: #1d2327; color: #e0e0e0; padding: 1em; border-radius: 4px;
            font-size: 12px; overflow-x: auto;
        }
        .etch-tc-help-card ul, .etch-tc-help-card ol { margin-left: 1.5em; }
        .etch-tc-help-card li { margin-bottom: 0.4em; }
        ';
    }
}

// Initialise admin page.
if ( is_admin() ) {
    new Etch_TC_Admin();
}
