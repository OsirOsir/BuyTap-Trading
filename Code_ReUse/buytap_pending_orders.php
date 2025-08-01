
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
    echo '<div class="buytap-orders-table">';

    if ($orders) {
        echo '<table style="width:100%; border-collapse:collapse; margin-top:10px;">';
        echo '<thead><tr><th>Order Date</th><th>Order Details</th><th>Status</th><th>Amount to Make</th></tr></thead><tbody>';

        foreach ($orders as $order) {
            $id = $order->ID;

            $order_date = esc_html(get_post_meta($id, 'order_date', true));
            $details = esc_html(get_post_meta($id, 'order_details', true));
            $status = esc_html(get_post_meta($id, 'status', true));
            $expected_return = number_format(get_post_meta($id, 'amount_to_make', true));
            $sub_status = get_post_meta($id, 'sub_status', true);

            echo "<tr style='border-bottom:1px solid #ddd;'>
                    <td>$order_date</td>
                    <td>$details</td>
                    <td>$status</td>
                    <td><strong>Ksh</strong> $expected_return</td>
                 </tr>";

            // 👉 SHOW SELLER INFO IF STATUS IS PAIRED
            if ($status === 'paired') {
                $seller_name = get_post_meta($id, 'seller_name', true);
                $seller_number = get_post_meta($id, 'seller_number', true);
                $amount = get_post_meta($id, 'amount_to_send', true);
                $pair_time = get_post_meta($id, 'pair_time', true);
                $now = current_time('timestamp');
                $time_left = max(0, ($pair_time + 3600) - $now);
                $formatted_time = gmdate('H:i:s', $time_left);

                echo "<tr class='paired-seller'>
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
                                    <td>$seller_name</td>
                                    <td>$seller_number</td>
                                    <td>Ksh. $amount</td>
                                    <td data-countdown=\"" . ($pair_time + 3600) . "\">Loading...</td>
                                    <td>";

                if ($sub_status !== 'Payment Made') {
                    echo "<button class='made-payment-btn' data-order-id='$id'>Made Payment</button>
";
                } else {
                    echo "<span class='badge-paid'>Payment Sent</span>";
                }

                echo "</td></tr></table></td></tr>";
            }
        }

        echo '</tbody></table>';
    } else {
        echo '<p>No pending orders found.</p>';
    }

    echo '</div>';
$content = ob_get_clean();

// Output the HTML first
return $content . <<<EOD
<script>
document.addEventListener("DOMContentLoaded", function () {
  const timers = document.querySelectorAll("[data-countdown]");
  timers.forEach(function (el) {
    const endTime = parseInt(el.getAttribute("data-countdown"));
    function updateCountdown() {
      const now = Math.floor(Date.now() / 1000);
      let timeLeft = endTime - now;
      if (timeLeft < 0) timeLeft = 0;
      const hrs = String(Math.floor((timeLeft % 86400) / 3600)).padStart(2, "0");
      const mins = String(Math.floor((timeLeft % 3600) / 60)).padStart(2, "0");
      const secs = String(timeLeft % 60).padStart(2, "0");
      el.innerText = `00d ${hrs}:${mins}:${secs}`;
    }
    updateCountdown();
    setInterval(updateCountdown, 1000);
  });

  // AJAX BUTTON
  document.querySelectorAll(".made-payment-btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      const orderId = this.getAttribute("data-order-id");
      if (!orderId) return;

      fetch("/wp-admin/admin-ajax.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({
          action: "buytap_payment_made",
          order_id: orderId
        })
      })
      .then(res => res.text())
      .then(response => {
        if (response.trim() === "success") {
          this.outerHTML = "<span class='badge-paid'>Payment Sent</span>";
        } else {
          alert("Could not mark payment. Try again.");
        }
      })
      .catch(() => {
        alert("Error sending request.");
      });
    });
  });
});
</script>
EOD;
});
