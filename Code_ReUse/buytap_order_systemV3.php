<?php
/*
Plugin Name: BuyTap Order System
Description: Custom plugin to handle token purchases and order creation for BuyTap.
Version: 1.0
Author: Philip Osir
*/

// 🔧 Registers the custom post type for handling orders in the backend dashboard
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
// 🧩 Adds a meta box to the order edit screen in the admin
add_action('add_meta_boxes', 'buytap_add_order_meta_boxes');
function buytap_add_order_meta_boxes() {
    add_meta_box('order_details_meta', 'Order Details', 'buytap_render_order_meta_box', 'buytap_order', 'normal', 'default');
}

// 📝 Renders input fields for each order-related meta field (admin view)
function buytap_render_order_meta_box($post) {
    $fields = [
        // Pending / Shared
        'order_date' => 'Order Date',
        'order_details' => 'Order Details',
        'status' => 'Status',
        'amount_to_make' => 'Amount to Make',
        'seller_name' => 'Seller Name',
        'seller_number' => 'Seller Number',
        'amount_to_send' => 'Amount to Send',
        'time_left' => 'Time Left',
        'sub_status' => 'Sub Status',

        // Active
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

        // Closed
        'date_completed' => 'Date Completed',
        'final_amount_paid' => 'Final Amount Paid',

        // Revoked
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

// 💾 Saves all custom field values entered by the admin into post meta
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

// 🛒 Handles form submissions from a standard HTML form (non-Elementor)
add_action('init', 'buytap_handle_token_order_submission');
function buytap_handle_token_order_submission() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buytap_token_form_submitted'])) {
        $user_id = get_current_user_id();
        if (!$user_id) return;

		// Sanitize inputs
        $amount = (float) sanitize_text_field($_POST['buytap_amount']);
        $duration = (int) sanitize_text_field($_POST['buytap_duration']);

        if ($amount < 500 || $amount > 5000) return;
		
// Determine profit % based on duration
        $profit_percent = 0;
        if ($duration === 4) $profit_percent = 0.30;
        elseif ($duration === 8) $profit_percent = 0.65;
        elseif ($duration === 12) $profit_percent = 0.95;
        else return;

        $profit = $amount * $profit_percent;
        $expected_return = $amount + $profit;

        $now = current_time('mysql');
        $maturity = date('Y-m-d H:i:s', strtotime("+$duration days"));

		 // Create order post
        $order_id = wp_insert_post([
            'post_type' => 'buytap_order',
            'post_status' => 'publish',
            'post_title' => "Order by User {$user_id} on {$now}",
            'post_author' => $user_id
        ]);

        if ($order_id) {
			update_post_meta($order_id, 'order_date', $now);
			update_post_meta($order_id, 'order_details', "Ksh. $amount for $duration days");
			update_post_meta($order_id, 'amount_to_send', $amount);
			update_post_meta($order_id, 'amount_to_make', $expected_return);
			update_post_meta($order_id, 'status', 'pending');
			update_post_meta($order_id, 'time_left', $maturity);

			// Reduce/// 🔻 Deduct tokens from pool available token
			buytap_reduce_tokens_on_purchase($amount);
			
			buytap_pair_buyer_with_seller($order_id); // ✅ Auto-pair buyer with matured seller
	}


        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }
}

