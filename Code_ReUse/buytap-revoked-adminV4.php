<?php
/*
Plugin Name: BuyTap - Revoked Orders Admin
Description: Adds an admin page to list Revoked orders with one-click 'Reinstate to Pending' for BuyTap.
Version: 1.0.1
Author: Philip Osir & ChatGPT
License: GPL2+
*/

if (!defined('ABSPATH')) { exit; }

/**
 * This plugin assumes:
 * - Custom post type: buytap_order
 * - Order meta 'status' can be 'Pending', 'Paired', 'Active', 'Matured', 'Closed', 'Revoked'
 * - Token pool stored in option: buytap_total_tokens
 * - Existing pairing function: buytap_pair_buyer_with_seller($order_id) (optional)
 */

/**
 * Admin submenu: Revoked Orders
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=buytap_order',
        'Revoked Orders',
        'Revoked Orders',
        'manage_options',
        'buytap-revoked-orders',
        'buytap_render_revoked_orders_admin_page'
    );
});

/**
 * Render the Revoked Orders admin page
 */
function buytap_render_revoked_orders_admin_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to view this page.');
    }

    echo '<div class="wrap"><h1>Revoked Orders</h1>';

    // Notices
    if (!empty($_GET['buytap_msg'])) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($_GET['buytap_msg']) . '</p></div>';
    }
    if (!empty($_GET['buytap_err'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($_GET['buytap_err']) . '</p></div>';
    }

    // Fetch revoked orders
    $revoked_orders = get_posts([
        'post_type'      => 'buytap_order',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => [
            ['key' => 'status', 'value' => 'Revoked']
        ],
    ]);

    if (!$revoked_orders) {
        echo '<p>No revoked orders.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr>
            <th>ID</th>
            <th>Title</th>
            <th>User</th>
            <th>Order Date</th>
            <th>Details</th>
            <th>Amount</th>
            <th>Reason</th>
            <th>Revoked On</th>
            <th>Action</th>
          </tr></thead><tbody>';

    foreach ($revoked_orders as $o) {
        $uid           = (int) $o->post_author;
        $user          = get_userdata($uid);
        $user_name     = $user ? $user->display_name : 'Unknown';
        $order_date    = get_post_meta($o->ID, 'order_date', true);
        $details       = get_post_meta($o->ID, 'order_details', true);
        $amount        = (float) get_post_meta($o->ID, 'amount_to_send', true);
        $reason        = get_post_meta($o->ID, 'revoked_reason', true);
        $date_revoked  = get_post_meta($o->ID, 'date_revoked', true);

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=buytap_reinstate_order&order_id=' . $o->ID),
            'buytap_reinstate_' . $o->ID
        );

        echo '<tr>';
        echo '<td>' . (int)$o->ID . '</td>';
        echo '<td>' . esc_html($o->post_title) . '</td>';
        echo '<td>' . esc_html($user_name) . '</td>';
        echo '<td>' . esc_html($order_date) . '</td>';
        echo '<td>' . esc_html($details) . '</td>';
        echo '<td>Ksh ' . number_format($amount) . '</td>';
        echo '<td>' . esc_html($reason) . '</td>';
        echo '<td>' . esc_html($date_revoked) . '</td>';
        echo '<td><a class="button button-primary" href="' . esc_url($url) . '">Reinstate to Pending</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

/**
 * Admin-post handler: Reinstate a revoked order back to Pending
 */
add_action('admin_post_buytap_reinstate_order', function () {
    if (!current_user_can('manage_options')) {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Permission denied'), admin_url('edit.php?post_type=buytap_order&page=buytap-revoked-orders')));
        exit;
    }

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Invalid order'), admin_url('edit.php?post_type=buytap_order&page=buytap-revoked-orders')));
        exit;
    }

    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'buytap_reinstate_' . $order_id)) {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Invalid nonce'), admin_url('edit.php?post_type=buytap_order&page=buytap-revoked-orders')));
        exit;
    }

    // Only if currently Revoked
    $status = get_post_meta($order_id, 'status', true);
    if ($status !== 'Revoked') {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Order is not revoked'), admin_url('edit.php?post_type=buytap_order&page=buytap-revoked-orders')));
        exit;
    }

    // Deduct buyer amount back from pool (it was returned when revoked)
    $amount = (int) get_post_meta($order_id, 'amount_to_send', true);
    if ($amount > 0) {
        $pool = (int) get_option('buytap_total_tokens', 0);
        update_option('buytap_total_tokens', max(0, $pool - $amount));
    }

    // Reset order to Pending
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'sub_status', 'Pending');
    update_post_meta($order_id, 'is_paired', 'no');

    // Clean pairing remnants
    delete_post_meta($order_id, 'paired_seller_order_id');
    delete_post_meta($order_id, 'pair_time');
    delete_post_meta($order_id, 'buyer_confirmed');
    delete_post_meta($order_id, 'seller_name');
    delete_post_meta($order_id, 'seller_number');

    // Attempt immediate pairing with any matured seller
    if (function_exists('buytap_pair_buyer_with_seller')) {
        buytap_pair_buyer_with_seller($order_id);
    }

    wp_safe_redirect(add_query_arg('buytap_msg', rawurlencode('Order reinstated to Pending'), admin_url('edit.php?post_type=buytap_order&page=buytap-revoked-orders')));
    exit;
});

