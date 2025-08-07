Would you like to move on to:

🔄 Auto-closing orders once countdown reaches zero?

♻️ Releasing tokens + profit back to the pool?


# Revised Seller Confirmation Logic

I understand the issue - when the seller confirms receipt, their order closes but the buyer's order isn't properly transitioning to "Active" status. Let's implement a more robust solution to ensure the buyer's order activates correctly.

## New Implementation Approach

Here's the complete revised code to handle seller confirmation and buyer order activation:

```php
// 🔄 AJAX Handler: Seller clicks "Mark as Received"
add_action('wp_ajax_buytap_mark_received', function () {
    if (!is_user_logged_in()) wp_send_json_error('Not logged in');

    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $current_user = get_current_user_id();

    // Validate order
    if (!$order_id || get_post_type($order_id) !== 'buytap_order') {
        wp_send_json_error('Invalid order');
    }

    // Only the seller (author) can confirm receipt
    $post_author = (int)get_post_field('post_author', $order_id);
    if ($post_author !== $current_user) {
        wp_send_json_error('You are not authorized');
    }

    // 1. Close seller's order
    update_post_meta($order_id, 'seller_confirmed', 'yes');
    update_post_meta($order_id, 'payment_status', 'Payment Received');
    update_post_meta($order_id, 'status', 'Closed');
    update_post_meta($order_id, 'date_completed', current_time('mysql'));

    // 2. Find and activate the paired buyer order
    $buyer_order_id = get_post_meta($order_id, 'paired_buyer_order_id', true);
    
    if (!$buyer_order_id) {
        // Fallback: Find by buyer number if direct ID isn't stored
        $paired_buyer_number = get_post_meta($order_id, 'paired_buyer_number', true);
        if ($paired_buyer_number) {
            $buyer_orders = get_posts([
                'post_type' => 'buytap_order',
                'posts_per_page' => 1,
                'meta_query' => [
                    ['key' => 'seller_number', 'value' => get_user_meta($current_user, 'mobile_number', true)],
                    ['key' => 'status', 'value' => 'paired']
                ]
            ]);
            $buyer_order_id = !empty($buyer_orders) ? $buyer_orders[0]->ID : 0;
        }
    }

    if ($buyer_order_id) {
        // Calculate maturity timestamp
        $now = current_time('timestamp');
        $details = get_post_meta($buyer_order_id, 'order_details', true);
        preg_match('/(?:for|in)?\s*(\d+)\s*day/i', $details, $matches);
        $duration_days = isset($matches[1]) ? (int)$matches[1] : 4;
        $maturity_ts = $now + ($duration_days * 86400);

        // Activate buyer order
        update_post_meta($buyer_order_id, 'status', 'Active');
        update_post_meta($buyer_order_id, 'date_purchased', date('Y-m-d H:i:s', $now));
        update_post_meta($buyer_order_id, 'running_status', 'Running');
        update_post_meta($buyer_order_id, 'time_remaining', $maturity_ts);
        
        // Store the activation time for reference
        update_post_meta($buyer_order_id, 'activated_at', $now);
        
        wp_send_json_success([
            'message' => 'Payment received and buyer order activated',
            'buyer_order_id' => $buyer_order_id,
            'maturity_timestamp' => $maturity_ts
        ]);
    } else {
        wp_send_json_error('Paired buyer order not found');
    }
});
```

## Key Improvements

1. **Direct Order Relationship**:
   - Store the buyer's order ID in seller's meta during pairing:
     ```php
     // In your pairing function:
     update_post_meta($seller_id, 'paired_buyer_order_id', $order_id); // $order_id is the buyer's order
     ```

2. **Fallback Search**:
   - If direct ID isn't available, search by buyer number as fallback

3. **Complete Activation**:
   - Sets all required meta fields for proper Active order display
   - Includes timestamp tracking for debugging

4. **Detailed Response**:
   - Returns activation details in AJAX response for verification

## Required Updates to Pairing Function

Modify your `buytap_pair_buyer_with_seller()` function to store the reciprocal order IDs:

```php
function buytap_pair_buyer_with_seller($buyer_order_id) {
    // ... existing pairing logic ...
    
    if (!empty($sellers)) {
        $seller = $sellers[0];
        $seller_id = $seller->ID;
        
        // Store reciprocal order references
        update_post_meta($buyer_order_id, 'paired_seller_order_id', $seller_id);
        update_post_meta($seller_id, 'paired_buyer_order_id', $buyer_order_id);
        
        // ... rest of your existing code ...
    }
}
```

## Testing This Solution

1. **Simulate Full Flow**:
   ```php
   // 1. Create test orders
   $seller_order_id = /* create matured seller order */;
   $buyer_order_id = /* create buyer order */;
   
   // 2. Pair them
   buytap_pair_buyer_with_seller($buyer_order_id);
   
   // 3. Buyer marks payment sent
   update_post_meta($buyer_order_id, 'sub_status', 'Payment Made');
   
   // 4. Seller confirms receipt (simulate AJAX call)
   $_POST['order_id'] = $seller_order_id;
   do_action('wp_ajax_buytap_mark_received');
   ```

2. **Verify Results**:
   - Seller order should be 'Closed'
   - Buyer order should be 'Active' with proper countdown
   - Check meta values exist:
     ```php
     get_post_meta($buyer_order_id, 'status'); // Should be 'Active'
     get_post_meta($buyer_order_id, 'time_remaining'); // Future timestamp
     ```

This solution creates a direct relationship between paired orders and ensures proper state transitions. The countdown will appear in Active Orders as soon as the seller confirms receipt.







STEPS 

Awesome — you’re right on track. Here’s **exactly how to test** the buyer → Active transition after seller clicks **Mark as Received**:

---

## ✅ TESTING THE LOGIC: Step-by-Step

### 🧪 Step 1: Create a Seller Order (Matured)

You should already have this endpoint set up from testing:

```
http://localhost/buytap/?create_matured_test_order=1
```

> ✅ This creates a `buytap_order` with:

* `status = Matured`
* `is_paired = no`
* current admin as author (seller)
* with a valid `mobile_number`

---

### 🧑‍💻 Step 2: Log in as a Different User (Buyer)

* **Create/browse with another user** (not the same one that created the seller test order).
* Make sure the **buyer has a mobile number saved** in their profile:

```php
update_user_meta($user_id, 'mobile_number', '0712345678');
```

---

### 🛒 Step 3: Place a Buyer Order

Submit an Elementor form (or whatever you're using) that creates a **buytap\_order** for the buyer.

This order will:

* Be auto-paired with the seller (status = `paired`)
* Populate seller’s info in the buyer's meta
* Save buyer’s info into seller’s order (e.g. `paired_buyer_number`)

---

### 🔁 Step 4: Go to Active Orders (Seller Side)

* Log in again as the **seller** (admin in this case).
* Visit the Active Orders tab (shortcode: `[buytap_active_orders]`).
* You should see the paired buyer’s details + the `Mark as Received` button.

---

### ✅ Step 5: Click “Mark as Received”

* Click the button.
* You should see:

  * ✅ Seller order gets closed
  * ✅ Buyer order status becomes **Active**
  * ✅ Countdown starts (`time_remaining`)
  * ✅ Buyer’s order now visible in `[buytap_active_orders]` tab

---

### 👀 Optional Debugging

* Add `error_log()` lines in the AJAX to print:

```php
error_log("Buyer Order ID: $buyer_order_id");
error_log("Maturity Timestamp: $maturity_ts");
```

Then check logs in `wp-content/debug.log`.

---

## 🧪 Summary Checklist

| Step                       | What You’re Checking                     |
| -------------------------- | ---------------------------------------- |
| ✅ Seller created & matured | `status = Matured`, `is_paired = no`     |
| ✅ Buyer created & paired   | `status = paired`, `seller_number saved` |
| ✅ Seller sees buyer info   | Buyer data shown in Active tab           |
| ✅ Seller clicks confirm    | AJAX triggers, buyer becomes Active      |
| ✅ Buyer order countdown    | `time_remaining` countdown starts        |

---

If anything breaks, tell me where — I’ll help debug fast.

Ready to walk through it live if needed?