// 🚀 Handles form submission from Elementor Pro Forms named "Purchase_Form"
add_action('elementor_pro/forms/new_record', function($record, $handler) {
    // Make sure it's our specific form
    $form_name = $record->get_form_settings('form_name');
    if ($form_name !== 'Purchase_Form') return;
	
  // Get form fields
    $raw_fields = $record->get('fields');

    // Get submitted values
    $amount = $raw_fields['buytap_amount']['value'];
    $duration = $raw_fields['buytap_duration']['value'];

    // Calculate return based on duration // Convert readable duration into numbers
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

    $expected_return = $amount + ($amount * $profit_percent / 100);
    $maturity_date = date('Y-m-d H:i:s', strtotime("+$duration_days days"));

    // Create order post 
    // Create Order
    $order_id = wp_insert_post([
        'post_type' => 'buytap_order',
        'post_title' => 'Order #' . get_current_user_id() . '-' . current_time('YmdHis'),  //Add User ID + Timestamp to Order Title
        'post_status' => 'publish',
        'post_author' => get_current_user_id(),
    ]);

    // Save metadata
    update_post_meta($order_id, 'order_date', current_time('Y-m-d H:i'));
    update_post_meta($order_id, 'order_details', $amount . ' for ' . $duration);
    update_post_meta($order_id, 'status', 'Pending');
    update_post_meta($order_id, 'amount_to_make', $expected_return);
    update_post_meta($order_id, 'seller_name', '');
    update_post_meta($order_id, 'seller_number', '');
    update_post_meta($order_id, 'amount_to_send', $amount);
    update_post_meta($order_id, 'sub_status', 'Pending');
	
	
// 🔻 Deduct tokens from pool✅
    buytap_reduce_tokens_on_purchase((int)$amount);
	
	// ✅ Reload or Redirect to Dashboard

	buytap_pair_buyer_with_seller($order_id); // ✅ Trigger pairing
}, 10, 2);

// ===============================
// TOKEN POOL SYSTEM FOR BUYTAP/Token Pool Functions
// ===============================

// 🔢 Set initial token balance (run once or manually via admin)
function buytap_set_initial_token_pool($amount = 200000) {
    update_option('buytap_total_tokens', $amount);
}

// Reduce tokens when buyer makes a purchase
function buytap_reduce_tokens_on_purchase($amount) {
    $current = (int) get_option('buytap_total_tokens', 0);
    $new_total = max($current - $amount, 0);
    update_option('buytap_total_tokens', $new_total);
}

// Add tokens + profit when order matures
function buytap_add_tokens_on_maturity($amount, $profit_percent) {
    $profit = ($profit_percent / 100) * $amount;
    $add_back = $amount + $profit;
    $current = (int) get_option('buytap_total_tokens', 0);
    update_option('buytap_total_tokens', $current + $add_back);
}

// 📊 Returns the current available tokens
function buytap_get_token_balance() {
    return (int) get_option('buytap_total_tokens', 0);
}

// ===============================
// FRONT-END SHORTCODE FOR DISPLAY
// Usage: [buytap_tokens]
// ===============================
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


// WP ADMIN DASHBOARD tokens  FOR  EDITING DISPLAY
// Add submenu under BuyTap Orders for  admin to  add shares 
add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=buytap_order', // parent menu
        'Token Pool Manager',              // page title
        'Token Pool',                      // menu label
        'manage_options',                  // capability
        'buytap-token-pool',               // slug
        'buytap_render_token_pool_page'    // callback
    );
});


// 💬 Renders the actual page to set/edit total tokens from WP Admin
function buytap_render_token_pool_page() {
    // Save submitted value
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
    echo '</div>';
}

// ===============================
// PENDING ORDERS TAB 
// ===============================
//🔹 buytap_pending_orders Shortcode: Display Logged-In User's Pending Orders
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
                    <?php if ($status === 'paired'):
                        $seller_name = get_post_meta($id, 'seller_name', true);
                        $seller_number = get_post_meta($id, 'seller_number', true);
                        $amount = get_post_meta($id, 'amount_to_send', true);
                        $pair_time = get_post_meta($id, 'pair_time', true);
                        ?>
                        <tr class='paired-seller'>
                            <td colspan='4'>
                                <table class='paired-seller-table'>
                                    <tr>
                                        <th>Seller's Name</th>
                                        <th>Seller's Number</th>
                                        <th>Amount to Send</th>
                                        <th>Time Left</th>
                                        <th>Status</th>
                                    </tr>
                                    <tr>
                                        <td><?= esc_html($seller_name) ?></td>
                                        <td><?= esc_html($seller_number) ?></td>
                                        <td>Ksh. <?= esc_html($amount) ?></td>
                                        <td data-countdown="<?= $pair_time + 3600 ?>">Loading...</td>
                                        <td>
                                            <?php if ($sub_status !== 'Payment Made'): ?>
                                                <button class="made-payment-btn" data-order-id="<?= $id ?>">Made Payment</button>
                                            <?php else: ?>
                                                <span class="badge-paid">Payment Sent</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
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