// ===== Force Revoke (row action + handler) =====

// 1) Add "Force Revoke" link in the BuyTap Orders list (only when it makes sense)
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'buytap_order' || !current_user_can('manage_options')) {
        return $actions;
    }

    $status = get_post_meta($post->ID, 'status', true);
    // Only allow on Pending / Paired (not Active/Closed/Revoked)
    if (in_array($status, ['Pending', 'paired', 'Paired'], true)) {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=buytap_force_revoke_order&order_id=' . $post->ID),
            'buytap_force_revoke_' . $post->ID
        );
        $actions['buytap_force_revoke'] = '<a href="'.esc_url($url).'" style="color:#b32d2e;">Force Revoke</a>';
    }

    return $actions;
}, 10, 2);

// 2) Handle the force revoke action securely (FIXED VERSION)
add_action('admin_post_buytap_force_revoke_order', function () {
    if (!current_user_can('manage_options')) {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Permission denied'), admin_url('edit.php?post_type=buytap_order')));
        exit;
    }

    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Invalid order'), admin_url('edit.php?post_type=buytap_order')));
        exit;
    }
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'buytap_force_revoke_' . $order_id)) {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Invalid nonce'), admin_url('edit.php?post_type=buytap_order')));
        exit;
    }

    $status = get_post_meta($order_id, 'status', true);

    // Only allow if Pending / Paired (avoid nuking Active/Closed/Revoked)
    if (!in_array($status, ['Pending', 'paired', 'Paired'], true)) {
        wp_safe_redirect(add_query_arg('buytap_err', rawurlencode('Order is not eligible for forced revoke'), admin_url('edit.php?post_type=buytap_order')));
        exit;
    }

    // If it was paired, handle the seller relationship properly
    $seller_id = (int) get_post_meta($order_id, 'paired_seller_order_id', true);
    if ($seller_id && get_post_type($seller_id) === 'buytap_order') {
        
        // ✅ CRITICAL FIX: Only remove THIS specific buyer's chunk, not all buyers
        $revoked_amount = buytap_remove_single_buyer_chunk($seller_id, $order_id);
        
        // ✅ Check if seller has other active buyers before making any changes
        $has_other_buyers = buytap_seller_has_other_buyers($seller_id, $order_id);
        
        if ($has_other_buyers) {
            // Seller has other buyers - keep everything as is, just remove this one
            update_post_meta($seller_id, 'is_paired', 'yes');
            // NO status change - seller stays in current status
        } else {
            // Only if no other buyers, reset to Matured
            update_post_meta($seller_id, 'is_paired', 'no');
            if (get_post_meta($seller_id, 'status', true) !== 'Matured') {
                update_post_meta($seller_id, 'status', 'Matured');
            }
            
            // Try to re‑pair seller with new buyers
            if (function_exists('buytap_pair_orders')) {
                buytap_pair_orders($seller_id);
            }
        }
    }

    // Return ONLY this buyer's amount to token pool
    $amount = (int) get_post_meta($order_id, 'amount_to_send', true);
    if ($amount > 0) {
        $pool = (int) get_option('buytap_total_tokens', 0);
        update_option('buytap_total_tokens', $pool + $amount);
    }

    // Clean chunks + mark this specific buyer as revoked
    if (function_exists('buytap_force_revoke_cleanup_chunks')) {
        buytap_force_revoke_cleanup_chunks($order_id, 'Admin forced revoke.');
    } else {
        buytap_fallback_cleanup_chunks($order_id, 'Admin forced revoke.');
    }

    // Clean pairing remnants on THIS buyer only
    delete_post_meta($order_id, 'paired_seller_order_id');
    delete_post_meta($order_id, 'pair_time');
    delete_post_meta($order_id, 'buyer_confirmed');
    delete_post_meta($order_id, 'seller_name');
    delete_post_meta($order_id, 'seller_number');

    wp_safe_redirect(add_query_arg('buytap_msg', rawurlencode('Order forcibly revoked'), admin_url('edit.php?post_type=buytap_order')));
    exit;
});

