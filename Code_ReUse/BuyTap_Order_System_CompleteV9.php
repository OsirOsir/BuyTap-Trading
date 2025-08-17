<?php
/*
Plugin Name: BuyTap Order System
Description: Custom plugin to handle token purchases and order creation for BuyTap.
Version: 1.0
Author: Philip Osir
*/

// --- Simple logger (writes to wp-content/debug.log when WP_DEBUG_LOG is true)
if (!function_exists('buytap_log')) {
    function buytap_log($msg, $ctx = []) {
        if (!defined('WP_DEBUG_LOG') || !WP_DEBUG_LOG) return;
        $line = '[BuyTap] ' . $msg;
        if (!empty($ctx)) $line .= ' ' . wp_json_encode($ctx);
        error_log($line);
    }
}


// =============================================
// CUSTOM POST TYPE SETUP - ORDER MANAGEMENT
// =============================================

/**
 * Registers the custom post type for handling orders in the backend dashboard
 * This creates the 'Orders' section in WordPress admin
 */
add_action('init', function () {
    register_post_type('buytap_order', array(
        'labels' => array(
            'name' => 'Orders',
            'singular_name' => 'Order',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Order',
            'edit_item' => 'Edit Order',
            'new_item' => 'New Order',
            'view_item' => 'View Order',
            'search_items' => 'Search Orders',
            'not_found' => 'No orders found',
        ),
        'public' => true,
        'publicly_queryable' => false,
        'has_archive' => false,
        'show_in_menu' => true,
        'rewrite' => false,
        'menu_position' => 25,
        'menu_icon' => 'dashicons-cart',
        'supports' => array('title', 'author'),
        'capability_type' => 'post',
    ));
});

// =============================================
// ORDER META BOXES - ADMIN INTERFACE
// =============================================

/**
 * Adds a meta box to the order edit screen in the admin
 * This displays all order-related fields in a single box
 */
add_action('add_meta_boxes', 'buytap_add_order_meta_boxes');
function buytap_add_order_meta_boxes() {
    add_meta_box('order_details_meta', 'Order Details', 'buytap_render_order_meta_box', 'buytap_order', 'normal', 'default');
}

/**
 * Renders input fields for each order-related meta field (admin view)
 * Organized by order status for better clarity
 */
function buytap_render_order_meta_box($post) {
    $fields = [
        // Pending / Shared fields
        'order_date' => 'Order Date',
        'order_details' => 'Order Details',
        'status' => 'Status',
        'amount_to_make' => 'Amount to Make',
        'seller_name' => 'Seller Name',
        'seller_number' => 'Seller Number',
        'amount_to_send' => 'Amount to Send',
        'time_left' => 'Time Left',
        'sub_status' => 'Sub Status',

        // Active order fields
        'date_purchased' => 'Date Purchased',
        'expected_amount' => 'Expected Amount',
        'running_status' => 'Running Status',
        'time_remaining' => 'Time Remaining',

        // Paired to Sell (New Buyer Info)
        'paired_buyer_name' => 'Buyer Name',
        'paired_buyer_number' => 'Buyer MPESA Number',
        'amount_to_receive' => 'Amount to Receive',
        'payment_status' => 'Payment Status',
        'seller_action_note' => 'Action (e.g. Click to Sell)',

        // Closed order fields
        'date_completed' => 'Date Completed',
        'final_amount_paid' => 'Final Amount Paid',

        // Revoked order fields
        'date_revoked' => 'Date Revoked',
        'revoked_reason' => 'Revoked Reason',
    ];
    
    // Display each field as a text input
    foreach ($fields as $key => $label) {
        $value = esc_attr(get_post_meta($post->ID, $key, true));
        echo "<p><label for='$key'><strong>$label:</strong></label><br/>";
        echo "<input type='text' id='$key' name='$key' value='$value' style='width:100%' /></p>";
    }
}

/**
 * Saves all custom field values entered by the admin into post meta
 * Handles data sanitization before saving
 */
add_action('save_post', 'buytap_save_order_meta');
function buytap_save_order_meta($post_id) {
   $keys = [
        // Pending / Shared
        'order_date', 'order_details', 'status', 'amount_to_make',
        'seller_name', 'seller_number', 'amount_to_send', 'time_left',
        'sub_status',

        // Active
        'date_purchased', 'expected_amount', 'running_status', 'time_remaining',

        // Paired to Sell (New Buyer Info)
        'paired_buyer_name', 'paired_buyer_number', 'amount_to_receive',
        'payment_status', 'seller_action_note',

        // Closed
        'date_completed', 'final_amount_paid',

        // Revoked
        'date_revoked', 'revoked_reason'
    ];

    foreach ($keys as $key) {
        if (array_key_exists($key, $_POST)) {
            update_post_meta($post_id, $key, sanitize_text_field($_POST[$key]));
        }
    }
}

// =============================================
// ELEMENTOR FORM INTEGRATION - ORDER CREATION
// =============================================

/**
 * Handles form submission from Elementor Pro Forms named "Purchase_Form"
 * Creates new orders when users submit the purchase form
 */
add_action('elementor_pro/forms/new_record', function($record, $handler) {
    // Make sure it's our specific form
    $form_name = $record->get_form_settings('form_name');
    if ($form_name !== 'Purchase_Form') return;
    
    // Get form fields
    $raw_fields = $record->get('fields');

    // Get submitted values
    $amount = $raw_fields['buytap_amount']['value'];
    $duration = $raw_fields['buytap_duration']['value'];

    // Calculate return based on duration selection
    $duration_days = 0;
    $profit_percent = 0;

    if ($duration === '30% in 4 Days') {
        $duration_days = 4;
        $profit_percent = 30;
    } elseif ($duration === '65% in 8 Days') {
        $duration_days = 8;
        $profit_percent = 65;
    } elseif ($duration === '95% in 12 Days') {
        $duration_days = 12;
        $profit_percent = 95;
    }

    // Calculate expected return amount (without bonus)
    $expected_return = $amount + ($amount * $profit_percent / 100);

    // Create order post 
    $order_id = wp_insert_post([
        'post_type' => 'buytap_order',
        'post_title' => 'Order #' . get_current_user_id() . '-' . current_time('YmdHis'),
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ]);

    // Save order metadata (without bonus)
    update_post_meta($order_id, 'order_date', current_time('Y-m-d H:i'));
    update_post_meta($order_id, 'order_details', $amount . ' for ' . $duration);
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'amount_to_make', $expected_return);
    update_post_meta($order_id, 'expected_amount', $expected_return);
    update_post_meta($order_id, 'remaining_to_receive', $expected_return);
    update_post_meta($order_id, 'seller_name', '');
    update_post_meta($order_id, 'seller_number', '');
    update_post_meta($order_id, 'amount_to_send', $amount);
    update_post_meta($order_id, 'sub_status', 'Pending');
    update_post_meta($order_id, 'remaining_to_send', $amount);
    update_post_meta($order_id, 'is_paired', 'no');
    
	// Apply any pending referral bonuses to THIS new order (adds to expected)
	buytap_apply_pending_bonuses_to_new_order($order_id, get_current_user_id(), (float)$amount, (int)$profit_percent);

    // --- REFERRAL BONUS LOGIC (CREDIT UPLINE) ---
    $buyer_id = get_current_user_id();
    $promo_code = get_user_meta($buyer_id, 'promo_code', true);

    if ($promo_code) {
        $upline_user = get_users([
            'meta_key' => 'my_referral_code',
            'meta_value' => sanitize_text_field($promo_code),
            'number' => 1,
            'fields' => 'ID'
        ]);

        if (!empty($upline_user)) {
            $upline_id = $upline_user[0];
            $bonus = round($amount * 0.03, 2); // 3% bonus

            // Add to upline's bonus history as pending
            $bonus_history = get_user_meta($upline_id, 'referral_bonus_history', true) ?: [];
            
            $bonus_history[] = [
                'date' => current_time('mysql'),
                'buyer_id' => $buyer_id,
                'buyer_name' => get_userdata($buyer_id)->display_name,
                'order_id' => $order_id,
                'purchase_amt' => $amount,
                'bonus_amt' => $bonus,
                'status' => 'pending',
                'applied_to_order' => 0 // Will be set when upline uses it
            ];
            
            update_user_meta($upline_id, 'referral_bonus_history', $bonus_history);
        }
    }
    
    // Deduct tokens from pool
    buytap_reduce_tokens_on_purchase((int)$amount);
    
    // Run auto pairing after buyer order creation
    buytap_pair_orders($order_id);
}, 10, 2);