//🔧 STEP 1: Auto-Pair Logic 
function buytap_pair_buyer_with_seller($order_id) {
    // Look for one unpaired matured order (seller)
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => 1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Matured'],
            ['key' => 'is_paired', 'value' => 'no'],
        ],
        'orderby' => 'date',
        'order' => 'ASC'
    ];
    $sellers = get_posts($args);

    if (!empty($sellers)) {
        $seller = $sellers[0];
        $seller_id = $seller->ID;

        // Get seller data
        $seller_user_id = $seller->post_author;
        $seller_name = get_the_author_meta('display_name', $seller_user_id);
        $seller_number = get_user_meta($seller_user_id, 'mpesa_number', true); // or use a custom field

        // Update buyer order
        update_post_meta($order_id, 'status', 'paired');
        update_post_meta($order_id, 'seller_name', $seller_name);
        update_post_meta($order_id, 'seller_number', $seller_number);
        update_post_meta($order_id, 'pair_time', current_time('timestamp'));
        update_post_meta($order_id, 'sub_status', 'Pending');
        update_post_meta($order_id, 'is_paired', 'yes');

        // Mark seller as paired
        update_post_meta($seller_id, 'is_paired', 'yes');
    }
}


//✅ STEP 4: Handle “Made Payment” Button
add_action('init', function() {
    if (isset($_POST['payment_made_order_id'])) {
        $order_id = intval($_POST['payment_made_order_id']);
        if (get_current_user_id() === (int)get_post_field('post_author', $order_id)) {
            update_post_meta($order_id, 'sub_status', 'Payment Made');
        }
    }
});

//Testing  Seller Maturity  --To  be deleted Immidiately  after  use  
//http://localhost/buytap/?create_matured_test_order=1
add_action('init', function () {
    if (isset($_GET['create_matured_test_order']) && current_user_can('manage_options')) {
        $user_id = get_current_user_id();

        $order_id = wp_insert_post([
            'post_type' => 'buytap_order',
            'post_status' => 'publish',
            'post_title' => "Matured Seller Test Order",
            'post_author' => $user_id
        ]);

        update_post_meta($order_id, 'status', 'Matured');
        update_post_meta($order_id, 'is_paired', 'no');
        update_post_meta($order_id, 'amount_to_make', 500);
        update_post_meta($order_id, 'order_date', current_time('mysql'));
        update_post_meta($order_id, 'order_details', 'Test matured seller token');
        update_user_meta($user_id, 'mpesa_number', '0712345678');

        echo "✅ Matured test order created. Order ID: $order_id";
        exit;
    }
});

// ✅ Register REST endpoint for marking payment
add_action('rest_api_init', function () {
    register_rest_route('buytap/v1', '/mark-payment/', [
        'methods' => 'POST',
        'callback' => 'buytap_rest_mark_payment',
        'permission_callback' => function () {
            return is_user_logged_in();
        }
    ]);
});

function buytap_rest_mark_payment(WP_REST_Request $request) {
    $order_id = intval($request['order_id']);
    $user_id = get_current_user_id();

    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
        return new WP_REST_Response(['success' => false, 'message' => 'Invalid order'], 400);
    }

    $author_id = (int)get_post_field('post_author', $order_id);
    if ($author_id !== $user_id) {
        return new WP_REST_Response(['success' => false, 'message' => 'Unauthorized'], 403);
    }

    update_post_meta($order_id, 'sub_status', 'Payment Made');

    return new WP_REST_Response(['success' => true, 'message' => 'Payment marked'], 200);
}

function buytap_enqueue_script_and_nonce() {
    wp_register_script('buytap-front-js', false); // No actual JS file needed
    wp_enqueue_script('buytap-front-js');

    wp_localize_script('buytap-front-js', 'buytapData', [
        'nonce' => wp_create_nonce('wp_rest'),
        'rest_url' => esc_url_raw(rest_url('buytap/v1/mark-payment/'))
    ]);
}
add_action('wp_enqueue_scripts', 'buytap_enqueue_script_and_nonce');