/**
 * Remove only the specific buyer-seller relationship chunk
 * Returns the amount that was revoked
 */
function buytap_remove_single_buyer_chunk($seller_id, $buyer_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'buytap_chunks';
    
    // Get the chunk amount before deleting
    $chunk_amount = $wpdb->get_var($wpdb->prepare(
        "SELECT amount FROM $table_name 
         WHERE seller_order_id = %d AND buyer_order_id = %d",
        $seller_id, $buyer_id
    ));
    
    // Delete the specific chunk
    $wpdb->delete($table_name, [
        'seller_order_id' => $seller_id,
        'buyer_order_id' => $buyer_id
    ]);
    
    // Return the amount to seller's available balance
    if ($chunk_amount > 0) {
        buytap_atomic_give_back_to_seller($seller_id, (float)$chunk_amount);
    }
    
    return (float)$chunk_amount;
}

/**
 * Check if seller has other active buyers (IMPROVED)
 */
function buytap_seller_has_other_buyers($seller_id, $exclude_buyer_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'buytap_chunks';
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name 
         WHERE seller_order_id = %d 
         AND buyer_order_id != %d
         AND status NOT IN ('Revoked', 'Received')",
        $seller_id, $exclude_buyer_id
    ));
    
    return $count > 0;
}

/**
 * Get seller's remaining balance properly
 */
function buytap_get_seller_remaining_balance($seller_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'buytap_chunks';
    
    // Calculate total allocated amount from chunks
    $allocated = (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount), 0) 
         FROM $table_name 
         WHERE seller_order_id = %d 
         AND status NOT IN ('Revoked')",
        $seller_id
    ));
    
    // Get seller's expected amount
    $expected = (float) get_post_meta($seller_id, 'expected_amount', true);
    if ($expected <= 0) {
        $expected = (float) get_post_meta($seller_id, 'amount_to_make', true);
    }
    
    return max(0, $expected - $allocated);
}
/**
 * Check if the main order system plugin is active and show notice if not
 */
add_action('admin_notices', function() {
    if (!is_plugin_active('buytap-order-system/buytap-order-system.php')) {
        echo '<div class="notice notice-warning"><p>';
        echo 'BuyTap - Revoked Orders Admin plugin works best with the main BuyTap Order System plugin activated.';
        echo '</p></div>';
    }
});
