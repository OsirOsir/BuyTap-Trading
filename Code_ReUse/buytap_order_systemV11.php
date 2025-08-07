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

// ✅ Call pairing logic	
	buytap_pair_buyer_with_seller($order_id);// ✅ Trigger 
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
                                        <td data-countdown="<?= esc_attr($pair_time + 3600) ?>">Loading...</td>
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
        $seller_number = get_user_meta($seller_user_id, 'mobile_number', true); // or use a custom field

        // Update buyer order
        update_post_meta($order_id, 'status', 'paired');
        update_post_meta($order_id, 'seller_name', $seller_name);
        update_post_meta($order_id, 'seller_number', $seller_number);
        update_post_meta($order_id, 'pair_time', current_time('timestamp'));
        update_post_meta($order_id, 'sub_status', 'Waiting for Payment');
        update_post_meta($order_id, 'is_paired', 'yes');

        // Mark seller as paired
        update_post_meta($seller_id, 'is_paired', 'yes');
		
		// ✅ Save buyer info into seller order meta (for display under seller)
		$buyer_user = get_userdata(get_post_field('post_author', $order_id));
		$buyer_name = $buyer_user ? $buyer_user->display_name : '';
		$buyer_number = get_user_meta($buyer_user->ID, 'mobile_number', true);
		$amount = get_post_meta($order_id, 'amount_to_send', true);

		update_post_meta($seller_id, 'paired_buyer_name', $buyer_name);
		update_post_meta($seller_id, 'paired_buyer_number', $buyer_number);
		update_post_meta($seller_id, 'amount_to_receive', $amount);
		update_post_meta($seller_id, 'payment_status', 'Payment Pending');
    }
}


//✅ STEP 4: Handle “Made Payment” Button
add_action('init', function() {
    if (isset($_POST['payment_made_order_id'])) {
        $order_id = intval($_POST['payment_made_order_id']);
        $user_id = get_current_user_id();

        if ($user_id === (int)get_post_field('post_author', $order_id)) {
            // Mark as payment made
            update_post_meta($order_id, 'sub_status', 'Payment Made');

            // Transition to ACTIVE immediately
            $now = current_time('timestamp');
            $details = get_post_meta($order_id, 'order_details', true); // e.g., "Ksh. 5700 for 4 days"
            preg_match('/(?:for|in)?\s*(\d+)\s*day/i', $details, $matches);
            $duration_days = isset($matches[1]) ? (int)$matches[1] : 4;
            $maturity_ts = $now + ($duration_days * 86400);

            update_post_meta($order_id, 'status', 'Active');
            update_post_meta($order_id, 'date_purchased', date('Y-m-d H:i:s', $now));
            update_post_meta($order_id, 'expected_amount', get_post_meta($order_id, 'amount_to_make', true));
            update_post_meta($order_id, 'running_status', 'Running');
            update_post_meta($order_id, 'time_remaining', $maturity_ts);
        }
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

//// 🔁 Handle Buyer Payment Confirmation and Activate Order with Maturity Countdown

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

    // ✅ Mark payment made
    update_post_meta($order_id, 'sub_status', 'Payment Made');

    // ✅ Get order duration in days (from text)
    $details = get_post_meta($order_id, 'order_details', true); // e.g. "Ksh. 500 for 4 days"
	preg_match('/(?:for|in)?\s*(\d+)\s*day/i', $details, $matches);
	$duration_days = isset($matches[1]) ? (int)$matches[1] : 4;

	$now_ts = time(); // ✅ exact timestamp (no timezone offset)
	$maturity_ts = $now_ts + ($duration_days * 86400); // ✅ 4 days ahead

	update_post_meta($order_id, 'status', 'Active');
	update_post_meta($order_id, 'date_purchased', current_time('mysql')); // keep this for records
	update_post_meta($order_id, 'expected_amount', get_post_meta($order_id, 'amount_to_make', true));
	update_post_meta($order_id, 'running_status', 'Running');
	update_post_meta($order_id, 'time_remaining', $maturity_ts); // ✅ use this in countdown


    return new WP_REST_Response(['success' => true, 'message' => 'Payment marked and order activated'], 200);
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


//Create the Countdown Script (MM:SS Format) for the  buyer  to make  payment 
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const countdownCells = document.querySelectorAll('[data-countdown]');
        countdownCells.forEach(cell => {
            const orderRow = cell.closest('tr');
            const statusElement = orderRow.querySelector('.badge-paid, .made-payment-btn');

            // If Payment already sent, just show "--"
            if (statusElement && statusElement.classList.contains('badge-paid')) {
                cell.textContent = '--';
                return;
            }
// Temporarily  returns  Time  coount down correctly  BUT ILL REMOVE  LATER 
//             const endTime = (parseInt(cell.dataset.countdown) / 2) * 1000;
			const endTime = parseInt(cell.dataset.countdown) * 1000;

            function updateCountdown() {
                const now = Date.now();
                const diff = endTime - now;

                if (diff <= 0) {
                    cell.textContent = '00:00';
                    return;
                }

                const minutes = Math.floor(diff / 60000);
                const seconds = Math.floor((diff % 60000) / 1000);
                cell.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
                requestAnimationFrame(updateCountdown);
            }

            updateCountdown();
        });
    });
    </script>
    <?php
});


