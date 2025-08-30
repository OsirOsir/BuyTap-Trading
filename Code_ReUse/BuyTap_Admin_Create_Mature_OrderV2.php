<?php
/**
 * Plugin Name: BuyTap Admin: Create Mature Orders
 * Description: Adds an admin screen to create Matured seller orders for any user and auto-pair them immediately. Designed to work with the "BuyTap Order System" plugin.
 * Version: 1.0.0
 * Author: Philip Osir
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit; // Safety: no direct access

// Dependency check
function buytap_admin_cm_dependencies_ok() {
    return post_type_exists('buytap_order');
}
function buytap_admin_cm_pairer_available() {
    return function_exists('buytap_pair_orders');
}

// Add submenu under BuyTap Orders CPT
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=buytap_order',
        __('Create Mature Order', 'buytap-admin-cm'),
        __('Create Mature Order', 'buytap-admin-cm'),
        'manage_options',
        'buytap-create-mature-order',
        'buytap_admin_cm_render_page'
    );
});

// Render admin page
function buytap_admin_cm_render_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have permission to access this page.', 'buytap-admin-cm'));
    }

    if (!buytap_admin_cm_dependencies_ok()) {
        echo '<div class="notice notice-error"><p><strong>BuyTap Admin:</strong> The required post type <code>buytap_order</code> was not found. Please activate the "BuyTap Order System" plugin first.</p></div>';
        return;
    }
    if (!buytap_admin_cm_pairer_available()) {
        echo '<div class="notice notice-warning"><p><strong>Heads up:</strong> The pairing function <code>buytap_pair_orders()</code> was not found. Orders will be created but not auto-paired until that plugin is active.</p></div>';
    }

    // Handle form submission
   // Handle form submission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['buytap_admin_cm_nonce']) &&
    wp_verify_nonce($_POST['buytap_admin_cm_nonce'], 'buytap_admin_cm_create')
) {
    $user_id  = intval($_POST['user_id'] ?? 0);
    $amount   = floatval($_POST['amount'] ?? 0);       // This is the amount the seller should RECEIVE now
    $notes    = sanitize_text_field($_POST['notes'] ?? '');
    $duration = intval($_POST['duration'] ?? 0);

    // Optional: store the “original plan” for context only
    $profit_pct = ($duration === 4) ? 30 : (($duration === 8) ? 65 : (($duration === 12) ? 95 : 0));

    if ($user_id <= 0 || $amount <= 0) {
        echo '<div class="notice notice-error"><p>❌ Please select a valid user and enter a positive amount.</p></div>';
    } else {

        // Create the post
        $order_id = wp_insert_post([
            'post_type'   => 'buytap_order',
            'post_status' => 'publish',
            'post_author' => $user_id,
            'post_title'  => sprintf('Matured (Admin) - %s', current_time('mysql')),
        ]);

        if (is_wp_error($order_id) || !$order_id) {
            echo '<div class="notice notice-error"><p>❌ Failed to create order. ' . esc_html(is_wp_error($order_id) ? $order_id->get_error_message() : '') . '</p></div>';
        } else {
            // Human title "Order #ID"
            wp_update_post(['ID' => $order_id, 'post_title' => 'Order #'.$order_id]);

            // Core status
            update_post_meta($order_id, 'status', 'Matured');
            update_post_meta($order_id, 'sub_status', 'Waiting to be Paired');
            update_post_meta($order_id, 'is_paired', 'no');

            // Descriptive info
            update_post_meta($order_id, 'order_date', current_time('mysql'));
            $detail = sprintf(
                '%s tokens matured (%d%% plan in %d days). %s',
                number_format_i18n($amount, 0),
                (int)$profit_pct,
                (int)$duration,
                $notes ? 'Note: '.$notes : ''
            );
            update_post_meta($order_id, 'order_details', $detail);

            // ✅ Seller-side financial metas your system actually uses:
            // "expected_amount" = what the seller should receive in this payout
            // "remaining_to_receive" tracks the unreceived balance
            // Atomic seller counter so pairing can reserve safely
            update_post_meta($order_id, 'expected_amount', $amount);
            update_post_meta($order_id, 'remaining_to_receive', $amount);
            update_post_meta($order_id, 'amount_to_make', $amount); // legacy compatibility
            update_post_meta($order_id, 'seller_remaining', $amount); // atomic counter used by pairing

            // (Optional) Store a display-only backup so the UI can show a non-zero "Amount Bought"
            // if the order didn’t originate from a buyer flow.
            update_post_meta($order_id, 'amount_bought', $amount);

            // Run pairing now if available
            if (function_exists('buytap_pair_orders')) {
                buytap_pair_orders($order_id);
            }

            $edit_link = get_edit_post_link($order_id, '');
            echo '<div class="notice notice-success is-dismissible"><p>✅ Mature order created successfully (ID: ' . intval($order_id) . '). ' . ($edit_link ? '<a href="' . esc_url($edit_link) . '">Edit Order</a>' : '') . '</p></div>';
        }
    }
}
    ?>

    <div class="wrap">
        <h1><?php esc_html_e('Create Mature Order', 'buytap-admin-cm'); ?></h1>
        <p>Use this tool to create a <strong>Matured</strong> seller order for any user. After creation, pairing will run immediately (if available).</p>

        <form method="post" action="">
            <?php wp_nonce_field('buytap_admin_cm_create', 'buytap_admin_cm_nonce'); ?>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row"><label for="user_id"><?php esc_html_e('User', 'buytap-admin-cm'); ?></label></th>
                        <td>
                            <select name="user_id" id="user_id" style="width: 400px;" required></select>
                            <p class="description">Start typing a username, email, display name, or phone number to search.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="amount"><?php esc_html_e('Amount to Make', 'buytap-admin-cm'); ?></label></th>
                        <td>
                            <input type="number" step="0.01" min="1" class="regular-text" name="amount" id="amount" required placeholder="e.g. 5000">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="duration"><?php esc_html_e('Duration', 'buytap-admin-cm'); ?></label></th>
                        <td>
                            <select name="duration" id="duration" required>
                                <option value="">— Select Duration —</option>
                                <option value="4">4 Days (30% Profit)</option>
                                <option value="8">8 Days (65% Profit)</option>
                                <option value="12">12 Days (95% Profit)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="notes"><?php esc_html_e('Notes (optional)', 'buytap-admin-cm'); ?></label></th>
                        <td>
                            <input type="text" class="regular-text" name="notes" id="notes" placeholder="Any internal note">
                        </td>
                    </tr>
                </tbody>
            </table>
            <?php submit_button(__('Create Mature Order', 'buytap-admin-cm')); ?>
        </form>
    </div>
    <?php
}

// Enqueue Select2 and AJAX search
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'buytap_order_page_buytap-create-mature-order') return;

    wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
    wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js', ['jquery'], null, true);

    wp_add_inline_script('select2', "
        jQuery(function($) {
            $('#user_id').select2({
                ajax: {
                    url: '" . admin_url('admin-ajax.php') . "',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return { action: 'buytap_admin_user_search', q: params.term };
                    },
                    processResults: function(data) {
                        return { results: data };
                    },
                    cache: true
                },
                minimumInputLength: 2,
                placeholder: 'Search for a user...',
            });
        });
    ");
});

// AJAX search handler
add_action('wp_ajax_buytap_admin_user_search', function() {
    if ( ! current_user_can('manage_options') ) wp_send_json([]);

    global $wpdb;
    $term = isset($_GET['q']) ? sanitize_text_field($_GET['q']) : '';

    if (strlen($term) < 2) wp_send_json([]);

    // Search including phone number stored under meta_key 'mobile_number'
    $users = $wpdb->get_results($wpdb->prepare("
        SELECT u.ID, u.user_login, u.user_email, u.display_name, pm.meta_value AS phone_number
        FROM {$wpdb->users} u
        LEFT JOIN {$wpdb->usermeta} pm 
            ON pm.user_id = u.ID AND pm.meta_key = 'mobile_number'
        WHERE u.user_login LIKE %s 
           OR u.user_email LIKE %s 
           OR u.display_name LIKE %s
           OR pm.meta_value LIKE %s
        GROUP BY u.ID
        LIMIT 20
    ", "%$term%", "%$term%", "%$term%", "%$term%"));

    $results = [];
    foreach ($users as $user) {
        $phone_display = $user->phone_number ? " | 📞 " . esc_html($user->phone_number) : "";
        $results[] = [
            'id'   => $user->ID,
            'text' => sprintf('%s (%s) - %s%s',
                $user->display_name,
                $user->user_login,
                $user->user_email,
                $phone_display
            )
        ];
    }

    wp_send_json($results);
});