//Shortcode For  My  Referal Bonus History
add_shortcode('my_referral_bonus_history', function() {
    if (!is_user_logged_in()) {
        return "<p>Please log in to view your referral bonus history.</p>";
    }

    $user_id = get_current_user_id();
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true);

    if (empty($bonus_history) || !is_array($bonus_history)) {
        return "<p>No referral bonuses earned yet.</p>";
    }

    // Sort by date (newest first)
    usort($bonus_history, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    ob_start();
    ?>
    <div class="buytap-orders-table buytap-bonus-table">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Buyer</th>
                    <th>Purchase Amount</th>
                    <th>Bonus Earned</th>
                    <th>Order ID</th>
                    <th>Status</th>
                    <th>Applied To</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bonus_history as $bonus): ?>
                    <tr>
                        <td><?= esc_html(date('Y-m-d H:i', strtotime($bonus['date']))) ?></td>
                        <td><?= esc_html($bonus['buyer_name']) ?></td>
                        <td>Ksh <?= number_format($bonus['purchase_amt'], 2) ?></td>
                        <td style="color:green;">+Ksh <?= number_format($bonus['bonus_amt'], 2) ?></td>
                        <td>#<?= intval($bonus['order_id']) ?></td>
                        <td>
                            <?php if ($bonus['status'] === 'applied'): ?>
                                <span style="color:green;">✔ Applied</span>
                            <?php else: ?>
                                <span style="color:orange;">Pending</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($bonus['applied_to_order'])): ?>
                                Order #<?= $bonus['applied_to_order'] ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    return ob_get_clean();
});
		
// Apply all pending referral bonuses to a just-created order.
// Must be called AFTER base metas (amount_to_send, expected_amount, etc.) are saved.
function buytap_apply_pending_bonuses_to_new_order($order_id, $user_id, $amount, $profit_percent) {
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true);
    if (empty($bonus_history) || !is_array($bonus_history)) return;

    $total_bonus = 0.0;
    $applied_bonuses = [];

    foreach ($bonus_history as &$bonus) {
        if (!isset($bonus['status']) || $bonus['status'] !== 'pending') continue;
        $total_bonus += (float)$bonus['bonus_amt'];
        $bonus['status'] = 'applied';
        $bonus['applied_to_order'] = $order_id;
        $applied_bonuses[] = $bonus;
    }
    unset($bonus); // break reference

    if ($total_bonus <= 0) return;

    // Save updated history
    update_user_meta($user_id, 'referral_bonus_history', $bonus_history);

    // Base expected = tokens + profit
    $expected_without_bonus = (float)$amount * (1 + ((float)$profit_percent / 100.0));
    $new_expected = $expected_without_bonus + (float)$total_bonus;

    // Update order metas to reflect bonus
    update_post_meta($order_id, 'expected_amount', $new_expected);
    update_post_meta($order_id, 'remaining_to_receive', $new_expected);
    update_post_meta($order_id, 'amount_to_make', $new_expected);
    update_post_meta($order_id, 'applied_referral_bonus', (float)$total_bonus);
    update_post_meta($order_id, '_applied_bonuses', $applied_bonuses);

    buytap_log('Applied referral bonuses to new order', [
        'order_id' => $order_id,
        'user_id'  => $user_id,
        'base_amt' => (float)$amount,
        'profit_percent' => (float)$profit_percent,
        'bonus_total' => (float)$total_bonus,
        'expected_with_bonus' => $new_expected
    ]);
}


//verification function (to check for and fix any discrepancies):
function buytap_verify_bonus_application($user_id) {
    $bonus_history = get_user_meta($user_id, 'referral_bonus_history', true) ?: [];
    $needs_update = false;
    
    foreach ($bonus_history as &$bonus) {
        if ($bonus['status'] === 'applied') {
            // Verify the bonus was actually added to an order
            $order = get_post($bonus['applied_to_order']);
            if (!$order || $order->post_type !== 'buytap_order') {
                $bonus['status'] = 'pending';
                unset($bonus['applied_to_order']);
                $needs_update = true;
                continue;
            }
            
            // Get the recorded bonus amount from the order
            $recorded_bonus = (float) get_post_meta($order->ID, 'applied_referral_bonus', true);
            $expected_amount = (float) get_post_meta($order->ID, 'expected_amount', true);
            $base_amount = (float) get_post_meta($order->ID, 'amount_to_send', true);
            
            // Calculate what the expected amount should be based on order details
            $order_details = get_post_meta($order->ID, 'order_details', true);
            $profit_percent = 0;
            
            if (strpos($order_details, '30% in 4 Days') !== false) {
                $profit_percent = 30;
            } elseif (strpos($order_details, '65% in 8 Days') !== false) {
                $profit_percent = 65;
            } elseif (strpos($order_details, '95% in 12 Days') !== false) {
                $profit_percent = 95;
            } else {
                $profit_percent = 30; // Default fallback
            }
            
            $expected_without_bonus = $base_amount * (1 + ($profit_percent / 100));
            $expected_with_bonus = $expected_without_bonus + $bonus['bonus_amt'];
            
            // Check if the bonus was properly applied
            $bonus_correct = (
                abs($recorded_bonus - $bonus['bonus_amt']) < 0.01 && 
                abs($expected_amount - $expected_with_bonus) < 0.01
            );
            
            if (!$bonus_correct) {
                // Bonus wasn't properly applied
                $bonus['status'] = 'pending';
                unset($bonus['applied_to_order']);
                $needs_update = true;
                
                // Reset the order's expected amount if it was incorrect
                update_post_meta($order->ID, 'expected_amount', $expected_without_bonus);
                update_post_meta($order->ID, 'remaining_to_receive', $expected_without_bonus);
                update_post_meta($order->ID, 'applied_referral_bonus', 0);
            }
        }
    }
    
    if ($needs_update) {
        update_user_meta($user_id, 'referral_bonus_history', $bonus_history);
    }
    
    return $needs_update;
}

// Add this to your admin tools or run periodically

// =============================================
// TOKEN POOL MANAGEMENT SYSTEM
// =============================================

/**
 * Set initial token balance (run once or manually via admin)
 * @param int $amount Initial token amount (default: 200,000)
 */
function buytap_set_initial_token_pool($amount = 200000) {
    update_option('buytap_total_tokens', $amount);
}

/**
 * Reduce tokens when buyer makes a purchase
 * @param int $amount Amount to deduct from token pool
 */
function buytap_reduce_tokens_on_purchase($amount) {
    $current = (int) get_option('buytap_total_tokens', 0);
    $new_total = max($current - $amount, 0);
    update_option('buytap_total_tokens', $new_total);
}

/**
 * Add tokens + profit when order matures
 * @param int $amount Base amount
 * @param int $profit_percent Profit percentage to add
 */
function buytap_add_tokens_on_maturity($amount, $profit_percent) {
    $profit = ($profit_percent / 100) * $amount;
    $add_back = $amount + $profit;
    $current = (int) get_option('buytap_total_tokens', 0);
    update_option('buytap_total_tokens', $current + $add_back);
}

/**
 * Returns the current available tokens
 * @return int Current token balance
 */
function buytap_get_token_balance() {
    return (int) get_option('buytap_total_tokens', 0);
}

// =============================================
// FRONT-END DISPLAY SHORTCODES
// =============================================

/**
 * Shortcode to display available tokens
 * Usage: [buytap_tokens]
 */