// ===============================
// ACTIVE ORDERS TAB
// Shortcode: [buytap_active_orders]
// Displays active orders with countdown to maturity
// ===============================
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
						$current_time = time();

						// 👇 This will auto-convert from 'Active' to 'Matured'
						$status = get_post_meta($id, 'status', true);
						$current_time = time();

						// If order matured
						if ($status === 'Active' && $time_remaining <= $current_time) {
							update_post_meta($id, 'status', 'Matured');
							$status = 'Matured';

							// ✅ Add expected amount back to pool if not already added
							$already_returned = get_post_meta($id, 'returned_to_pool', true);
							if ($already_returned !== 'yes') {
								$expected = get_post_meta($id, 'expected_amount', true);
								$expected = floatval($expected); // Ensure it's a number
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
							if ($status === 'Matured') {
								echo '<span class="badge badge-waiting" style="background-color:orange;color:white;">Waiting to be Paired</span>';
							} else {
								echo '<span class="badge badge-running" style="background-color:green;color:white;">Running</span>';
							}
								?>
							</td>
						</tr>
					<?php if ($status === 'Matured'): 
					$buyer_name = get_post_meta($id, 'paired_buyer_name', true);
					$buyer_number = get_post_meta($id, 'paired_buyer_number', true);
					$amount_to_receive = get_post_meta($id, 'amount_to_receive', true);
					$payment_status = get_post_meta($id, 'payment_status', true);
					?>
					<tr class="buyer-details">
						<td colspan="5">
							<table class="buyer-info" style="width:100%; margin-top:10px; background:#1b1b2f; color:white; border-radius:8px; padding:10px;">
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
									<tr>
										<td><?= esc_html($buyer_name) ?></td>
										<td><?= esc_html($buyer_number) ?></td>
										<td>Ksh. <?= esc_html($amount_to_receive) ?></td>
										<td><?= esc_html($payment_status) ?></td>
										<td>
											<?php if ($payment_status !== 'Payment Received'): ?>
												<button class="seller-mark-received" data-order-id="<?= $id ?>">Mark as Received</button>
											<?php else: ?>
												<span style="color:lightgreen;">✔ Payment Received</span>
											<?php endif; ?>
										</td>
									</tr>
								</tbody>
							</table>
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

//// 🔄 AJAX Handler: Seller clicks "Mark as Received" (No page reload)
add_action('wp_ajax_buytap_mark_received', function () {
    if (!is_user_logged_in()) wp_send_json_error('Not logged in');

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $current_user = get_current_user_id();

    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
        wp_send_json_error('Invalid order');
    }

    // Only the seller (author) can confirm receipt
    $post_author = (int)get_post_field('post_author', $order_id);
    if ($post_author !== $current_user) {
        wp_send_json_error('You are not authorized');
    }

    update_post_meta($order_id, 'seller_confirmed', 'yes');
    update_post_meta($order_id, 'payment_status', 'Payment Received');
	
//🔐 If buyer already confirmed, mark this order as Closed
    if (get_post_meta($order_id, 'buyer_confirmed', true) === 'yes') {
        update_post_meta($order_id, 'status', 'Closed');
    }

    wp_send_json_success('Marked as received');
});

// 📟 JavaScript Trigger: Send AJAX when seller clicks "Mark as Received"
add_action('wp_footer', function () {
    if (!is_user_logged_in()) return;
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.seller-mark-received').forEach(button => {
            button.addEventListener('click', function () {
                if (!confirm("Are you sure you've received the payment?")) return;

                const orderId = this.dataset.orderId;

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: 'buytap_mark_received',
                        order_id: orderId
                    })
                })
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        this.outerHTML = '<span style="color:lightgreen;">✔ Payment Received</span>';
                    } else {
                        alert('❌ ' + data.data);
                    }
                });
            });
        });
    });
    </script>
    <?php
});

