<?php
if ( !defined( 'ABSPATH' ) ) {
    exit;
}

class DT_Posts extends Disciple_Tools_Posts {
    public function __construct() {
        parent::__construct();
    }

    /**
     * Get settings on the post type
     *
     * @param string $post_type
     *
     * @return array|WP_Error
     */
    public static function get_post_settings( string $post_type ){
        if ( !self::can_access( $post_type ) ){
            return new WP_Error( __FUNCTION__, "No permissions to read " . $post_type, [ 'status' => 403 ] );
        }
        return apply_filters( "dt_get_post_type_settings", [], $post_type );
    }

    /**
     * CRUD
     */

    /**
     * Create a post
     * For fields format See https://github.com/DiscipleTools/disciple-tools-theme/wiki/Contact-Fields-Format
     *
     * @param string $post_type
     * @param array $fields
     * @param bool $silent
     * @param bool $check_permissions
     *
     * @return array|WP_Error
     */
    public static function create_post( string $post_type, array $fields, bool $silent = false, bool $check_permissions = true ){
        if ( $check_permissions && !self::can_create( $post_type ) ){
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        $initial_fields = $fields;
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );

        //check to see if we want to create this contact.
        //could be used to check for duplicates first
        $continue = apply_filters( "dt_create_post_check_proceed", true, $fields );
        if ( !$continue ){
            return new WP_Error( __FUNCTION__, "Could not create this post. Maybe it already exists", [ 'status' => 409 ] );
        }
        //set title
        if ( !isset( $fields ["title"] ) ) {
            return new WP_Error( __FUNCTION__, "title needed", [ 'fields' => $fields ] );
        }
        $title = $fields["title"];
        unset( $fields["title"] );

        $create_date = null;
        if ( isset( $fields["create_date"] )){
            $create_date = $fields["create_date"];
            unset( $fields["create_date"] );
        }
        $initial_comment = null;
        if ( isset( $fields["initial_comment"] ) ) {
            $initial_comment = $fields["initial_comment"];
            unset( $fields["initial_comment"] );
        }
        $notes = null;
        if ( isset( $fields["notes"] ) ) {
            if ( is_array( $fields["notes"] ) ) {
                $notes = $fields["notes"];
                unset( $fields["notes"] );
            } else {
                return new WP_Error( __FUNCTION__, "'notes' field expected to be an array" );
            }
        }

        //get extra fields and defaults
        $fields = apply_filters( "dt_post_create_fields", $fields, $post_type );

        $allowed_fields = apply_filters( "dt_post_create_allow_fields", [], $post_type );
        $bad_fields = self::check_for_invalid_post_fields( $post_settings, $fields, $allowed_fields );
        if ( !empty( $bad_fields ) ) {
            return new WP_Error( __FUNCTION__, "One or more fields do not exist", [
                'bad_fields' => $bad_fields,
                'status' => 400
            ] );
        }

        $contact_methods_and_connections = [];
        $multi_select_fields = [];
        $location_meta = [];
        $post_user_meta = [];
        foreach ( $fields as $field_key => $field_value ){
            if ( self::is_post_key_contact_method_or_connection( $post_settings, $field_key ) ) {
                $contact_methods_and_connections[$field_key] = $field_value;
                unset( $fields[$field_key] );
            }
            $field_type = $post_settings["fields"][$field_key]["type"] ?? '';
            if ( $field_type === "multi_select" ){
                $multi_select_fields[$field_key] = $field_value;
                unset( $fields[$field_key] );
            }
            if ( $field_type === "location_meta" || $field_type === "location" ){
                $location_meta[$field_key] = $field_value;
                unset( $fields[$field_key] );
            }
            if ( $field_type === "post_user_meta" ){
                $post_user_meta[$field_key] = $field_value;
                unset( $fields[ $field_key ] );
            }
            if ( $field_type === 'date' && !is_numeric( $field_value )){
                $fields[$field_value] = strtotime( $field_value );
            }
        }
        /**
         * Create the post
         */
        $post = [
            "post_title"  => $title,
            'post_type'   => $post_type,
            "post_status" => 'publish',
            "meta_input"  => $fields,
        ];
        if ( $create_date ){
            $post["post_date"] = $create_date;
        }
        $post_id = wp_insert_post( $post );

        $potential_error = self::update_post_contact_methods( $post_settings, $post_id, $contact_methods_and_connections );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_connections( $post_settings, $post_id, $contact_methods_and_connections, null );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_multi_select_fields( $post_settings["fields"], $post_id, $multi_select_fields, null );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_location_grid_fields( $post_settings["fields"], $post_id, $location_meta, $post_type, null );
        if (is_wp_error( $potential_error )) {
            return $potential_error;
        }

        $potential_error = self::update_post_user_meta_fields( $post_settings["fields"], $post_id, $post_user_meta, [] );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        if ( $initial_comment ) {
            $potential_error = self::add_post_comment( $post_type, $post_id, $initial_comment, "comment", [], false );
            if ( is_wp_error( $potential_error ) ) {
                return $potential_error;
            }
        }

        if ( $notes ) {
            if ( ! is_array( $notes ) ) {
                return new WP_Error( 'notes_not_array', 'Notes must be an array' );
            }
            $error = new WP_Error();
            foreach ( $notes as $note ) {
                $potential_error = self::add_post_comment( $post_type, $post_id, $note, "comment", [], false, true );
                if ( is_wp_error( $potential_error ) ) {
                    $error->add( 'comment_fail', $potential_error->get_error_message() );
                }
            }
            if ( count( $error->get_error_messages() ) > 0 ) {
                return $error;
            }
        }


        //hook for signaling that a post has been created and the initial fields
        if ( !is_wp_error( $post_id )){
            do_action( "dt_post_created", $post_type, $post_id, $initial_fields );
            if ( !$silent ){
                Disciple_Tools_Notifications::insert_notification_for_new_post( $post_type, $fields, $post_id );
            }
        }


        if ( !self::can_view( $post_type, $post_id ) ){
            return [ "ID" => $post_id ];
        } else {
            return self::get_post( $post_type, $post_id );
        }
    }