function buytap_display_available_tokens_shortcode() {
    $available = buytap_get_token_balance();
    $ajax_url = admin_url('admin-ajax.php');

    return '<div id="available-tokens" 
                 class="buytap-token-box" 
                 data-ajax-url="' . esc_url($ajax_url) . '">
                <strong>Ksh </strong> ' . number_format($available) . '
            </div>';
}
add_shortcode('buytap_tokens', 'buytap_display_available_tokens_shortcode');

// =============================================
// ADMIN TOKEN POOL MANAGEMENT
// =============================================

/**
 * Adds token pool management page to admin menu
 */
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=buytap_order',
        'Token Pool Manager',
        'Token Pool',
        'manage_options',
        'buytap-token-pool',
        'buytap_render_token_pool_page'
    );
});

/**
 * Renders the token pool management page in admin
 * Allows admins to manually adjust token pool amount
 */
function buytap_render_token_pool_page() {
    // Token pool update handling
    if (isset($_POST['buytap_token_pool_submit']) && current_user_can('manage_options')) {
        $new_value = (int) sanitize_text_field($_POST['new_token_value']);
        update_option('buytap_total_tokens', $new_value);
        echo '<div class="updated"><p><strong>Token Pool Updated to: ' . number_format($new_value) . '</strong></p></div>';
    }

    $current = buytap_get_token_balance();

    echo '<div class="wrap">';
    echo '<h1>BuyTap Token Pool</h1>';
    echo '<p><strong>Current Available Tokens:</strong> ' . number_format($current) . '</p>';
    echo '<form method="post">';
    echo '<label for="new_token_value"><strong>Set New Token Pool:</strong></label><br>';
    echo '<input type="number" name="new_token_value" id="new_token_value" value="' . esc_attr($current) . '" required>';
    echo '<br><br>';
    echo '<input type="submit" name="buytap_token_pool_submit" class="button button-primary" value="Update Token Pool">';
    echo '</form>';

    // =========================================
    // ADD THE BONUS VERIFICATION SECTION HERE
    // =========================================
    echo '<h2 style="margin-top:30px;">Referral Bonus Verification</h2>';
    echo '<form method="post" style="margin-bottom:20px;">';
    wp_nonce_field('buytap_verify_bonuses', 'buytap_bonus_nonce');
    echo '<input type="hidden" name="verify_bonuses" value="1">';
    echo '<input type="submit" class="button button-secondary" value="Verify All Bonus Applications">';
    echo '</form>';

    if (isset($_POST['verify_bonuses']) && current_user_can('manage_options')) {
        check_admin_referer('buytap_verify_bonuses', 'buytap_bonus_nonce');
        
        $users = get_users([
            'meta_key' => 'referral_bonus_history',
            'fields' => 'ID'
        ]);
        
        $fixed = 0;
        foreach ($users as $user_id) {
            if (buytap_verify_bonus_application($user_id)) {
                $fixed++;
            }
        }
        
        echo '<div class="notice notice-success"><p>Verified bonuses for '.$fixed.' users. ';
        echo '<a href="#" onclick="jQuery(this).parent().remove();">Dismiss</a></p></div>';
    }
    // =========================================

    echo '</div>'; // Close the wrap div
}

// =============================================
// ORDER STATUS SHORTCODES - FRONTEND VIEWS
// =============================================

/**
 * Pending Orders Tab - Displays orders waiting to be paired
 */
add_shortcode('buytap_pending_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => ['Pending', 'paired'],
                'compare' => 'IN'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];
    $orders = get_posts($args);

    ob_start();
    ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
                    <tr><th>Order Date</th><th>Order Details</th><th>Status</th><th>Amount to Make</th></tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $order):
                    $id = $order->ID;
                    $order_date = esc_html(get_post_meta($id, 'order_date', true));
                    $details = esc_html(get_post_meta($id, 'order_details', true));
                    $status = esc_html(get_post_meta($id, 'status', true));
                    $expected_return = number_format(get_post_meta($id, 'amount_to_make', true));
                    $sub_status = get_post_meta($id, 'sub_status', true);
                    ?>
                    <tr style='border-bottom:1px solid #ddd;'>
                        <td><?= $order_date ?></td>
                        <td><?= $details ?></td>
                        <td><?= $status ?></td>
                        <td><strong>Ksh</strong> <?= $expected_return ?></td>
                    </tr>
                    <?php if (in_array($status, ['Pending', 'paired'])):
								global $wpdb; 
								$chunks = buytap_buyer_chunks($id);
                    ?>
                    <tr class='paired-seller'>
                      <td colspan='4'>
                        <?php if ($chunks): ?>
                          <table class='paired-seller-table' style="width:100%;">
                            <tr>
                              <th>Seller's Name</th>
                              <th>Seller's Number</th>
                              <th>Amount to Send</th>
                              <th>Time Left</th>
                              <th>Status</th>
                            </tr>
                            <?php foreach ($chunks as $chunk):
                                $seller_id = (int) $chunk->seller_order_id;
                                $seller_user_id = (int) get_post_field('post_author', $seller_id);
                                $seller_name = get_the_author_meta('display_name', $seller_user_id);
                                $seller_number = get_user_meta($seller_user_id, 'mobile_number', true);
                                $pair_time = (int) $chunk->pair_time;
                                $chunk_status = (string) $chunk->status;
                            ?>
                            <tr>
                              <td><?= esc_html($seller_name) ?></td>
                              <td><?= esc_html($seller_number) ?></td>
                              <td>Ksh. <?= number_format((float)$chunk->amount) ?></td>
                              <td data-countdown="<?= esc_attr($pair_time + 3600) ?>">Loading...</td>
                              <td>
                                <?php if ($chunk_status === 'Received'): ?>
                                    <span class="badge-paid">✔ Received</span>
                                <?php elseif ($chunk_status === 'Payment Made'): ?>
                                    <span class="badge-paid">Payment Sent</span>
                                <?php else: ?>
                                    <button class="made-payment-btn" 
                                            data-chunk-id="<?= (int)$chunk->id ?>">Made Payment</button>
                                <?php endif; ?>
                              </td>
                            </tr>
                            <?php endforeach; ?>
                          </table>
                        <?php else: ?>
                          <em>Waiting to be paired...</em>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No pending orders found.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// PAYMENT CONFIRMATION SYSTEM
// =============================================

/**
 * REST API Endpoints for payment confirmation
 * Allows frontend to mark payments as made/received
 */
add_action('rest_api_init', function () {
    // Endpoint for marking entire order as paid
    register_rest_route('buytap/v1', '/mark-payment/', [
        'methods' => 'POST',
        'callback' => 'buytap_rest_mark_payment',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
    
    // Endpoint for marking individual chunks as paid
    register_rest_route('buytap/v1', '/chunk-made-payment/', [
        'methods'  => 'POST',
        'callback' => function(WP_REST_Request $request) {
            global $wpdb; 
            $t = buytap_chunks_table();
            $chunk_id = (int) ($request['chunk_id'] ?? 0);
			
            if ($chunk_id <= 0) return new WP_REST_Response(['success'=>false,'message'=>'Invalid chunk'], 400);

            $chunk = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $chunk_id));
            if (!$chunk) return new WP_REST_Response(['success'=>false,'message'=>'Not found'], 404);

            $buyer_order_id = (int) $chunk->buyer_order_id;
            

            $author_id = (int) get_post_field('post_author', $buyer_order_id);
            if ($author_id !== get_current_user_id()) {
                return new WP_REST_Response(['success'=>false,'message'=>'Unauthorized'], 403);
            }
			// Update chunk status
            $wpdb->update($t, ['status' => 'Payment Made'], ['id' => $chunk_id]);

            return new WP_REST_Response(['success'=>true], 200);
        },
        'permission_callback' => function () { return is_user_logged_in(); }
    ]);
});

/**
 * Handle Buyer Payment Confirmation and Activate Order with Maturity Countdown
 * @param WP_REST_Request $request REST API request object
 */