// 🛠️ Optional Fallback: Handle GET request to mark seller received (non-AJAX fallback or testing)
//PHP Code for "Mark Received" Button Logic (No Form Needed) under  Active  Orders to SYNC BOTH ORDERS ON SELLER Confirmation
add_action('init', function () {
    if (isset($_GET['mark_received']) && is_user_logged_in()) {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $current_user = get_current_user_id();

        if ($order_id && get_post_type($order_id) === 'buytap_order') {
            // Match seller to current user
            $seller_number = get_post_meta($order_id, 'seller_number', true);
            $current_user_number = get_user_meta($current_user, 'mobile_number', true);

            if ($seller_number === $current_user_number) {
                // ✅ Mark seller confirmation
                update_post_meta($order_id, 'seller_confirmed', 'yes');
                update_post_meta($order_id, 'payment_status', 'Payment Received');

                // ✅ If buyer already confirmed, mark BOTH orders as Closed
                if (get_post_meta($order_id, 'buyer_confirmed', true) === 'yes') {
                    update_post_meta($order_id, 'status', 'Closed');

                    // 🔍 Find the buyer’s matching order and close it too
                    $paired_buyer_number = get_post_meta($order_id, 'paired_buyer_number', true);
                    $buyer_orders = get_posts([
                        'post_type' => 'buytap_order',
                        'posts_per_page' => -1,
                        'meta_query' => [
                            ['key' => 'mpesa_number', 'value' => $paired_buyer_number],
                            ['key' => 'status', 'value' => 'Active']
                        ]
                    ]);

                    foreach ($buyer_orders as $b_order) {
                        update_post_meta($b_order->ID, 'status', 'Active');
						update_post_meta($b_order->ID, 'buyer_confirmed', 'yes');
                    }
                }

                wp_redirect(remove_query_arg(['mark_received', 'order_id']));
                exit;
            }
        }
    }
});



add_action('init', function () {
    if (
        isset($_POST['mark_payment_received_order_id']) &&
        current_user_can('read') // make sure user is logged in
    ) {
        $order_id = (int) $_POST['mark_payment_received_order_id'];
        $user_id = get_current_user_id();
        $post = get_post($order_id);

        if ($post && $post->post_type === 'buytap_order' && $post->post_author === $user_id) {
            update_post_meta($order_id, 'payment_status', 'Payment Received');
        }
    }
});