    /**
     * Update post
     * For fields format See https://github.com/DiscipleTools/disciple-tools-theme/wiki/Contact-Fields-Format
     *
     * @param string $post_type
     * @param int $post_id
     * @param array $fields
     * @param bool $silent
     * @param bool $check_permissions
     *
     * @return array|WP_Error
     */
    public static function update_post( string $post_type, int $post_id, array $fields, bool $silent = false, bool $check_permissions = true ){
        $post_types = apply_filters( 'dt_registered_post_types', [ 'contacts', 'groups' ] );
        if ( !in_array( $post_type, $post_types ) ){
            return new WP_Error( __FUNCTION__, "Post type does not exist", [ 'status' => 403 ] );
        }
        if ( $check_permissions && !self::can_update( $post_type, $post_id ) ){
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        $initial_fields = $fields;
        $post = get_post( $post_id );
        if ( !$post ) {
            return new WP_Error( __FUNCTION__, "post does not exist" );
        }

        //get extra fields and defaults
        $fields = apply_filters( "dt_post_update_fields", $fields, $post_type, $post_id );
        if ( is_wp_error( $fields ) ){
            return $fields;
        }

        $allowed_fields = apply_filters( "dt_post_update_allow_fields", [], $post_type );
        $bad_fields = self::check_for_invalid_post_fields( $post_settings, $fields, $allowed_fields );
        if ( !empty( $bad_fields ) ) {
            return new WP_Error( __FUNCTION__, "One or more fields do not exist", [
                'bad_fields' => $bad_fields,
                'status' => 400
            ] );
        }
        $existing_post = self::get_post( $post_type, $post_id, false, false );

        if ( isset( $fields['title'] ) && $existing_post["title"] != $fields['title'] ) {
            wp_update_post( [
                'ID' => $post_id,
                'post_title' => $fields['title']
            ] );
            dt_activity_insert( [
                'action'            => 'field_update',
                'object_type'       => $post_type,
                'object_subtype'    => 'title',
                'object_id'         => $post_id,
                'object_name'       => $fields['title'],
                'meta_key'          => 'title',
                'meta_value'        => $fields['title'],
                'old_value'         => $existing_post['title'],
            ] );
        }

        $potential_error = self::update_post_contact_methods( $post_settings, $post_id, $fields, $existing_post );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_connections( $post_settings, $post_id, $fields, $existing_post );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_multi_select_fields( $post_settings["fields"], $post_id, $fields, $existing_post );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $potential_error = self::update_location_grid_fields( $post_settings["fields"], $post_id, $fields, $post_type, $existing_post );
        if (is_wp_error( $potential_error )) {
            return $potential_error;
        }

        $potential_error = self::update_post_user_meta_fields( $post_settings["fields"], $post_id, $fields, $existing_post );
        if ( is_wp_error( $potential_error )){
            return $potential_error;
        }

        $fields["last_modified"] = time(); //make sure the last modified field is updated.
        foreach ( $fields as $field_key => $field_value ){
            if ( !self::is_post_key_contact_method_or_connection( $post_settings, $field_key ) ) {
                $field_type = $post_settings["fields"][ $field_key ]["type"] ?? '';
                if ( $field_type === 'date' && !is_numeric( $field_value ) ) {
                    if ( empty( $field_value ) ) { // remove date
                        delete_post_meta( $post_id, $field_key );
                        continue;
                    }
                    $field_value = strtotime( $field_value );
                }
                $already_handled = [ "multi_select", "post_user_meta", "location", "location_meta" ];
                if ( $field_type && !in_array( $field_type, $already_handled ) ) {
                    update_post_meta( $post_id, $field_key, $field_value );
                }
            }
        }

        do_action( "dt_post_updated", $post_type, $post_id, $initial_fields, $existing_post );
        $post = self::get_post( $post_type, $post_id, false, false );
        if ( !$silent ){
            Disciple_Tools_Notifications::insert_notification_for_post_update( $post_type, $post, $existing_post, array_keys( $initial_fields ) );
        }

        if ( !self::can_view( $post_type, $post_id ) ){
            return [ "ID" => $post_id ];
        } else {
            return $post;
        }
    }


    /**
     * Get Post
     *
     * @param string $post_type
     * @param int $post_id
     * @param bool $use_cache
     * @param bool $check_permissions
     *
     * @return array|WP_Error
     */
    public static function get_post( string $post_type, int $post_id, bool $use_cache = true, bool $check_permissions = true ){
        if ( $check_permissions && !self::can_view( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "No permissions to read " . $post_type, [ 'status' => 403 ] );
        }
        $current_user_id = get_current_user_id();
        $cached = wp_cache_get( "post_" . $current_user_id . '_' . $post_id );
        if ( $cached && $use_cache ){
            return $cached;
        }

        $wp_post = get_post( $post_id );
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        if ( !$wp_post ){
            return new WP_Error( __FUNCTION__, "post does not exist", [ 'status' => 400 ] );
        }
        $fields = [];

        /**
         * add connections
         */
        foreach ( $post_settings["connection_types"] as $connection_type ){
            $field = $post_settings["fields"][$connection_type];
            $args = [
                'connected_type'   => $field["p2p_key"],
                'connected_direction' => $field["p2p_direction"],
                'connected_items'  => $wp_post,
                'nopaging'         => true,
                'suppress_filters' => false,
            ];
            $connections = get_posts( $args );
            $fields[$connection_type] = [];
            foreach ( $connections as $c ){
                $fields[$connection_type][] = self::filter_wp_post_object_fields( $c );
            }
        }

        $fields["ID"] = $post_id;
        $fields["created_date"] = $wp_post->post_date;
        $fields["permalink"] = get_permalink( $post_id );

        self::adjust_post_custom_fields( $post_settings, $post_id, $fields );
        $fields["title"] = $wp_post->post_title;

        $fields = apply_filters( 'dt_after_get_post_fields_filter', $fields, $post_type );
        wp_cache_set( "post_" . $current_user_id . '_' . $post_id, $fields );
        return $fields;

    }


    /**
     * Get a list of posts
     * For query format see https://github.com/DiscipleTools/disciple-tools-theme/wiki/Filter-and-Search-Lists
     *
     * @param $post_type
     * @param $search_and_filter_query
     *
     * @return array|WP_Error
     */
    public static function list_posts( $post_type, $search_and_filter_query ){
        $fields_to_return = [];
        if ( isset( $search_and_filter_query["fields_to_return"] ) ){
            $fields_to_return = $search_and_filter_query["fields_to_return"];
            unset( $search_and_filter_query["fields_to_return"] );
        }
        $data = self::search_viewable_post( $post_type, $search_and_filter_query );
        if ( is_wp_error( $data ) ) {
            return $data;
        }
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        $records = $data["posts"];
        foreach ( $post_settings["connection_types"] as $connection_type ){
            if ( empty( $fields_to_return ) || in_array( $connection_type, $fields_to_return ) ){
                $p2p_type = $post_settings["fields"][$connection_type]["p2p_key"];
                $p2p_direction = $post_settings["fields"][$connection_type]["p2p_direction"];
                $q = p2p_type( $p2p_type )->set_direction( $p2p_direction )->get_connected( $records, [ "nopaging" => true ], 'abstract' );
                $raw_connected = array();
                foreach ( $q->items as $item ){
                    $raw_connected[] = $item->get_object();
                }
                p2p_distribute_connected( $records, $raw_connected, $connection_type );
            }
        }

        $ids = [];
        foreach ( $records as $record ) {
            $ids[] = $record->ID;
        }
        $ids_sql = dt_array_to_sql( $ids );
        $field_keys = [];
        if ( !in_array( 'all_fields', $fields_to_return ) ){
            $field_keys = empty( $fields_to_return ) ? array_keys( $post_settings["fields"] ) : $fields_to_return;
        }
        $field_keys_sql = dt_array_to_sql( $field_keys );

        global $wpdb;
        $all_posts = [];
        // phpcs:disable
        // WordPress.WP.PreparedSQL.NotPrepared
        $all_post_meta = $wpdb->get_results( "
            SELECT *
                FROM $wpdb->postmeta pm
                WHERE pm.post_id IN ( $ids_sql )
                AND pm.meta_key IN ( $field_keys_sql )
            UNION SELECT *
                FROM $wpdb->postmeta pm 
                WHERE pm.post_id IN ( $ids_sql )
                AND pm.meta_key LIKE 'contact_%'
        ", ARRAY_A);
        $user_id = get_current_user_id();
        $all_user_meta = $wpdb->get_results( $wpdb->prepare( "
            SELECT *
            FROM $wpdb->dt_post_user_meta um
            WHERE um.post_id IN ( $ids_sql )
            AND user_id = %s
            AND um.meta_key IN ( $field_keys_sql )
        ", $user_id ), ARRAY_A);
        // phpcs:disable

        foreach ( $all_post_meta as $index => $meta_row ) {
            if ( !isset( $all_posts[$meta_row["post_id"]] ) ) {
                $all_posts[$meta_row["post_id"]] = [];
            }
            if ( !isset( $all_posts[$meta_row["post_id"]][$meta_row["meta_key"]] ) ) {
                $all_posts[$meta_row["post_id"]][$meta_row["meta_key"]] = [];
            }
            $all_posts[$meta_row["post_id"]][$meta_row["meta_key"]][] = $meta_row["meta_value"];
        }
        $all_post_user_meta =[];
        foreach ( $all_user_meta as $index => $meta_row ){
            if ( !isset( $all_post_user_meta[$meta_row["post_id"]] ) ) {
                $all_post_user_meta[$meta_row["post_id"]] = [];
            }
            $all_post_user_meta[$meta_row["post_id"]][] = $meta_row;
        }

        $site_url = site_url();
        foreach ( $records as  &$record ){
            foreach ( $post_settings["connection_types"] as $connection_type ){
                if ( empty( $fields_to_return ) || in_array( $connection_type, $fields_to_return ) ) {
                    foreach ( $record->$connection_type as &$post ) {
                        $post = self::filter_wp_post_object_fields( $post );
                    }
                }
            }
            $record = (array) $record;

            self::adjust_post_custom_fields( $post_settings, $record["ID"], $record, $fields_to_return, $all_posts[$record["ID"]] ?? [], $all_post_user_meta[$record["ID"]] ?? [] );
            $record["permalink"] = $site_url . '/' . $post_type .'/' . $record["ID"];
        }
        $data["posts"] = $records;

        $data = apply_filters( "dt_list_posts_custom_fields", $data, $post_type );

        return $data;
    }


    /**
     * Get viewable in compact form
     *
     * @param string $post_type
     * @param string $search_string
     *
     * @return array|WP_Error|WP_Query
     */
    public static function get_viewable_compact( string $post_type, string $search_string ) {
        if ( !self::can_access( $post_type ) ) {
            return new WP_Error( __FUNCTION__, sprintf( "You do not have access to these %s", $post_type ), [ 'status' => 403 ] );
        }
        global $wpdb;
        $current_user = wp_get_current_user();
        $compact = [];
        $search_string = esc_sql( sanitize_text_field( $search_string ) );
        $shared_with_user = [];
        $users_interacted_with =[];

        //search by post_id
        if ( is_numeric( $search_string ) ){
            $post = get_post( $search_string );
            if ( $post && self::can_view( $post_type, $post->ID ) ){
                $compact[] = [
                    "ID" => (string) $post->ID,
                    "name" => $post->post_title,
                    "user" => false,
                    "status" => null
                ];
            }
        }

        $send_quick_results = false;
        if ( empty( $search_string ) ){
            //find the most recent posts interacted with by the user
            $posts = $wpdb->get_results( $wpdb->prepare( "
                SELECT *, statusReport.meta_value as overall_status, pm.meta_value as corresponds_to_user
                FROM $wpdb->posts p
                INNER JOIN (
                    SELECT log.object_id
                    FROM $wpdb->dt_activity_log log
                    WHERE log.histid IN (
                        SELECT max(l.histid) FROM $wpdb->dt_activity_log l 
                        WHERE l.user_id = %s  AND l.object_type = %s
                        GROUP BY l.object_id
                    )
                    ORDER BY log.histid desc
                    LIMIT 30
                ) as log
                ON log.object_id = p.ID
                LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = p.ID AND statusReport.meta_key = 'overall_status')
                LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = p.ID AND pm.meta_key = 'corresponds_to_user' )
                WHERE p.post_type = %s AND (p.post_status = 'publish' OR p.post_status = 'private')
            ", $current_user->ID, $post_type, $post_type ), OBJECT );
            if ( !empty( $posts ) ){
                $send_quick_results = true;
            }
        }
        if ( !$send_quick_results ){
            if ( !self::can_view_all( $post_type ) ) {
                //@todo better way to get the contact records for users my contacts are shared with
                $shared_with_user = self::get_posts_shared_with_user( $post_type, $current_user->ID, $search_string );
                $query_args['meta_key'] = 'assigned_to';
                $query_args['meta_value'] = "user-" . $current_user->ID;
                $posts = $wpdb->get_results( $wpdb->prepare( "
                    SELECT *, statusReport.meta_value as overall_status, pm.meta_value as corresponds_to_user 
                    FROM $wpdb->posts
                    INNER JOIN $wpdb->postmeta as assigned_to ON ( $wpdb->posts.ID = assigned_to.post_id AND assigned_to.meta_key = 'assigned_to')
                    LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = $wpdb->posts.ID AND statusReport.meta_key = 'overall_status')
                    LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = $wpdb->posts.ID AND pm.meta_key = 'corresponds_to_user' )
                    WHERE assigned_to.meta_value = %s
                    AND $wpdb->posts.post_title LIKE %s
                    AND $wpdb->posts.post_type = %s AND ($wpdb->posts.post_status = 'publish' OR $wpdb->posts.post_status = 'private')
                    ORDER BY CASE
                        WHEN $wpdb->posts.post_title LIKE %s then 1
                        ELSE 2
                    END, CHAR_LENGTH($wpdb->posts.post_title), $wpdb->posts.post_title
                    LIMIT 0, 30
                ", "user-". $current_user->ID, '%' . $search_string . '%', $post_type, '%' . $search_string . '%'
                ), OBJECT );
            } else {
                $posts = $wpdb->get_results( $wpdb->prepare( "
                    SELECT ID, post_title, pm.meta_value as corresponds_to_user, statusReport.meta_value as overall_status
                    FROM $wpdb->posts p
                    LEFT JOIN $wpdb->postmeta pm ON ( pm.post_id = p.ID AND pm.meta_key = 'corresponds_to_user' )
                    LEFT JOIN $wpdb->postmeta statusReport ON ( statusReport.post_id = p.ID AND statusReport.meta_key = 'overall_status')
                    WHERE p.ID IN ( SELECT ID FROM $wpdb->posts WHERE post_title LIKE %s )
                    AND p.post_type = %s AND (p.post_status = 'publish' OR p.post_status = 'private')
                    ORDER BY  CASE
                        WHEN pm.meta_value > 0 then 1
                        WHEN CHAR_LENGTH(%s) > 0 && p.post_title LIKE %s then 2
                        ELSE 3
                    END, CHAR_LENGTH(p.post_title), p.post_title
                    LIMIT 0, 30
                ", '%' . $search_string . '%', $post_type, $search_string, '%' . $search_string . '%'
                ), OBJECT );
            }
        }
        if ( is_wp_error( $posts ) ) {
            return $posts;
        }

        $post_ids = array_map(
            function( $post ) {
                return $post->ID;
            },
            $posts
        );
        if ( $post_type === 'contacts' && !self::can_view_all( $post_type ) && sizeof( $posts ) < 30 ) {
            $users_interacted_with = Disciple_Tools_Users::get_assignable_users_compact( $search_string );
            foreach ( $users_interacted_with as $user ) {
                $post_id = Disciple_Tools_Users::get_contact_for_user( $user["ID"] );
                if ( $post_id ){
                    if ( !in_array( $post_id, $post_ids ) ) {
                        $compact[] = [
                            "ID" => $post_id,
                            "name" => $user["name"],
                            "user" => true
                        ];
                    }
                }
            }
        }
        foreach ( $shared_with_user as $shared ) {
            if ( !in_array( $shared->ID, $post_ids ) ) {
                $compact[] = [
                    "ID" => $shared->ID,
                    "name" => $shared->post_title
                ];
            }
        }
        foreach ( $posts as $post ) {
            $compact[] = [
                "ID" => $post->ID,
                "name" => $post->post_title,
                "user" => $post->corresponds_to_user >= 1,
                "status" => $post->overall_status
            ];
        }

        return [
            "total" => sizeof( $compact ),
            "posts" => array_slice( $compact, 0, 50 )
        ];
    }

    /**
     * Comments
     */

    /**
     * @param string $post_type
     * @param int $post_id
     * @param string $comment_html
     * @param string $type      normally 'comment', different comment types can have their own section in the comments activity
     * @param array $args       [user_id, comment_date, comment_author etc]
     * @param bool $check_permissions
     * @param bool $silent
     *
     * @return false|int|WP_Error
     */
    public static function add_post_comment( string $post_type, int $post_id, string $comment_html, string $type = "comment", array $args = [], bool $check_permissions = true, $silent = false ) {
        if ( $check_permissions && !self::can_update( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }
        //limit comment length to 5000
        $comments = str_split( $comment_html, 4999 );
        $user = wp_get_current_user();
        $user_id = $args["user_id"] ?? get_current_user_id();

        $created_comment_id = null;
        foreach ( $comments as $comment ){
            $comment_data = [
                'comment_post_ID'      => $post_id,
                'comment_content'      => $comment,
                'user_id'              => $user_id,
                'comment_author'       => $args["comment_author"] ?? $user->display_name,
                'comment_author_url'   => $args["comment_author_url"] ?? "",
                'comment_author_email' => $user->user_email,
                'comment_type'         => $type,
            ];
            if ( isset( $args["comment_date"] ) ){
                $comment_data["comment_date"] = $args["comment_date"];
                $comment_data["comment_date_gmt"] = $args["comment_date"];
            }
            $new_comment = wp_new_comment( $comment_data );
            if ( !$created_comment_id ){
                $created_comment_id = $new_comment;
            }
        }

        if ( !$silent && !is_wp_error( $created_comment_id )){
            Disciple_Tools_Notifications_Comments::insert_notification_for_comment( $created_comment_id );
        }
        if ( !is_wp_error( $created_comment_id ) ){
            do_action( "dt_comment_created", $post_type, $post_id, $created_comment_id, $type );
        }
        return $created_comment_id;
    }

    public static function update_post_comment( int $comment_id, string $comment_content, bool $check_permissions = true ){
        $comment = get_comment( $comment_id );
        if ( $check_permissions && ( ( isset( $comment->user_id ) && $comment->user_id != get_current_user_id() ) || !self::can_update( get_post_type( $comment->comment_post_ID ), $comment->comment_post_ID ?? 0 ) ) ) {
            return new WP_Error( __FUNCTION__, "You don't have permission to edit this comment", [ 'status' => 403 ] );
        }
        if ( !$comment ){
            return new WP_Error( __FUNCTION__, "No comment found with id: " . $comment_id, [ 'status' => 403 ] );
        }
        $comment = [
            "comment_content" => $comment_content,
            "comment_ID" => $comment_id,
        ];
        return wp_update_comment( $comment );
    }

    public static function delete_post_comment( int $comment_id, bool $check_permissions = true ){
        $comment = get_comment( $comment_id );
        if ( $check_permissions && ( ( isset( $comment->user_id ) && $comment->user_id != get_current_user_id() ) || !self::can_update( get_post_type( $comment->comment_post_ID ), $comment->comment_post_ID ?? 0 ) ) ) {
            return new WP_Error( __FUNCTION__, "You don't have permission to delete this comment", [ 'status' => 403 ] );
        }
        if ( !$comment ){
            return new WP_Error( __FUNCTION__, "No comment found with id: " . $comment_id, [ 'status' => 403 ] );
        }
        return wp_delete_comment( $comment_id );
    }

    /**
     * Get post comments
     *
     * @param string $post_type
     * @param int $post_id
     * @param bool $check_permissions
     * @param string $type
     * @param array $args
     *
     * @return array|int|WP_Error
     */
    public static function get_post_comments( string $post_type, int $post_id, bool $check_permissions = true, string $type = "all", array $args = [] ) {
        if ( $check_permissions && !self::can_view( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "No permissions to read post", [ 'status' => 403 ] );
        }
        //setting type to "comment" does not work.
        $comments_query = [
            'post_id' => $post_id,
            "type" => $type
        ];
        if ( isset( $args["offset"] ) || isset( $args["number"] ) ){
            $comments_query["offset"] = $args["offset"] ?? 0;
            $comments_query["number"] = $args["number"] ?? '';
        }
        $comments = get_comments( $comments_query );


        $response_body = [];
        foreach ( $comments as $comment ){
            $url = !empty( $comment->comment_author_url ) ? $comment->comment_author_url : get_avatar_url( $comment->user_id, [ 'size' => '16' ] );
            $c = [
                "comment_ID" => $comment->comment_ID,
                "comment_author" => !empty( $display_name ) ? $display_name : $comment->comment_author,
                "comment_author_email" => $comment->comment_author_email,
                "comment_date" => $comment->comment_date,
                "comment_date_gmt" => $comment->comment_date_gmt,
                "gravatar" => preg_replace( "/^http:/i", "https:", $url ),
                "comment_content" => wp_kses_post( $comment->comment_content ),
                "user_id" => $comment->user_id,
                "comment_type" => $comment->comment_type,
                "comment_post_ID" => $comment->comment_post_ID
            ];
            $response_body[] =$c;
        }

        return [
            "comments" => $response_body,
            "total" => wp_count_comments( $post_id )->total_comments
        ];
    }


    /**
     * Activity
     */

    /**
     * @param string $post_type
     * @param int $post_id
     * @param array $args
     *
     * @return array|null|object|WP_Error
     */
    public static function get_post_activity( string $post_type, int $post_id, array $args = [] ) {
        global $wpdb;
        if ( !self::can_view( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "No permissions to read: " . $post_type, [ 'status' => 403 ] );
        }
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        $fields = $post_settings["fields"];
        $hidden_fields = [];
        foreach ( $fields as $field_key => $field ){
            if ( isset( $field["hidden"] ) && $field["hidden"] === true ){
                $hidden_fields[] = $field_key;
            }
        }

        $hidden_keys = empty( $hidden_fields ) ? "''" : dt_array_to_sql( $hidden_fields );
        // phpcs:disable
        // WordPress.WP.PreparedSQL.NotPrepared
        $activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                *
            FROM
                `$wpdb->dt_activity_log`
            WHERE
                `object_type` = %s
                AND `object_id` = %s
                AND meta_key NOT IN ( $hidden_keys )
            ORDER BY hist_time DESC",
            $post_type,
            $post_id
        ) );
        //@phpcs:enable
        $activity_simple = [];
        foreach ( $activity as $a ) {
            $a->object_note = self::format_activity_message( $a, $post_settings );
            if ( isset( $a->user_id ) && $a->user_id > 0 ) {
                $user = get_user_by( "id", $a->user_id );
                if ( $user ){
                    $a->name =$user->display_name;
                    $a->gravatar = get_avatar_url( $user->ID, [ 'size' => '16' ] );
                }
            } else if ( isset( $a->user_caps ) && strlen( $a->user_caps ) === 32 ){
                //get site-link name
                $site_link = Site_Link_System::get_post_id_by_site_key( $a->user_caps );
                if ( $site_link ){
                    $a->name = get_the_title( $site_link );
                }
            }
            if ( !empty( $a->object_note ) ){
                $activity_simple[] = [
                    "meta_key" => $a->meta_key,
                    "gravatar" => isset( $a->gravatar ) ? $a->gravatar : "",
                    "name" => isset( $a->name ) ? $a->name : "",
                    "object_note" => $a->object_note,
                    "hist_time" => $a->hist_time,
                    "meta_id" => $a->meta_id,
                    "histid" => $a->histid,
                ];
            }
        }

        $paged = array_slice( $activity_simple, $args["offset"] ?? 0, $args["number"] ?? 1000 );
        return [
            "activity" => $paged,
            "total" => sizeof( $activity_simple )
        ];
    }

    public static function get_post_single_activity( string $post_type, int $post_id, int $activity_id ){
        global $wpdb;
        if ( !self::can_view( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "No permissions to read group", [ 'status' => 403 ] );
        }
        $post_settings = apply_filters( "dt_get_post_type_settings", [], $post_type );
        $activity = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                *
            FROM
                `$wpdb->dt_activity_log`
            WHERE
                `object_type` = %s
                AND `object_id` = %s
                AND `histid` = %s",
            $post_type,
            $post_id,
            $activity_id
        ) );
        foreach ( $activity as $a ) {
            $a->object_note = self::format_activity_message( $a, $post_settings );
            if ( isset( $a->user_id ) && $a->user_id > 0 ) {
                $user = get_user_by( "id", $a->user_id );
                if ( $user ) {
                    $a->name = $user->display_name;
                }
            }
        }
        if ( isset( $activity[0] ) ){
            return $activity[0];
        }
        return $activity;
    }

    /**
     * Sharing
     */

    /**
     * Gets an array of users whom the post is shared with.
     *
     * @param string $post_type
     * @param int $post_id
     *
     * @param bool $check_permissions
     *
     * @return array|mixed
     */
    public static function get_shared_with( string $post_type, int $post_id, bool $check_permissions = true ) {
        global $wpdb;

        if ( $check_permissions && !self::can_view( $post_type, $post_id ) ) {
            return new WP_Error( 'no_permission', "You do not have permission for this", [ 'status' => 403 ] );
        }

        $shared_with_list = [];
        $shares = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                *
            FROM
                `$wpdb->dt_share`
            WHERE
                post_id = %s",
            $post_id
        ), ARRAY_A );

        // adds display name to the array
        foreach ( $shares as $share ) {
            $display_name = dt_get_user_display_name( $share['user_id'] );
            if ( is_wp_error( $display_name ) ) {
                $display_name = 'Not Found';
            }
            $share['display_name'] = $display_name;
            $shared_with_list[] = $share;
        }

        return $shared_with_list;
    }

    /**
     * Removes share record
     *
     * @param string $post_type
     * @param int    $post_id
     * @param int    $user_id
     *
     * @return false|int|WP_Error
     */
    public static function remove_shared( string $post_type, int $post_id, int $user_id ) {
        global $wpdb;

        if ( !self::can_update( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "You do not have permission to unshare", [ 'status' => 403 ] );
        }

        $assigned_to_meta = get_post_meta( $post_id, "assigned_to", true );
        if ( !( current_user_can( 'update_any_' . $post_type ) ||
                get_current_user_id() === $user_id ||
                dt_get_user_id_from_assigned_to( $assigned_to_meta ) === get_current_user_id() )
        ){
            $name = dt_get_user_display_name( $user_id );
            return new WP_Error( __FUNCTION__, "You do not have permission to unshare with " . $name, [ 'status' => 403 ] );
        }


        $table = $wpdb->dt_share;
        $where = [
            'user_id' => $user_id,
            'post_id' => $post_id
        ];
        $result = $wpdb->delete( $table, $where );

        if ( $result == false ) {
            return new WP_Error( 'remove_shared', __( "Record not deleted." ), [ 'status' => 418 ] );
        } else {

            // log share activity
            dt_activity_insert(
                [
                    'action'         => 'remove',
                    'object_type'    => get_post_type( $post_id ),
                    'object_subtype' => 'share',
                    'object_name'    => get_the_title( $post_id ),
                    'object_id'      => $post_id,
                    'meta_id'        => '', // id of the comment
                    'meta_key'       => '',
                    'meta_value'     => $user_id,
                    'meta_parent'    => '',
                    'object_note'    => 'Sharing of ' . get_the_title( $post_id ) . ' was removed for ' . dt_get_user_display_name( $user_id ),
                ]
            );

            return $result;
        }
    }

    /**
     * Adds a share record
     *
     * @param string $post_type
     * @param int $post_id
     * @param int $user_id
     * @param array $meta
     * @param bool $send_notifications
     * @param bool $check_permissions
     * @param bool $insert_activity
     *
     * @return false|int|WP_Error
     */
    public static function add_shared( string $post_type, int $post_id, int $user_id, $meta = null, bool $send_notifications = true, $check_permissions = true, bool $insert_activity = true ) {
        global $wpdb;

        if ( $check_permissions && !self::can_update( $post_type, $post_id ) ) {
            return new WP_Error( __FUNCTION__, "You do not have permission for this", [ 'status' => 403 ] );
        }

        $table = $wpdb->dt_share;
        $data = [
            'user_id' => $user_id,
            'post_id' => $post_id,
            'meta'    => $meta,
        ];
        $format = [
            '%d',
            '%d',
            '%s',
        ];

        $duplicate_check = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                id
            FROM
                `$wpdb->dt_share`
            WHERE
                post_id = %s
                AND user_id = %s",
            $post_id,
            $user_id
        ), ARRAY_A );

        if ( is_null( $duplicate_check ) ) {

            // insert share record
            $results = $wpdb->insert( $table, $data, $format );

            if ( $insert_activity ){
                // log share activity
                dt_activity_insert(
                    [
                        'action'         => 'share',
                        'object_type'    => get_post_type( $post_id ),
                        'object_subtype' => 'share',
                        'object_name'    => get_the_title( $post_id ),
                        'object_id'      => $post_id,
                        'meta_id'        => '', // id of the comment
                        'meta_key'       => '',
                        'meta_value'     => $user_id,
                        'meta_parent'    => '',
                        'object_note'    => strip_tags( get_the_title( $post_id ) ) . ' was shared with ' . dt_get_user_display_name( $user_id ),
                    ]
                );
            }

            // Add share notification
            if ( $send_notifications ){
                Disciple_Tools_Notifications::insert_notification_for_share( $user_id, $post_id );
            }

            return $results;
        } else {
            return new WP_Error( 'add_shared', __( "Post already shared with user." ), [ 'status' => 418 ] );
        }
    }


    /**
     * Following
     */
    /**
     * @param $post_type
     * @param $post_id
     * @param bool $check_permissions
     *
     * @return array|WP_Error
     */
    public static function get_users_following_post( $post_type, $post_id, $check_permissions = true ){
        if ( $check_permissions && !self::can_access( $post_type ) ){
            return new WP_Error( __FUNCTION__, "You do not have access to: " . $post_type, [ 'status' => 403 ] );
        }
        $users = [];
        $assigned_to_meta = get_post_meta( $post_id, "assigned_to", true );
        $assigned_to = dt_get_user_id_from_assigned_to( $assigned_to_meta );
        if ( $post_type === "contacts" ){
            array_merge( $users, self::get_subassigned_users( $post_id ) );
        }
        $shared_with = self::get_shared_with( $post_type, $post_id, false );
        foreach ( $shared_with as $shared ){
            $users[] = (int) $shared["user_id"];
        }
        $users_follow = get_post_meta( $post_id, "follow", false );
        foreach ( $users_follow as $follow ){
            if ( !in_array( $follow, $users ) && user_can( $follow, "view_any_". $post_type ) ){
                $users[] = $follow;
            }
        }
        $users_unfollow = get_post_meta( $post_id, "unfollow", false );
        foreach ( $users_unfollow as $unfollower ){
            $key = array_search( $unfollower, $users );
            if ( $key !== false ){
                unset( $users[$key] );
            }
        }
        //you always follow a post if you are assigned to it.
        if ( $assigned_to ){
            $users[] = $assigned_to;
        }
        return array_unique( $users );
    }
}