function buytap_rest_mark_payment(WP_REST_Request $request) {
    $order_id = intval($request['order_id']);
    $user_id = get_current_user_id();

    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
		// Silently return success to avoid front-end popup
		return new WP_REST_Response(['success' => true, 'message' => 'Order already handled'], 200);
	}

    $author_id = (int)get_post_field('post_author', $order_id);
    if ($author_id !== $user_id) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    update_post_meta($order_id, 'sub_status', 'Payment Made');
    update_post_meta($order_id, 'buyer_confirmed', 'yes');

    return new WP_REST_Response(['success' => true, 'message' => 'Payment marked and order activated'], 200);
}

/**
 * Enqueue frontend scripts and localize data for AJAX calls
 */
function buytap_enqueue_script_and_nonce() {
    // Use a real handle that prints in footer
    wp_register_script('buytap-front-js', includes_url('js/wp-util.min.js'), array('jquery'), null, true);
    wp_enqueue_script('buytap-front-js');

    wp_localize_script('buytap-front-js', 'buytapData', array(
        'nonce'                   => wp_create_nonce('wp_rest'),
        'rest_url'                => esc_url_raw(rest_url('buytap/v1/mark-payment/')),
        'rest_chunk_made_payment' => esc_url_raw(rest_url('buytap/v1/chunk-made-payment/')),
    ));
}
add_action('wp_enqueue_scripts', 'buytap_enqueue_script_and_nonce');


// =============================================
// COUNTDOWN TIMERS - FRONTEND JAVASCRIPT
// =============================================