// ===============================
// JAVASCRIPT: ACTIVE ORDER COUNTDOWN (Days, Hours, Minutes, Seconds)
// ===============================
add_action('wp_footer', function () {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const activeTimers = document.querySelectorAll('[data-active-countdown]');
        activeTimers.forEach(cell => {
            const expiry = parseInt(cell.dataset.activeCountdown, 10); // UNIX seconds
            const timer = setInterval(() => {
                const now = Math.floor(Date.now() / 1000); // UNIX seconds
                const diff = expiry - now;

                if (diff <= 0) {
                    cell.innerHTML = 'Matured';
                    clearInterval(timer);
                    return;
                }

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

//Add matured  Tokens back to the Pool (Expected Tokens) 
add_action('wp_loaded', function () {
    $args = [
        'post_type' => 'buytap_order',
        'posts_per_page' => -1,
        'meta_query' => [
            ['key' => 'status', 'value' => 'Active'],
            ['key' => 'time_remaining', 'value' => current_time('timestamp'), 'compare' => '<=']
        ]
    ];

    $orders = get_posts($args);

    foreach ($orders as $order) {
        $order_id = $order->ID;

        // ✅ Set status to Matured
        update_post_meta($order_id, 'status', 'Matured');
        update_post_meta($order_id, 'sub_status', 'Waiting to be Paired');

        // ✅ Only return tokens once
        $already_returned = get_post_meta($order_id, 'returned_to_pool', true);
        if ($already_returned !== 'yes') {
            $expected = (int) get_post_meta($order_id, 'expected_amount', true);
            $current_pool = (int) get_option('buytap_total_tokens', 0);
            $new_pool = $current_pool + $expected;

            if (update_option('buytap_total_tokens', $new_pool)) {
                update_post_meta($order_id, 'returned_to_pool', 'yes');
                error_log("✅ Order $order_id matured. Returned $expected to token pool.");
            } else {
                error_log("❌ Order $order_id failed to update pool.");
            }
        }

        // ✅ Auto-pair matured seller with waiting buyer
        if (function_exists('buytap_pair_matured_seller_with_buyer')) {
            buytap_pair_matured_seller_with_buyer($order_id);
        }
    }
});


// helper function for  pairing :
function buytap_pair_matured_seller_with_buyer($seller_order_id) {
    $seller_status = get_post_meta($seller_order_id, 'status', true);
    $is_paired = get_post_meta($seller_order_id, 'is_paired', true);

    if ($seller_status !== 'Matured' || $is_paired !== 'no') return;

    // Find 1 pending buyer
    $buyers = get_posts([
        'post_type' => 'buytap_order',
        'post_status' => 'publish',
        'meta_query' => [
            ['key' => 'status', 'value' => 'Pending'],
        ],
        'numberposts' => 1
    ]);

    if ($buyers) {
        $buyer_order_id = $buyers[0]->ID;

        // Copy seller info into buyer
        $seller_user = get_post_field('post_author', $seller_order_id);
        $seller_name = get_userdata($seller_user)->display_name;
        $seller_number = get_user_meta($seller_user, 'mobile_number', true);
        $amount = get_post_meta($buyer_order_id, 'amount_to_send', true);

        update_post_meta($buyer_order_id, 'status', 'Paired');
        update_post_meta($buyer_order_id, 'seller_name', $seller_name);
        update_post_meta($buyer_order_id, 'seller_number', $seller_number);
        update_post_meta($buyer_order_id, 'amount_to_send', $amount);
        update_post_meta($buyer_order_id, 'sub_status', 'Awaiting Payment');

        // Mark seller as paired
        update_post_meta($seller_order_id, 'is_paired', 'yes');
    }
}

// Shortcode  for Rendering  the  contents  under  Closed Orders Tab 
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
        update_user_meta($user_id, 'mobile_number', '0712345678');

        echo "✅ Matured test order created. Order ID: $order_id";
        exit;
    }
});

// Testing Forced Maturity – Fully matures the order in DB
// Usage: http://localhost/buytap/?expire_test_order=1&order_id=123
add_action('init', function () {
    if (isset($_GET['expire_test_order']) && current_user_can('manage_options')) {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

        if ($order_id && get_post_type($order_id) === 'buytap_order') {
            $current_time = time();

            // Force timer to expire
            update_post_meta($order_id, 'time_remaining', $current_time - 60); // 1 min ago

            // Update status and mark it available for pairing
            update_post_meta($order_id, 'status', 'Matured');
            update_post_meta($order_id, 'is_paired', 'no'); // ✅ Add this line

            // Return tokens to pool if not already added
            $already_returned = get_post_meta($order_id, 'returned_to_pool', true);
            if ($already_returned !== 'yes') {
                $expected = floatval(get_post_meta($order_id, 'expected_amount', true));
                $current_pool = floatval(get_option('buytap_total_tokens', 0));
                update_option('buytap_total_tokens', $current_pool + $expected);
                update_post_meta($order_id, 'returned_to_pool', 'yes');
            }

            echo "✅ Order ID $order_id has been fully matured.";
        } else {
            echo "❌ Invalid or missing order ID.";
        }

        exit;
    }
});

