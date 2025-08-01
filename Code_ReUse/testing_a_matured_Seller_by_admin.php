//Testing  Seller Maturity  --To  be deleted Immidiately  after  use  
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