/**
 * Create the Countdown Script (MM:SS Format) for the buyer to make payment 
 * Shows time remaining to complete payment after pairing
 */
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const countdownCells = document.querySelectorAll('[data-countdown]');
        countdownCells.forEach(cell => {
            const orderRow = cell.closest('tr');
            const statusElement = orderRow?.querySelector('.badge-paid, .made-payment-btn');

            // If payment already marked, skip countdown
            if (statusElement && statusElement.classList.contains('badge-paid')) {
                cell.textContent = '--';
                return;
            }

            const raw = parseInt(cell.dataset.countdown, 10); // expiry in UTC seconds
            if (!raw) { 
                cell.textContent = '--'; 
                return; 
            }

            const endTime = raw * 1000; // convert to ms

            function updateCountdown() {
                const now = Date.now();      // UTC ms
                const diff = endTime - now;  // ms

                if (diff <= 0) {
                    cell.textContent = '00:00';
                    return;
                }

                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                cell.textContent = 
                    `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;

                requestAnimationFrame(updateCountdown);
            }

            updateCountdown();
        });
    });
    </script>
    <?php
});

// =============================================
// ACTIVE ORDERS MANAGEMENT
// =============================================

/**
 * Active Orders Tab - Displays currently running investments
 */
add_shortcode('buytap_active_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => ['Active', 'Matured'],
                'compare' => 'IN'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $orders = get_posts($args);

    ob_start();
    ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
                    <tr>
                        <th>Date Purchased</th>
                        <th>Amount Bought</th>
                        <th>Expected Return</th>
                        <th>Time Remaining</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $id = $order->ID;
                        $date_purchased = esc_html(get_post_meta($id, 'date_purchased', true));
                        $amount_bought = (float) get_post_meta($id, 'amount_to_send', true);
                        $expected_return = (float) get_post_meta($id, 'expected_amount', true);
                        $time_remaining = (int)get_post_meta($id, 'time_remaining', true);

                        $status = get_post_meta($id, 'status', true);
                        $current_time = current_time('timestamp', true);

                        // Check if order has matured
                        if ($status === 'Active' && $time_remaining <= $current_time) {
                            update_post_meta($id, 'status', 'Matured');
                            $status = 'Matured';

                            // Add matured tokens back to pool if not already done
                            $already_returned = get_post_meta($id, 'returned_to_pool', true);
                            if ($already_returned !== 'yes') {
                                $expected = get_post_meta($id, 'expected_amount', true);
                                $expected = floatval($expected);
                                $current_pool = get_option('buytap_total_tokens', 0);
                                $new_pool = $current_pool + $expected;
                                update_option('buytap_total_tokens', $new_pool);
                                update_post_meta($id, 'returned_to_pool', 'yes');
                            }
                        }
                        ?>
                        <tr>
                            <td><?= $date_purchased ?></td>
                            <td>Ksh <?= number_format($amount_bought) ?></td>
                            <td>Ksh <?= number_format($expected_return) ?></td>
                            <td data-active-countdown="<?= $time_remaining ?>">
                                <?= $time_remaining <= $current_time ? 'Matured' : 'Loading...' ?>
                            </td>
                            <td>
                                <?php
//                             $current_time = current_time('timestamp');

							// If countdown is running, always show "Running"
							if ($time_remaining > $current_time) {
								echo '<span class="badge badge-running" style="background-color:green;color:white;">Running</span>';
							} else {
								if ($status === 'Matured') {
									echo '<span class="badge badge-waiting" style="background-color:orange;color:white;">Waiting to be Paired</span>';
								} elseif ($status === 'Closed') {
									echo '<span class="badge badge-complete" style="color:green;">✔ Completed</span>';
								} else {
									echo esc_html($status);
								}
							}
                                ?>
                            </td>
                        </tr>
                    <?php if ($status === 'Matured'):
                        $chunks = buytap_seller_chunks($id);
                    ?>
                    <tr class="buyer-details">
                      <td colspan="5">
                        <?php if ($chunks): ?>
                        <table class="buyer-info" style="width:100%; margin-top:10px; border-radius:8px; padding:10px;">
                          <thead>
                            <tr>
                              <th>Buyer Name</th>
                              <th>Buyer MPESA Number</th>
                              <th>Amount To Receive</th>
                              <th>Status</th>
                              <th>Action</th>
                            </tr>
                          </thead>
                          <tbody>
                          <?php foreach ($chunks as $chunk):
                              $buyer_id = (int) $chunk->buyer_order_id;
                              $buyer_user = get_userdata((int) get_post_field('post_author', $buyer_id));
                              $buyer_name = $buyer_user ? $buyer_user->display_name : '';
                              $buyer_number = get_user_meta($buyer_user->ID, 'mobile_number', true);
                          ?>
                            <tr>
                              <td><?= esc_html($buyer_name) ?></td>
                              <td><?= esc_html($buyer_number) ?></td>
                              <td>Ksh. <?= number_format((float)$chunk->amount) ?></td>
                              <td><?= esc_html($chunk->status) ?></td>
                              <td>
                                <?php if ($chunk->status !== 'Received'): ?>
                                  <button class="seller-mark-received" data-chunk-id="<?= (int)$chunk->id ?>">Mark as Received</button>
                                <?php else: ?>
                                  <span style="color:lightgreen;">✔ Received</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                          </tbody>
                        </table>
                        <?php else: ?>
                          <em>Waiting for buyer(s)...</em>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No active orders yet.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// PAYMENT RECEIVED CONFIRMATION HANDLERS
// =============================================

/**
 * AJAX handler for marking chunks as received by seller
 * Updates chunk status and checks if order should be activated/closed
 */
add_action('wp_ajax_buytap_chunk_mark_received', function () {
    if (!is_user_logged_in()) wp_send_json_error('Not logged in');

    global $wpdb; 
    $t = buytap_chunks_table();
    $chunk_id = isset($_POST['chunk_id']) ? (int) $_POST['chunk_id'] : 0;
    if ($chunk_id <= 0) wp_send_json_error('Invalid chunk');

    $chunk = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $chunk_id));
    if (!$chunk) wp_send_json_error('Chunk not found');

    $seller_order_id = (int) $chunk->seller_order_id;
    if (get_post_type($seller_order_id) !== 'buytap_order') wp_send_json_error('Invalid seller order');

    $seller_user_id = (int) get_post_field('post_author', $seller_order_id);
    if ($seller_user_id !== get_current_user_id()) wp_send_json_error('You are not authorized');

    // 1) Mark chunk received
    $wpdb->update($t, ['status' => 'Received'], ['id' => $chunk_id]);
    buytap_log('chunk received', [
        'chunk_id'        => $chunk_id,
        'buyer_order_id'  => (int) $chunk->buyer_order_id,
        'seller_order_id' => $seller_order_id
    ]);

    // 2) Re-sync both sides so remaining_* reflect reality
    $buyer_order_id = (int) $chunk->buyer_order_id;
    $buyer_rem   = max(0, buytap_buyer_remaining($buyer_order_id));
    $seller_rem  = max(0, buytap_seller_remaining($seller_order_id));
    update_post_meta($buyer_order_id,  'remaining_to_send',    $buyer_rem);
    update_post_meta($seller_order_id, 'remaining_to_receive', $seller_rem);
    buytap_log('post-receive balances', [
        'buyer_remaining'  => $buyer_rem,
        'seller_remaining' => $seller_rem
    ]);

    // 3) Try to activate buyer cleanly (sets date/time/status/etc.)
    buytap_activate_buyer_if_complete($buyer_order_id);

    // 4) Close seller if fully funded
    buytap_close_seller_if_funded($seller_order_id);

    wp_send_json_success('Marked as received');
});



/**
 * JavaScript to handle seller "Mark as Received" button clicks
 * Sends AJAX request and updates UI on success
 */
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      document.querySelectorAll('.seller-mark-received').forEach(button => {
        button.addEventListener('click', function () {
          if (!confirm("Are you sure you've received this payment?")) return;

          const btn = this;
          const chunkId = btn.dataset.chunkId;
          const row = btn.closest('tr');
          const statusCell = btn.closest('td')?.previousElementSibling;

          // Instant UI (optimistic)
          if (statusCell) statusCell.textContent = 'Received';
          btn.outerHTML = '<span style="color:lightgreen;font-weight:600;">✔ Received</span>';
          if (row) { row.style.transition = 'background .35s ease'; row.style.background = '#eaf9ea'; }

          // Fire request in background
          fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              action: 'buytap_chunk_mark_received',
              chunk_id: chunkId
            })
          })
          .then(res => res.json())
          .then(data => {
            if (!data.success) {
              alert('❌ ' + (data.data || 'Error saving. Please refresh and try again.'));
            }
          })
          .catch(() => {
            alert('Network error. The status may not have saved — please refresh and try again.');
          });
        });
      });
    });
    </script>
<?php });


/**
 * JavaScript to handle buyer "Made Payment" button clicks
 * Uses REST API to mark payment as made
 */
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return; ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.made-payment-btn').forEach(btn => {
            btn.addEventListener('click', async function () {
                if (!confirm('Confirm you have sent this payment.')) return;
                
                const chunkId = this.dataset.chunkId;
                this.disabled = true;
                this.textContent = 'Processing...';
                
                try {
                    const response = await fetch(buytapData.rest_chunk_made_payment, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': buytapData.nonce
                        },
                        body: JSON.stringify({ chunk_id: chunkId })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success || (data.message && data.message.toLowerCase().includes('invalid order'))) {
                        // Always treat "Invalid order" as success, even with different formatting
                        this.outerHTML = '<span class="badge-paid">Payment Sent</span>';
                    }
                    // No else block → no popup ever
                } catch (error) {
                    // Optional: remove this alert too if you don’t want network error messages
                    console.error('Network error:', error);
                }
            });
        });
    });
    </script>
    <?php
});

// =============================================
// ACTIVE ORDER COUNTDOWN TIMER
// =============================================

/**
 * JavaScript for active order countdown (Days, Hours, Minutes, Seconds)
 * Shows time remaining until order matures
 */
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const activeTimers = document.querySelectorAll('[data-active-countdown]');
        activeTimers.forEach(cell => {
            const expiry = parseInt(cell.dataset.activeCountdown, 10);
            const timer = setInterval(() => {
                const now = Math.floor(Date.now() / 1000);
                const diff = expiry - now;

                if (diff <= 0) {
                    cell.innerHTML = 'Matured';
                    clearInterval(timer);
                    return;
                }

                // Calculate days, hours, minutes, seconds remaining
                const days = Math.floor(diff / 86400);
                const hours = Math.floor((diff % 86400) / 3600);
                const minutes = Math.floor((diff % 3600) / 60);
                const seconds = diff % 60;

                cell.innerHTML = `${days}d ${hours}h ${minutes}m ${seconds}s`;
            }, 1000);
        });
    });
    </script>
    <?php
});

// =============================================
// ORDER MATURITY PROCESSING
// =============================================

/**
 * Check for matured orders and process them
 * Runs on wp_loaded hook to check orders that have reached maturity date
 */
add_action('wp_loaded', function () {
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Active'],
            ['key' => 'time_remaining', 'value' => current_time('timestamp', true), 'compare' => '<=']
        ]
    ];

    $orders = get_posts($args);

    foreach ($orders as $order) {
        $order_id = $order->ID;

        // Update order status to Matured
        update_post_meta($order_id, 'status', 'Matured');
        update_post_meta($order_id, 'sub_status', 'Waiting to be Paired');

		update_post_meta($order_id, 'remaining_to_receive', (float) get_post_meta($order_id, 'expected_amount', true));
		update_post_meta($order_id, 'is_paired', 'no');

		// Ensure atomic counter exists for this matured seller
		if ('' === get_post_meta($order_id, 'seller_remaining', true)) {
			$init = (float) get_post_meta($order_id, 'expected_amount', true);
			if ($init <= 0) $init = (float) get_post_meta($order_id, 'amount_to_make', true);
			update_post_meta($order_id, 'seller_remaining', $init);
		}

        // Run auto pairing for matured orders
       	buytap_pair_orders($order_id);
        
        // Add expected amount back to token pool if not already done
        $already_returned = get_post_meta($order_id, 'returned_to_pool', true);
        if ($already_returned !== 'yes') {
            $expected = (int) get_post_meta($order_id, 'expected_amount', true);
            $current_pool = (int) get_option('buytap_total_tokens', 0);
            $new_pool = $current_pool + $expected;

            update_option('buytap_total_tokens', $new_pool);
            update_post_meta($order_id, 'returned_to_pool', 'yes');
        }
    }
});
// =============================================
// IMPROVED ORDER PAIRING LOGIC
// =============================================

/**
 * Modified pairing logic to ensure full allocation
 * (Replace existing buytap_pair_orders function)
 */
function buytap_pair_orders($order_id) {
    $lock = buytap_pair_lock_acquire('global', 15);
    if (!$lock) return;

    try {
        $status = get_post_meta($order_id, 'status', true);
        if (!in_array($status, ['Pending', 'paired', 'Matured'], true)) return;

        $is_buyer = in_array($status, ['Pending', 'paired'], true);

        if ($is_buyer) {
            $remaining = (float) get_post_meta($order_id, 'remaining_to_send', true);
            if ($remaining <= 0) {
                $remaining = (float) get_post_meta($order_id, 'amount_to_send', true);
                update_post_meta($order_id, 'remaining_to_send', $remaining);
            }
            if ($remaining <= 0) return;
        } else {
            $remaining = (float) get_post_meta($order_id, 'remaining_to_receive', true);
            if ($remaining <= 0) {
                $remaining = (float) get_post_meta($order_id, 'expected_amount', true);
                if ($remaining <= 0) {
                    // legacy fallback
                    $remaining = (float) get_post_meta($order_id, 'amount_to_make', true);
                }
                update_post_meta($order_id, 'remaining_to_receive', $remaining);
            }
            if ($remaining <= 0) return;
        }

        $pair_args = [
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'meta_query'     => [
                [
                    'key'     => 'status',
                    'value'   => $is_buyer ? 'Matured' : ['Pending', 'paired'],
                    'compare' => $is_buyer ? '=' : 'IN'
                ],
                [
                    'key'     => $is_buyer ? 'remaining_to_receive' : 'remaining_to_send',
                    'value'   => 0,
                    'compare' => '>',
                    'type'    => 'NUMERIC'
                ]
            ],
            'orderby' => 'date',
            'order'   => 'ASC',
        ];
        $matches = get_posts($pair_args);

        foreach ($matches as $m) {
            $buyer_id  = $is_buyer ? $order_id : $m->ID;
            $seller_id = $is_buyer ? $m->ID    : $order_id;

            // ✅ Prevent self-pairing: skip if buyer and seller have same post_author
            if (get_post_field('post_author', $buyer_id) == get_post_field('post_author', $seller_id)) {
                continue;
            }

            if ($is_buyer) {
                if (buytap_buyer_remaining($order_id) <= 0) break;
                if (buytap_seller_remaining($m->ID) <= 0) continue;
                buytap_perform_pairing($order_id, $m->ID, 'auto');
            } else {
                if (buytap_seller_remaining($order_id) <= 0) break;
                if (buytap_buyer_remaining($m->ID) <= 0) continue;
                buytap_perform_pairing($m->ID, $order_id, 'auto');
            }
        }

        if ($is_buyer && buytap_buyer_remaining($order_id) <= 0) {
            update_post_meta($order_id, 'status', 'paired');
            update_post_meta($order_id, 'sub_status', 'Waiting for Payment');
            update_post_meta($order_id, 'is_paired', 'yes');
        }

    } finally {
        buytap_pair_lock_release($lock);
    }
}


// =============================================
// PLUGIN ACTIVATION - DATABASE SETUP
// =============================================

/**
 * Creates the chunks table on plugin activation
 * Stores information about order pairings and partial payments
 */

// Run on activation AND upgrades
register_activation_hook(__FILE__, 'buytap_install_or_upgrade_chunks');

function buytap_install_or_upgrade_chunks() {
    global $wpdb;
    $table_name = $wpdb->prefix . "buytap_chunks";
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        buyer_order_id BIGINT(20) UNSIGNED NOT NULL,
        seller_order_id BIGINT(20) UNSIGNED NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        status VARCHAR(20) DEFAULT 'Awaiting Payment',
        pair_time BIGINT(20) DEFAULT 0,
        PRIMARY KEY (id),
        INDEX buyer_idx (buyer_order_id),
        INDEX seller_idx (seller_order_id),
        UNIQUE KEY uniq_buyer_seller (buyer_order_id, seller_order_id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    update_option('buytap_chunks_schema_version', '2');
}

// Also run upgrade if plugin already active
add_action('plugins_loaded', function () {
    if (get_option('buytap_chunks_schema_version') !== '2') {
        buytap_install_or_upgrade_chunks();
    }
});

function buytap_pair_lock_acquire($key = 'pair', $ttl = 10) {
    $lock_key = "buytap_pair_lock_{$key}";
    if (get_transient($lock_key)) return false;
    set_transient($lock_key, 1, $ttl);
    return $lock_key;
}
function buytap_pair_lock_release($lock_key) {
    if ($lock_key) delete_transient($lock_key);
}

//Helpers (atomic reserve / give-back)
// --- Atomic seller capacity controls (prevents over-pairing)
function buytap_atomic_take_from_seller($seller_order_id, $requested) {
    global $wpdb;
    $requested = max(0.0, (float)$requested);
    if ($requested <= 0) return 0.0;

    $table = $wpdb->postmeta;
    $key   = 'seller_remaining';

    // Ensure the meta row exists
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$table} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $seller_order_id, $key
    ));
    if (!$exists) {
        $expected = (float) get_post_meta($seller_order_id, 'expected_amount', true);
        if ($expected <= 0) $expected = (float) get_post_meta($seller_order_id, 'amount_to_make', true);
        add_post_meta($seller_order_id, $key, $expected, true);
    }

    // Read current
    $current = (float) get_post_meta($seller_order_id, $key, true);
    if ($current <= 0) return 0.0;

    $take = min($requested, $current);

    // Atomic decrement: only succeed if enough remains
    $affected = $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET meta_value = CAST(meta_value AS DECIMAL(10,2)) - %f
         WHERE post_id=%d AND meta_key=%s
           AND CAST(meta_value AS DECIMAL(10,2)) >= %f",
        $take, $seller_order_id, $key, $take
    ));

    if ($affected === 1) return (float) $take;
    return 0.0; // lost the race
}

function buytap_atomic_give_back_to_seller($seller_order_id, $amount) {
    global $wpdb;
    $amount = max(0.0, (float)$amount);
    if ($amount <= 0) return;

    $table = $wpdb->postmeta;
    $key   = 'seller_remaining';

    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT meta_id FROM {$table} WHERE post_id=%d AND meta_key=%s LIMIT 1",
        $seller_order_id, $key
    ));
    if (!$exists) {
        add_post_meta($seller_order_id, $key, $amount, true);
        return;
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE {$table}
         SET meta_value = CAST(meta_value AS DECIMAL(10,2)) + %f
         WHERE post_id=%d AND meta_key=%s",
        $amount, $seller_order_id, $key
    ));
}



/**
 * Performs the actual pairing between buyer and seller orders
 * Creates chunks in the database to track partial fulfillment
 * @param int $buyer_order_id Buyer order ID
 * @param int $seller_order_id Seller order ID
 * @param string $initiator Who initiated the pairing
 * @return bool True if pairing succeeded, false otherwise
 */
function buytap_perform_pairing($buyer_order_id, $seller_order_id, $initiator) {
    global $wpdb;
    $table_name = buytap_chunks_table();

    // Fresh remaining
    $buyer_remaining = buytap_buyer_remaining($buyer_order_id);
    if ($buyer_remaining <= 0) return false;

    // Reserve capacity from the seller atomically (the key to stop overshoot)
    $chunk_amount = buytap_atomic_take_from_seller($seller_order_id, $buyer_remaining);
    if ($chunk_amount <= 0) return false;

    // Optional pre-check to avoid duplicate pairs (still handle rollback below)
    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name
         WHERE buyer_order_id = %d AND seller_order_id = %d
           AND status IN ('Awaiting Payment','Payment Made')",
        $buyer_order_id, $seller_order_id
    ));
    if ($existing > 0) {
        // give back reserved amount and skip
        buytap_atomic_give_back_to_seller($seller_order_id, $chunk_amount);
        return false;
    }

    // Create chunk with the reserved amount
    $ok = $wpdb->insert($table_name, [
        'buyer_order_id' => $buyer_order_id,
        'seller_order_id'=> $seller_order_id,
        'amount'         => $chunk_amount,
        'status'         => 'Awaiting Payment',
        'pair_time'      => current_time('timestamp', true),
    ]);

    if ($ok === false) {
        // Roll back the reservation if insert failed (e.g., unique key race)
        buytap_atomic_give_back_to_seller($seller_order_id, $chunk_amount);
        return false;
    }

    // Sync buyer & seller metas
    update_post_meta($buyer_order_id,  'remaining_to_send',    max(0, buytap_buyer_remaining($buyer_order_id)));
    $seller_counter = max(0, (float) get_post_meta($seller_order_id, 'seller_remaining', true));
    update_post_meta($seller_order_id, 'remaining_to_receive', $seller_counter);

    // Stamp buyer pair time for countdown
    update_post_meta($buyer_order_id, 'pair_time', current_time('timestamp', true));

    // If buyer fully matched now, set paired status
    if (buytap_buyer_remaining($buyer_order_id) <= 0) {
        update_post_meta($buyer_order_id, 'status', 'paired');
        update_post_meta($buyer_order_id, 'sub_status', 'Waiting for Payment');
        update_post_meta($buyer_order_id, 'is_paired', 'yes');
    }

    // If seller fully allocated now, flag it
    if (buytap_seller_remaining($seller_order_id) <= 0) {
        update_post_meta($seller_order_id, 'is_paired', 'yes');
    }

    return true;
}



/**
 * Scans for eligible buyers and sellers, then pairs them
 * Runs through all possible combinations to maximize matches
 */
function buytap_run_auto_pairing() {
    $lock = buytap_pair_lock_acquire('global', 15);
    if (!$lock) return;

    try {
        // Pair all eligible orders via the single guarded path
        $orders = get_posts([
            'post_type'      => 'buytap_order',
            'posts_per_page' => -1,
            'meta_query'     => [
                'relation' => 'OR',
                ['key' => 'status', 'value' => 'Pending'],
                ['key' => 'status', 'value' => 'paired'],
                ['key' => 'status', 'value' => 'Matured'],
            ]
        ]);

        foreach ($orders as $o) {
            buytap_pair_orders($o->ID);
        }
    } finally {
        buytap_pair_lock_release($lock);
    }
}


// =============================================
// ORDER STATUS CHANGE HOOKS
// =============================================

// /**
//  * Trigger pairing when orders are created/updated
//  */
// add_action('save_post_buytap_order', function($post_id, $post, $update) {
//     if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
//     if (!$update) return;
    
//     // Run pairing after post is saved
//     add_action('shutdown', function() use ($post_id) {
//         buytap_pair_orders($post_id);
//     });
// }, 10, 3);

/**
 * Trigger pairing when orders mature
 */
add_action('wp_loaded', function() {
    $matured_orders = get_posts([
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Matured'],
            ['key' => 'is_paired', 'value' => 'no'],
        ]
    ]);

    foreach ($matured_orders as $order) {
        buytap_pair_orders($order->ID);
    }
});

// =============================================
// CLOSED ORDERS VIEW
// =============================================

/**
 * Closed Orders Tab - Displays completed orders
 */
add_shortcode('buytap_closed_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $user_id = get_current_user_id();
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'author' => $user_id,
        'meta_query' => [
            [
                'key' => 'status',
                'value' => 'Closed'
            ]
        ],
        'orderby' => 'date',
        'order' => 'DESC'
    ];

    $orders = get_posts($args);

    ob_start(); ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
                    <tr>
                        <th>Date Purchased</th>
                        <th>Amount Bought</th>
                        <th>Amount Made</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order):
                        $id = $order->ID;
                        $date_purchased = esc_html(get_post_meta($id, 'date_purchased', true));
                        $amount_bought = number_format((float)get_post_meta($id, 'amount_to_send', true));
                        $amount_made = number_format((float)get_post_meta($id, 'expected_amount', true));
                        ?>
                        <tr style='border-bottom:1px solid #ddd;'>
                            <td><?= $date_purchased ?></td>
                            <td>Ksh <?= $amount_bought ?></td>
                            <td>Ksh <?= $amount_made ?></td>
                            <td><span class="badge-complete" style="color:green;">✔ Completed</span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No completed orders yet.</p>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
});

// =============================================
// REVOKED ORDERS MANAGEMENT
// =============================================

/**
 * Revoked Orders tab - Displays orders that were cancelled
 */
add_shortcode('buytap_revoked_orders', function () {
    if (!is_user_logged_in()) return '<p>Please log in to view your orders.</p>';

    $orders = get_posts([
        'post_type'      => 'buytap_order',
        'posts_per_page' => -1,
        'author'         => get_current_user_id(),
        'meta_query'     => [
            ['key' => 'status', 'value' => 'Revoked']
        ],
        'orderby' => 'date',
        'order'   => 'DESC'
    ]);

    ob_start(); ?>
    <div class="buytap-orders-table">
        <?php if ($orders): ?>
            <table style="width:100%; border-collapse:collapse; margin-top:10px;">
                <thead>
                    <tr>
                        <th>Order Date</th>
                        <th>Order Details</th>
                        <th>Reason</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?= esc_html(get_post_meta($o->ID, 'order_date', true)); ?></td>
                        <td><?= esc_html(get_post_meta($o->ID, 'order_details', true)); ?></td>
                        <td><?= esc_html(get_post_meta($o->ID, 'revoked_reason', true)); ?></td>
                        <td>Ksh <?= number_format((float) get_post_meta($o->ID, 'amount_to_send', true)); ?></td>
                        <td><span class="badge badge-danger">Revoked</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No revoked orders found.</p>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
});

/**
 * Auto-revoke: chunk-based
 * Only revoke unpaid chunks older than 1 hour, not whole orders blindly
 */
add_action('init', function () {
	if (!is_user_logged_in()) return; // optional, keeps it lighter
	
    global $wpdb; 
    $t = buytap_chunks_table();
    $now = current_time('timestamp', true);
    $deadline = $now - 3600;

    // Find overdue chunks that have not been paid
    $overdue_chunks = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t 
         WHERE status = 'Awaiting Payment' 
           AND pair_time <= %d", 
        $deadline
    ));

    foreach ($overdue_chunks as $chunk) {
        $buyer_id  = (int) $chunk->buyer_order_id;
        $seller_id = (int) $chunk->seller_order_id;
        $amount    = (float) $chunk->amount;

        // Delete/void the chunk
        $wpdb->delete($t, ['id' => (int)$chunk->id]);

		// Return reserved capacity to the seller (so they can be paired again)
		buytap_atomic_give_back_to_seller($seller_id, $amount);

        // Recompute remaining balances
        update_post_meta($buyer_id,  'remaining_to_send',    max(0, buytap_buyer_remaining($buyer_id)));
        update_post_meta($seller_id, 'remaining_to_receive', max(0, buytap_seller_remaining($seller_id)));

        // If buyer now has no active chunks and still owes → revoke order and refund unpaid remainder
        $buyer_left = buytap_buyer_remaining($buyer_id);
        $buyer_chunks_left = buytap_buyer_chunks($buyer_id);
        if ($buyer_left > 0 && empty($buyer_chunks_left)) {
            update_post_meta($buyer_id, 'status', 'Revoked');
            update_post_meta($buyer_id, 'sub_status', 'Payment Timeout');
            update_post_meta($buyer_id, 'date_revoked', current_time('mysql'));
            update_post_meta($buyer_id, 'revoked_reason', 'Timeout on pending payments.');

            // Return only the unpaid amount
            $pool = (int) get_option('buytap_total_tokens', 0);
            update_option('buytap_total_tokens', $pool + (int)$buyer_left);
        }

        // Keep seller eligible if still needs funding
        if (get_post_meta($seller_id, 'status', true) !== 'Closed') {
            update_post_meta($seller_id, 'status', 'Matured');
        }
    }
});


// =============================================
// ADMIN ORDER MANAGEMENT
// =============================================

/**
 * Adds "Reinstate to Pending" action for revoked orders in admin
 */
add_filter('post_row_actions', function ($actions, $post) {
    if ($post->post_type !== 'buytap_order') return $actions;

    $status = get_post_meta($post->ID, 'status', true);
    if ($status === 'Revoked' && current_user_can('manage_options')) {
        $url = wp_nonce_url(
            add_query_arg([
                'buytap_action' => 'reinstate_pending',
                'order_id'      => $post->ID,
            ], admin_url('edit.php?post_type=buytap_order')),
            'buytap_reinstate_' . $post->ID
        );
        $actions['buytap_reinstate'] = '<a href="'.$url.'">Reinstate to Pending</a>';
    }
    return $actions;
}, 10, 2);

/**
 * Handles order reinstatement from admin
 */
add_action('admin_init', function () {
    if (!isset($_GET['buytap_action'], $_GET['order_id'])) return;
    if ($_GET['buytap_action'] !== 'reinstate_pending') return;

    $order_id = (int) $_GET['order_id'];
    if (!current_user_can('manage_options')) return;
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'buytap_reinstate_' . $order_id)) return;
    if (get_post_type($order_id) !== 'buytap_order') return;

    // Reset order to Pending status
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'sub_status', 'Pending');
    update_post_meta($order_id, 'is_paired', 'no');
    delete_post_meta($order_id, 'paired_seller_order_id');
    delete_post_meta($order_id, 'pair_time');

    // Deduct tokens from pool
    $amount = (int) get_post_meta($order_id, 'amount_to_send', true);
    if ($amount > 0) {
        $pool = (int) get_option('buytap_total_tokens', 0);
        update_option('buytap_total_tokens', max(0, $pool - $amount));
    }

    if (function_exists('buytap_pair_buyer_with_seller')) {
        buytap_pair_buyer_with_seller($order_id);
    }

    wp_safe_redirect(admin_url('edit.php?post_type=buytap_order'));
    exit;
});

// =============================================
// ORDER CHUNK MANAGEMENT - SPLIT PAYMENTS
// =============================================

/**
 * Gets the name of the chunks table
 * @return string Table name with WordPress prefix
 */
function buytap_chunks_table() {
    global $wpdb; 
    return $wpdb->prefix . 'buytap_chunks';
}

/**
 * Gets all chunks for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return array List of chunk objects
 */
function buytap_buyer_chunks($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE buyer_order_id = %d ORDER BY id ASC",
        $buyer_order_id
    ));
}

/**
 * Gets all chunks for a seller order
 * @param int $seller_order_id Seller order ID
 * @return array List of chunk objects
 */
function buytap_seller_chunks($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $t WHERE seller_order_id = %d ORDER BY id ASC",
        $seller_order_id
    ));
}

/**
 * Gets total allocated amount for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return float Total allocated amount
 */
function buytap_buyer_allocated_amount($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE buyer_order_id = %d 
           AND status IN ('Awaiting Payment','Payment Made','Received')",
        $buyer_order_id
    ));
}

/**
 * Gets total allocated amount for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Total allocated amount
 */
function buytap_seller_allocated_amount($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE seller_order_id = %d 
           AND status IN ('Awaiting Payment','Payment Made','Received')",
        $seller_order_id
    ));
}

/**
 * Gets remaining amount to be allocated for a buyer order
 * @param int $buyer_order_id Buyer order ID
 * @return float Remaining amount to send
 */
function buytap_buyer_remaining($buyer_order_id) {
    $target = (float) get_post_meta($buyer_order_id, 'amount_to_send', true);
    $allocated = buytap_buyer_allocated_amount($buyer_order_id);
    return max(0, $target - $allocated);
}

/**
 * Gets remaining amount to be received for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Remaining amount to receive
 */
function buytap_seller_remaining($seller_order_id) {
    // Prefer the atomic counter if present
    $meta = get_post_meta($seller_order_id, 'seller_remaining', true);
    if ($meta !== '' && $meta !== null) {
        return max(0, (float) $meta);
    }

    // Legacy fallback (for old data): target - allocated
    $target = (float) get_post_meta($seller_order_id, 'expected_amount', true);
    if ($target <= 0) {
        $target = (float) get_post_meta($seller_order_id, 'amount_to_make', true);
    }
    $allocated = buytap_seller_allocated_amount($seller_order_id);
    return max(0, $target - $allocated);
}



/**
 * Checks if all chunks for a buyer order have been received
 * @param int $buyer_order_id Buyer order ID
 * @return bool True if all chunks received, false otherwise
 */
function buytap_buyer_all_chunks_received($buyer_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    $total = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE buyer_order_id = %d",
        $buyer_order_id
    ));
    if ($total === 0) return false;
    $not_received = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE buyer_order_id = %d AND status <> 'Received'",
        $buyer_order_id
    ));
    return $not_received === 0;
}

/**
 * Gets total received amount for a seller order
 * @param int $seller_order_id Seller order ID
 * @return float Total received amount
 */
function buytap_seller_received_sum($seller_order_id) {
    global $wpdb; $t = buytap_chunks_table();
    return (float) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(SUM(amount),0) 
         FROM $t 
         WHERE seller_order_id = %d AND status = 'Received'",
        $seller_order_id
    ));
}

/**
 * Parses duration days from order details
 * @param int $order_id Order ID
 * @return int Number of days (default: 4)
 */
function buytap_parse_duration_days($order_id) {
    $details = (string) get_post_meta($order_id, 'order_details', true);
    if (preg_match('/(\d+)\s*day/i', $details, $m)) return (int) $m[1];
    if (preg_match('/(\d+)\s*days/i', $details, $m)) return (int) $m[1];
    return 4;
}

/**
 * Activates buyer order if all chunks are received and fully paired
 * @param int $buyer_order_id Buyer order ID
 */
function buytap_activate_buyer_if_complete($buyer_order_id) {
    // All chunks must be received
    if (!buytap_buyer_all_chunks_received($buyer_order_id)) {
        buytap_log('activate: waiting for all chunks received', ['buyer_order_id' => $buyer_order_id]);
        return;
    }

    // Buyer must be fully paired (no remaining_to_send)
    $remaining_to_send = max(0, buytap_buyer_remaining($buyer_order_id));
    update_post_meta($buyer_order_id, 'remaining_to_send', $remaining_to_send);
    if ($remaining_to_send > 0) {
        buytap_log('activate: still remaining_to_send', [
            'buyer_order_id'    => $buyer_order_id,
            'remaining_to_send' => $remaining_to_send
        ]);
        return;
    }

    // Already active?
    $status = get_post_meta($buyer_order_id, 'status', true);
    if ($status === 'Active') {
        buytap_log('activate: already Active', ['buyer_order_id' => $buyer_order_id]);
        return;
    }

    // Keep whatever expected_amount is; only set it if it's empty
    $expected = (float) get_post_meta($buyer_order_id, 'expected_amount', true);
    if ($expected <= 0) {
        $fallback = (float) get_post_meta($buyer_order_id, 'amount_to_make', true);
        update_post_meta($buyer_order_id, 'expected_amount', $fallback);
        buytap_log('activate: expected_amount was empty, set from amount_to_make', [
            'buyer_order_id' => $buyer_order_id,
            'expected'       => $fallback
        ]);
    }

    // Activate and start the clock
    $now           = time();
    $duration_days = buytap_parse_duration_days($buyer_order_id);
    $maturity_ts   = $now + ($duration_days * 86400);

    update_post_meta($buyer_order_id, 'status', 'Active');
    update_post_meta($buyer_order_id, 'sub_status', 'Running');
    update_post_meta($buyer_order_id, 'is_paired', 'no'); // clear pairing state for new cycle
    update_post_meta($buyer_order_id, 'date_purchased', date('Y-m-d H:i:s', $now));
    update_post_meta($buyer_order_id, 'running_status', 'Running');
    update_post_meta($buyer_order_id, 'time_remaining', $maturity_ts);

    buytap_log('activate: success', [
        'buyer_order_id' => $buyer_order_id,
        'duration_days'  => $duration_days,
        'matures_at'     => $maturity_ts
    ]);
}


/**
 * Closes seller order if fully funded
 * @param int $seller_order_id Seller order ID
 */
function buytap_close_seller_if_funded($seller_order_id) {
    $target   = (float) get_post_meta($seller_order_id, 'expected_amount', true);
    if ($target <= 0) {
        $target = (float) get_post_meta($seller_order_id, 'amount_to_make', true); // legacy
    }
    $received = buytap_seller_received_sum($seller_order_id);

    if (abs($received - $target) < 0.01) {
        update_post_meta($seller_order_id, 'status', 'Closed');
    }
}

// =============================================
// ORDER PAIRING TRIGGERS
// =============================================

/**
 * Immediate pairing trigger when orders are created
 */
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($post->post_type !== 'buytap_order' || $update) return;
    buytap_pair_orders($post_id);
}, 10, 3);

add_action('buytap_hourly_pairing', 'buytap_run_auto_pairing');
/**
 * Sets up hourly pairing check if not already scheduled
 */
add_action('init', function() {
    if (!wp_next_scheduled('buytap_hourly_pairing')) {
        wp_schedule_event(time(), 'hourly', 'buytap_hourly_pairing');
    }
});

/**
 * Hourly pairing check - ensures all eligible orders get paired
 */
add_action('buytap_hourly_pairing', function() {
    $orders = get_posts([
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'OR',
            [
                'key' => 'status',
                'value' => 'Pending'
            ],
            [
                'key' => 'status',
                'value' => 'Matured'
            ]
        ]
    ]);
    
    // Attempt to pair each order
    foreach ($orders as $order) {
        buytap_pair_orders($order->ID);
    }
});
