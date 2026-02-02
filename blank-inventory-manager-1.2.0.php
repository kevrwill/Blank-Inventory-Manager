<?php
/**
 * Plugin Name: Blank Inventory Manager for WooCommerce
 * Plugin URI: https://gargantuanorangutan.com
 * Description: Manages blank products inventory that's shared across multiple custom printed products. Automatically deducts from blank stock when custom products are sold.
 * Version: 1.2.1
 * Author: G.O.
 * Author URI: https://gargantuanorangutan.com
 * Text Domain: blank-inventory-manager
 * Domain Path: /languages
 * Requires Plugins: woocommerce 
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

/**
 * Main plugin class
 */
class Blank_Inventory_Manager {
    
    /**
     * Plugin version
     */
    const VERSION = '1.2.1';
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Get instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add custom fields to products
        add_action('woocommerce_product_options_inventory_product_data', array($this, 'add_parent_product_field'));
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_field'), 10, 2);
        add_action('woocommerce_process_product_meta', array($this, 'save_parent_product_field'));
        
        // Add custom fields to variations
        add_action('woocommerce_variation_options_pricing', array($this, 'add_variation_field'), 10, 3);
        
        // Stock management
        add_action('woocommerce_reduce_order_stock', array($this, 'reduce_blank_stock'));
        add_action('woocommerce_restore_order_stock', array($this, 'restore_blank_stock'));
        
        // Display stock status
        add_filter('woocommerce_get_availability', array($this, 'sync_custom_product_stock'), 10, 2);
        add_filter('woocommerce_variation_is_in_stock', array($this, 'check_variation_blank_stock'), 10, 2);
        
        // Prevent purchase if blank out of stock
        add_filter('woocommerce_is_purchasable', array($this, 'check_blank_stock_purchasable'), 10, 2);
        
        // Admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Add settings page
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
    }
    
    /**
     * Add custom field to parent product (for base blank SKU)
     */
    public function add_parent_product_field() {
        global $post;
        
        // Get current values
        $blank_base_sku = get_post_meta($post->ID, '_blank_base_sku', true);
        $linked_blank_sku = get_post_meta($post->ID, '_linked_blank_sku', true);
        
        // Check if both are filled (potential conflict)
        $both_filled = !empty($blank_base_sku) && !empty($linked_blank_sku);
        
        echo '<div class="options_group">';
        
        // Show warning if both fields are filled
        if ($both_filled) {
            echo '<div class="inline notice notice-warning" style="margin: 10px 0; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">';
            echo '<p><strong>⚠️ ' . __('Warning: Both blank SKU fields are filled!', 'blank-inventory-manager') . '</strong><br>';
            echo __('The "Linked Blank SKU (Manual)" will take priority and the "Blank Base SKU" will be ignored. Choose ONE method to avoid confusion.', 'blank-inventory-manager') . '</p>';
            echo '</div>';
        }
        
        // Priority indicator
        echo '<p class="description" style="margin: 10px 0; padding: 10px; background: #f0f0f1; border-left: 3px solid #2271b1;">';
        echo '<strong>' . __('🎯 How Blank Linking Works (Priority Order):', 'blank-inventory-manager') . '</strong><br>';
        echo '1️⃣ ' . __('Variation-specific "Linked Blank SKU" (if set on individual variation)', 'blank-inventory-manager') . '<br>';
        echo '2️⃣ ' . __('Parent "Linked Blank SKU (Manual)" (below - overrides everything)', 'blank-inventory-manager') . '<br>';
        echo '3️⃣ ' . __('Parent "Blank Base SKU" + auto-detect (below - automatic mode)', 'blank-inventory-manager') . '<br><br>';
        echo '<em>' . __('💡 Tip: Use ONLY ONE method per product for best results.', 'blank-inventory-manager') . '</em>';
        echo '</p>';
        
        // Blank Base SKU field with enhanced styling
        echo '<div style="' . ($both_filled ? 'opacity: 0.6;' : '') . '">';
        woocommerce_wp_text_input(array(
            'id' => '_blank_base_sku',
            'label' => __('Blank Base SKU (Auto-mode)', 'blank-inventory-manager'),
            'desc_tip' => true,
            'description' => __('AUTOMATIC MODE: Enter only the base SKU (e.g., BLANK-WHT or BLANK-BLK). Plugin automatically adds size codes from variation SKUs. Example: BLANK-WHT + variation size "M" = BLANK-WHT-M. Leave empty if using manual linking below.', 'blank-inventory-manager'),
            'placeholder' => __('e.g., BLANK-WHT (without size)', 'blank-inventory-manager'),
            'custom_attributes' => array(
                'data-tip' => __('Auto mode: Base pattern only', 'blank-inventory-manager')
            )
        ));
        echo '</div>';
        
        // Visual separator
        echo '<hr style="margin: 15px 0; border: none; border-top: 1px dashed #ccc;">';
        echo '<p style="text-align: center; margin: 10px 0; font-weight: bold; color: #666;">' . __('— OR —', 'blank-inventory-manager') . '</p>';
        echo '<hr style="margin: 15px 0; border: none; border-top: 1px dashed #ccc;">';
        
        // Linked Blank SKU field with enhanced styling
        echo '<div style="' . ($both_filled ? 'opacity: 0.6;' : '') . '">';
        woocommerce_wp_text_input(array(
            'id' => '_linked_blank_sku',
            'label' => __('Linked Blank SKU (Manual Override)', 'blank-inventory-manager'),
            'desc_tip' => true,
            'description' => __('MANUAL MODE: Enter the complete blank SKU (e.g., BLANK-WHT-M). ALL variations will use this exact same blank, regardless of size. Good for simple products or when all sizes use one blank. This overrides auto-mode and variation-specific settings.', 'blank-inventory-manager'),
            'placeholder' => __('e.g., BLANK-WHT-M (complete SKU)', 'blank-inventory-manager'),
            'custom_attributes' => array(
                'data-tip' => __('Manual mode: Complete SKU', 'blank-inventory-manager')
            )
        ));
        echo '</div>';
        
        // Add some helpful JavaScript for better UX
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            var $baseField = $('#_blank_base_sku');
            var $manualField = $('#_linked_blank_sku');
            
            function checkFields() {
                var baseValue = $baseField.val().trim();
                var manualValue = $manualField.val().trim();
                
                if (baseValue && manualValue) {
                    // Both filled - show warning styling
                    $baseField.css('border-color', '#ffc107');
                    $manualField.css('border-color', '#ffc107');
                } else {
                    // Normal state
                    $baseField.css('border-color', '');
                    $manualField.css('border-color', '');
                }
                
                // Visual hint about which will be used
                if (manualValue) {
                    $baseField.parent().css('opacity', '0.5');
                    $manualField.parent().css('opacity', '1');
                } else if (baseValue) {
                    $baseField.parent().css('opacity', '1');
                    $manualField.parent().css('opacity', '0.5');
                } else {
                    $baseField.parent().css('opacity', '1');
                    $manualField.parent().css('opacity', '1');
                }
            }
            
            // Check on load and on change
            checkFields();
            $baseField.on('input', checkFields);
            $manualField.on('input', checkFields);
        });
        </script>
        <?php
        
        echo '</div>';
    }
    
    /**
     * Save parent product custom field
     */
    public function save_parent_product_field($post_id) {
        $blank_base_sku = isset($_POST['_blank_base_sku']) ? sanitize_text_field($_POST['_blank_base_sku']) : '';
        $linked_blank_sku = isset($_POST['_linked_blank_sku']) ? sanitize_text_field($_POST['_linked_blank_sku']) : '';
        
        update_post_meta($post_id, '_blank_base_sku', $blank_base_sku);
        update_post_meta($post_id, '_linked_blank_sku', $linked_blank_sku);
    }
    
    /**
     * Add custom field to variations
     */
    public function add_variation_field($loop, $variation_data, $variation) {
        // Get parent product settings to show context
        $parent_id = $variation->get_parent_id();
        $parent_base = get_post_meta($parent_id, '_blank_base_sku', true);
        $parent_manual = get_post_meta($parent_id, '_linked_blank_sku', true);
        
        $current_value = get_post_meta($variation->ID, '_linked_blank_sku', true);
        
        // Build contextual description
        $description = __('OVERRIDE: Link this specific variation to a blank product SKU. Leave empty to use parent product settings.', 'blank-inventory-manager');
        
        if (!empty($current_value)) {
            $description .= '<br><strong style="color: #d63638;">🎯 ' . __('This variation will use:', 'blank-inventory-manager') . ' ' . esc_html($current_value) . '</strong>';
        } elseif (!empty($parent_manual)) {
            $description .= '<br><span style="color: #2271b1;">ℹ️ ' . __('Currently using parent manual SKU:', 'blank-inventory-manager') . ' ' . esc_html($parent_manual) . '</span>';
        } elseif (!empty($parent_base)) {
            $variation_sku = get_post_meta($variation->ID, '_sku', true);
            if (!empty($variation_sku)) {
                // Extract size code properly (handles multi-character sizes)
                $parts = preg_split('/[-_]/', $variation_sku);
                $size_code = end($parts);
                $predicted = $parent_base . '-' . $size_code;
            } else {
                $predicted = $parent_base . '-?';
            }
            $description .= '<br><span style="color: #2271b1;">ℹ️ ' . __('Currently auto-detecting as:', 'blank-inventory-manager') . ' ' . esc_html($predicted) . '</span>';
        } else {
            $description .= '<br><span style="color: #d63638;">⚠️ ' . __('No parent settings found - fill this field or set parent product blank SKU', 'blank-inventory-manager') . '</span>';
        }
        
        echo '<div style="border-left: 3px solid #2271b1; padding-left: 10px; margin: 10px 0;">';
        
        woocommerce_wp_text_input(array(
            'id' => '_linked_blank_sku_' . $loop,
            'name' => '_linked_blank_sku[' . $loop . ']',
            'value' => $current_value,
            'label' => __('🔗 Linked Blank SKU (Optional Override)', 'blank-inventory-manager'),
            'desc_tip' => false,
            'description' => $description,
            'wrapper_class' => 'form-row form-row-full',
            'placeholder' => __('Leave empty to use parent settings', 'blank-inventory-manager')
        ));
        
        echo '</div>';
    }
    
    /**
     * Save variation custom field
     */
    public function save_variation_field($variation_id, $i) {
        if (isset($_POST['_linked_blank_sku'][$i])) {
            $linked_blank_sku = sanitize_text_field($_POST['_linked_blank_sku'][$i]);
            update_post_meta($variation_id, '_linked_blank_sku', $linked_blank_sku);
        }
    }
    
    /**
     * Get blank SKU for a product/variation
     */
    private function get_blank_sku($product, $variation_id = 0) {
        $blank_sku = '';
        
        // Priority 1: Check variation-specific SKU
        if ($variation_id) {
            $blank_sku = get_post_meta($variation_id, '_linked_blank_sku', true);
            if (!empty($blank_sku)) {
                return $blank_sku;
            }
        }
        
        // Priority 2: Check parent product manual link
        $parent_id = $product->get_parent_id() ?: $product->get_id();
        $blank_sku = get_post_meta($parent_id, '_linked_blank_sku', true);
        if (!empty($blank_sku)) {
            return $blank_sku;
        }
        
        // Priority 3: Auto-detect using base SKU + size code
        $blank_base = get_post_meta($parent_id, '_blank_base_sku', true);
        if (!empty($blank_base)) {
            $product_sku = $product->get_sku();
            if (!empty($product_sku)) {
                // Extract size code from end of SKU (handles multi-character sizes)
                $size_code = $this->extract_size_code($product_sku);
                if (!empty($size_code)) {
                    $blank_sku = $blank_base . '-' . $size_code;
                }
            }
        }
        
        return $blank_sku;
    }
    
    /**
     * Extract size code from product SKU
     * Handles multi-character sizes like XL, XXL, 2XL, 3XL, etc.
     */
    private function extract_size_code($sku) {
        // Split by common delimiters (hyphen, underscore)
        $parts = preg_split('/[-_]/', $sku);
        
        // Get the last part (should be the size)
        $size_code = end($parts);
        
        return $size_code;
    }
    
    /**
     * Reduce blank stock when order is placed
     */
    public function reduce_blank_stock($order) {
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            
            $variation_id = $item->get_variation_id();
            $blank_sku = $this->get_blank_sku($product, $variation_id);
            
            if (!empty($blank_sku)) {
                $this->adjust_blank_stock($blank_sku, -$item->get_quantity(), $order->get_id());
            }
        }
    }
    
    /**
     * Restore blank stock when order is cancelled/refunded
     */
    public function restore_blank_stock($order) {
        if (!is_a($order, 'WC_Order')) {
            $order = wc_get_order($order);
        }
        
        if (!$order) {
            return;
        }
        
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }
            
            $variation_id = $item->get_variation_id();
            $blank_sku = $this->get_blank_sku($product, $variation_id);
            
            if (!empty($blank_sku)) {
                $this->adjust_blank_stock($blank_sku, $item->get_quantity(), $order->get_id());
            }
        }
    }
    
    /**
     * Adjust blank product stock
     */
    private function adjust_blank_stock($blank_sku, $quantity, $order_id = 0) {
        $blank_product_id = wc_get_product_id_by_sku($blank_sku);
        
        if (!$blank_product_id) {
            $this->log_error("Blank product not found for SKU: {$blank_sku}", $order_id);
            return false;
        }
        
        $blank_product = wc_get_product($blank_product_id);
        
        if (!$blank_product || !$blank_product->managing_stock()) {
            return false;
        }
        
        $old_stock = $blank_product->get_stock_quantity();
        $new_stock = $old_stock + $quantity;
        
        $blank_product->set_stock_quantity($new_stock);
        $blank_product->save();
        
        // Log the change
        $action = $quantity > 0 ? 'increased' : 'decreased';
        $this->log_stock_change($blank_sku, $old_stock, $new_stock, $action, $order_id);
        
        return true;
    }
    
    /**
     * Sync stock status display
     */
    public function sync_custom_product_stock($availability, $product) {
        if (!$product) {
            return $availability;
        }
        
        $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
        $blank_sku = $this->get_blank_sku($product, $variation_id);
        
        if (empty($blank_sku)) {
            return $availability;
        }
        
        $blank_product_id = wc_get_product_id_by_sku($blank_sku);
        if (!$blank_product_id) {
            return $availability;
        }
        
        $blank_product = wc_get_product($blank_product_id);
        
        if ($blank_product && !$blank_product->is_in_stock()) {
            $availability['availability'] = __('Out of stock (blank inventory)', 'blank-inventory-manager');
            $availability['class'] = 'out-of-stock';
        }
        
        return $availability;
    }
    
    /**
     * Check variation blank stock
     */
    public function check_variation_blank_stock($is_in_stock, $variation) {
        $blank_sku = $this->get_blank_sku($variation, $variation->get_id());
        
        if (empty($blank_sku)) {
            return $is_in_stock;
        }
        
        $blank_product_id = wc_get_product_id_by_sku($blank_sku);
        if (!$blank_product_id) {
            return $is_in_stock;
        }
        
        $blank_product = wc_get_product($blank_product_id);
        
        if ($blank_product && !$blank_product->is_in_stock()) {
            return false;
        }
        
        return $is_in_stock;
    }
    
    /**
     * Prevent purchase if blank is out of stock
     */
    public function check_blank_stock_purchasable($is_purchasable, $product) {
        if (!$is_purchasable) {
            return $is_purchasable;
        }
        
        $variation_id = $product->is_type('variation') ? $product->get_id() : 0;
        $blank_sku = $this->get_blank_sku($product, $variation_id);
        
        if (empty($blank_sku)) {
            return $is_purchasable;
        }
        
        $blank_product_id = wc_get_product_id_by_sku($blank_sku);
        if (!$blank_product_id) {
            return $is_purchasable;
        }
        
        $blank_product = wc_get_product($blank_product_id);
        
        if ($blank_product && !$blank_product->is_in_stock()) {
            return false;
        }
        
        return $is_purchasable;
    }
    
    /**
     * Log stock change
     */
    private function log_stock_change($blank_sku, $old_stock, $new_stock, $action, $order_id) {
        $logs = get_option('blank_inventory_logs', array());
        
        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'blank_sku' => $blank_sku,
            'old_stock' => $old_stock,
            'new_stock' => $new_stock,
            'action' => $action,
            'order_id' => $order_id
        );
        
        // Keep only last 100 logs
        if (count($logs) > 100) {
            $logs = array_slice($logs, -100);
        }
        
        update_option('blank_inventory_logs', $logs);
    }
    
    /**
     * Log error
     */
    private function log_error($message, $order_id = 0) {
        $errors = get_option('blank_inventory_errors', array());
        
        $errors[] = array(
            'timestamp' => current_time('mysql'),
            'message' => $message,
            'order_id' => $order_id
        );
        
        // Keep only last 50 errors
        if (count($errors) > 50) {
            $errors = array_slice($errors, -50);
        }
        
        update_option('blank_inventory_errors', $errors);
    }
    
    /**
     * Admin notices for errors
     */
    public function admin_notices() {
        $errors = get_option('blank_inventory_errors', array());
        
        if (!empty($errors)) {
            $recent_errors = array_slice($errors, -5);
            
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p><strong>' . __('Blank Inventory Manager Warnings:', 'blank-inventory-manager') . '</strong></p>';
            echo '<ul>';
            foreach ($recent_errors as $error) {
                echo '<li>' . esc_html($error['timestamp']) . ': ' . esc_html($error['message']) . '</li>';
            }
            echo '</ul>';
            echo '<p><a href="' . admin_url('admin.php?page=blank-inventory-logs') . '">' . __('View all logs', 'blank-inventory-manager') . '</a></p>';
            echo '</div>';
        }
    }
    
    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            __('Blank Inventory Logs', 'blank-inventory-manager'),
            __('Blank Inventory', 'blank-inventory-manager'),
            'manage_woocommerce',
            'blank-inventory-logs',
            array($this, 'render_logs_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('blank_inventory_settings', 'blank_inventory_logs');
        register_setting('blank_inventory_settings', 'blank_inventory_errors');
    }
    
    /**
     * Render logs page
     */
    public function render_logs_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Blank Inventory Manager Logs', 'blank-inventory-manager'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=blank-inventory-logs&tab=logs" class="nav-tab <?php echo !isset($_GET['tab']) || $_GET['tab'] === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Stock Changes', 'blank-inventory-manager'); ?>
                </a>
                <a href="?page=blank-inventory-logs&tab=errors" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'errors' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Errors', 'blank-inventory-manager'); ?>
                </a>
                <a href="?page=blank-inventory-logs&tab=help" class="nav-tab <?php echo isset($_GET['tab']) && $_GET['tab'] === 'help' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Help', 'blank-inventory-manager'); ?>
                </a>
            </h2>
            
            <?php
            $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'logs';
            
            if ($active_tab === 'logs') {
                $this->render_logs_tab();
            } elseif ($active_tab === 'errors') {
                $this->render_errors_tab();
            } else {
                $this->render_help_tab();
            }
            ?>
        </div>
        <?php
    }
    
    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        $logs = get_option('blank_inventory_logs', array());
        $logs = array_reverse($logs);
        
        ?>
        <h2><?php _e('Recent Stock Changes', 'blank-inventory-manager'); ?></h2>
        
        <?php if (empty($logs)): ?>
            <p><?php _e('No stock changes recorded yet.', 'blank-inventory-manager'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Timestamp', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Blank SKU', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Action', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Old Stock', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('New Stock', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Order ID', 'blank-inventory-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                            <td><strong><?php echo esc_html($log['blank_sku']); ?></strong></td>
                            <td><?php echo esc_html(ucfirst($log['action'])); ?></td>
                            <td><?php echo esc_html($log['old_stock']); ?></td>
                            <td><?php echo esc_html($log['new_stock']); ?></td>
                            <td>
                                <?php if ($log['order_id']): ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $log['order_id'] . '&action=edit'); ?>">
                                        #<?php echo esc_html($log['order_id']); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render errors tab
     */
    private function render_errors_tab() {
        $errors = get_option('blank_inventory_errors', array());
        $errors = array_reverse($errors);
        
        ?>
        <h2><?php _e('Recent Errors', 'blank-inventory-manager'); ?></h2>
        
        <?php if (!empty($errors)): ?>
            <form method="post">
                <?php wp_nonce_field('clear_errors', 'blank_inventory_clear_errors'); ?>
                <p>
                    <input type="submit" name="clear_errors" class="button" value="<?php _e('Clear All Errors', 'blank-inventory-manager'); ?>">
                </p>
            </form>
        <?php endif; ?>
        
        <?php
        if (isset($_POST['clear_errors']) && wp_verify_nonce($_POST['blank_inventory_clear_errors'], 'clear_errors')) {
            delete_option('blank_inventory_errors');
            echo '<div class="updated"><p>' . __('All errors cleared.', 'blank-inventory-manager') . '</p></div>';
            $errors = array();
        }
        ?>
        
        <?php if (empty($errors)): ?>
            <p><?php _e('No errors recorded.', 'blank-inventory-manager'); ?></p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Timestamp', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Error Message', 'blank-inventory-manager'); ?></th>
                        <th><?php _e('Order ID', 'blank-inventory-manager'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errors as $error): ?>
                        <tr>
                            <td><?php echo esc_html($error['timestamp']); ?></td>
                            <td><?php echo esc_html($error['message']); ?></td>
                            <td>
                                <?php if ($error['order_id']): ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $error['order_id'] . '&action=edit'); ?>">
                                        #<?php echo esc_html($error['order_id']); ?>
                                    </a>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Render help tab
     */
    private function render_help_tab() {
        ?>
        <h2><?php _e('How to Use Blank Inventory Manager', 'blank-inventory-manager'); ?></h2>
        
        <div class="card" style="background: #fff3cd; border-left: 4px solid #ffc107;">
            <h3>⚠️ <?php _e('Important: Choose ONE Method Per Product', 'blank-inventory-manager'); ?></h3>
            <p><?php _e('The plugin offers three ways to link blank inventory. Pick the method that works best for each product, but DO NOT use multiple methods on the same product - this causes confusion and the manual method will always win.', 'blank-inventory-manager'); ?></p>
        </div>
        
        <div class="card">
            <h3><?php _e('🎯 Priority Order (What Gets Used)', 'blank-inventory-manager'); ?></h3>
            <p><?php _e('When an order is placed, the plugin checks for blank SKUs in this order:', 'blank-inventory-manager'); ?></p>
            <ol style="font-size: 1.1em; line-height: 1.8;">
                <li><strong style="color: #d63638;">1️⃣ Variation-specific "Linked Blank SKU"</strong> - <?php _e('Highest priority. If set on a variation, uses that exact SKU.', 'blank-inventory-manager'); ?></li>
                <li><strong style="color: #d63638;">2️⃣ Parent "Linked Blank SKU (Manual)"</strong> - <?php _e('Second priority. All variations use this exact SKU.', 'blank-inventory-manager'); ?></li>
                <li><strong style="color: #2271b1;">3️⃣ Parent "Blank Base SKU" + Auto-detect</strong> - <?php _e('Lowest priority. Automatically builds SKU from base + size code.', 'blank-inventory-manager'); ?></li>
            </ol>
            <p style="background: #f0f0f1; padding: 10px; border-radius: 4px;">
                <strong>💡 <?php _e('Best Practice:', 'blank-inventory-manager'); ?></strong> <?php _e('For 90% of products, just use Method 1 (Automatic). Only use Method 2 or 3 for special cases.', 'blank-inventory-manager'); ?>
            </p>
        </div>
        
        <div class="card">
            <h3><?php _e('Setup Methods', 'blank-inventory-manager'); ?></h3>
            
            <h4 style="color: #2271b1;">✅ <?php _e('Method 1: Automatic (RECOMMENDED - Naming Convention)', 'blank-inventory-manager'); ?></h4>
            <div style="background: #f0f6fc; padding: 15px; border-left: 3px solid #2271b1; margin: 10px 0;">
                <p><strong><?php _e('When to use:', 'blank-inventory-manager'); ?></strong> <?php _e('Each size variation uses a different blank (most common scenario)', 'blank-inventory-manager'); ?></p>
                <ol>
                    <li><?php _e('Create blank products with consistent SKU pattern: BLANK-COLOR-SIZE (e.g., BLANK-WHT-S, BLANK-WHT-M, BLANK-WHT-XL, BLANK-WHT-XXL)', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Create custom product variations with SKUs using hyphens or underscores before size (e.g., CUSTOM-CAT-S, CUSTOM-CAT-M, CUSTOM-CAT-XL, CUSTOM-CAT-XXL)', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('IMPORTANT: Size code must be separated by hyphen (-) or underscore (_) for multi-character sizes like XL, XXL, 2XL to work correctly', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('On parent product, set "Blank Base SKU" to the base pattern (e.g., BLANK-WHT)', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Leave "Linked Blank SKU (Manual)" empty', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Leave all variation "Linked Blank SKU" fields empty', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Plugin automatically extracts the last segment after hyphen/underscore as the size code', 'blank-inventory-manager'); ?></li>
                </ol>
                <p style="background: white; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong><?php _e('Example:', 'blank-inventory-manager'); ?></strong><br>
                    <?php _e('Parent "Blank Base SKU": BLANK-WHT', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Small (SKU: CUSTOM-CAT-S) → Extracts: S → Uses: BLANK-WHT-S', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Medium (SKU: CUSTOM-CAT-M) → Extracts: M → Uses: BLANK-WHT-M', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation XL (SKU: CUSTOM-CAT-XL) → Extracts: XL → Uses: BLANK-WHT-XL', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation XXL (SKU: CUSTOM-CAT-XXL) → Extracts: XXL → Uses: BLANK-WHT-XXL', 'blank-inventory-manager'); ?>
                </p>
            </div>
            
            <h4 style="color: #d63638;">⚠️ <?php _e('Method 2: Manual Parent-Level (Special Cases Only)', 'blank-inventory-manager'); ?></h4>
            <div style="background: #fff3cd; padding: 15px; border-left: 3px solid #ffc107; margin: 10px 0;">
                <p><strong><?php _e('When to use:', 'blank-inventory-manager'); ?></strong> <?php _e('ALL size variations use the EXACT SAME blank (uncommon)', 'blank-inventory-manager'); ?></p>
                <ol>
                    <li><?php _e('Best for simple products or when all sizes use one blank', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('On parent product, set "Linked Blank SKU (Manual)" to the complete blank SKU (e.g., BLANK-WHT-M)', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Leave "Blank Base SKU" empty', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('Leave all variation fields empty', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('All variations will deduct from the same blank', 'blank-inventory-manager'); ?></li>
                </ol>
                <p style="background: white; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong><?php _e('Example:', 'blank-inventory-manager'); ?></strong><br>
                    <?php _e('Parent "Linked Blank SKU (Manual)": BLANK-WHT-M', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Small → Uses: BLANK-WHT-M', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Medium → Uses: BLANK-WHT-M', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Large → Uses: BLANK-WHT-M', 'blank-inventory-manager'); ?>
                </p>
            </div>
            
            <h4 style="color: #d63638;">⚠️ <?php _e('Method 3: Manual Per-Variation (Exception Cases)', 'blank-inventory-manager'); ?></h4>
            <div style="background: #fff3cd; padding: 15px; border-left: 3px solid #ffc107; margin: 10px 0;">
                <p><strong><?php _e('When to use:', 'blank-inventory-manager'); ?></strong> <?php _e('One or more variations need different blanks than the others (rare)', 'blank-inventory-manager'); ?></p>
                <ol>
                    <li><?php _e('Set up parent using Method 1 (Automatic) for most variations', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('On specific variations that are different, set "Linked Blank SKU" to override', 'blank-inventory-manager'); ?></li>
                    <li><?php _e('More flexible but requires manual setup for each exception', 'blank-inventory-manager'); ?></li>
                </ol>
                <p style="background: white; padding: 10px; border-radius: 4px; margin-top: 10px;">
                    <strong><?php _e('Example:', 'blank-inventory-manager'); ?></strong><br>
                    <?php _e('Parent "Blank Base SKU": BLANK-WHT', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Small (no override) → Uses: BLANK-WHT-S', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Medium (override: BLANK-BLK-M) → Uses: BLANK-BLK-M', 'blank-inventory-manager'); ?><br>
                    <?php _e('Variation Large (no override) → Uses: BLANK-WHT-L', 'blank-inventory-manager'); ?>
                </p>
            </div>
        </div>
        
        <div class="card" style="background: #f0f6fc; border-left: 4px solid #2271b1;">
            <h3><?php _e('🎨 Visual Indicators in Product Editor', 'blank-inventory-manager'); ?></h3>
            <ul>
                <li><strong><?php _e('Yellow warning box:', 'blank-inventory-manager'); ?></strong> <?php _e('Both parent fields are filled (conflict detected)', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('Yellow field border:', 'blank-inventory-manager'); ?></strong> <?php _e('Both fields have values (manual will win)', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('Faded field:', 'blank-inventory-manager'); ?></strong> <?php _e('This field is being ignored because another has priority', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('Blue info text on variations:', 'blank-inventory-manager'); ?></strong> <?php _e('Shows what blank SKU will actually be used', 'blank-inventory-manager'); ?></li>
            </ul>
        </div>
        
        <div class="card">
            <h3><?php _e('Important Notes', 'blank-inventory-manager'); ?></h3>
            <ul>
                <li><?php _e('Blank products should be Simple Products (not Variable Products)', 'blank-inventory-manager'); ?></li>
                <li><?php _e('Set Blank products "Catalog visibility" to "Hidden" so customers don\'t see them', 'blank-inventory-manager'); ?></li>
                <li><?php _e('Enable stock management on blank products only (not on custom products)', 'blank-inventory-manager'); ?></li>
                <li><?php _e('When a custom product is ordered, blank stock automatically decreases', 'blank-inventory-manager'); ?></li>
                <li><?php _e('When an order is cancelled/refunded, blank stock is automatically restored', 'blank-inventory-manager'); ?></li>
                <li><?php _e('Custom products show as "Out of stock (blank inventory)" if linked blank is out of stock', 'blank-inventory-manager'); ?></li>
                <li><?php _e('For automatic mode, variation SKUs MUST use hyphens (-) or underscores (_) to separate size codes (e.g., CUSTOM-XXL not CUSTOMXXL)', 'blank-inventory-manager'); ?></li>
                <li><?php _e('Multi-character sizes (XL, XXL, 2XL, 3XL) work correctly when properly delimited: CUSTOM-CAT-XXL extracts "XXL"', 'blank-inventory-manager'); ?></li>
            </ul>
        </div>
        
        <div class="card">
            <h3><?php _e('Troubleshooting', 'blank-inventory-manager'); ?></h3>
            <p><?php _e('Check the "Errors" tab for issues like:', 'blank-inventory-manager'); ?></p>
            <ul>
                <li><strong><?php _e('Blank product not found for SKU:', 'blank-inventory-manager'); ?></strong> <?php _e('Verify your blank product SKU matches exactly what the plugin is looking for', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('No blank SKU configured:', 'blank-inventory-manager'); ?></strong> <?php _e('Add blank base SKU to parent product or linked SKU to variation', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('Wrong blank deducted:', 'blank-inventory-manager'); ?></strong> <?php _e('Check the priority order - a manual field might be overriding auto-detection', 'blank-inventory-manager'); ?></li>
                <li><strong><?php _e('XXL deducts from L instead:', 'blank-inventory-manager'); ?></strong> <?php _e('Make sure your SKUs use hyphens or underscores to separate the size code. CUSTOMXXL won\'t work, use CUSTOM-XXL', 'blank-inventory-manager'); ?></li>
            </ul>
            <p style="background: #f0f0f1; padding: 10px; border-radius: 4px;">
                <strong>💡 <?php _e('Testing Tip:', 'blank-inventory-manager'); ?></strong> <?php _e('After setup, place a test order and check the "Stock Changes" tab to verify the correct blank SKU is being deducted.', 'blank-inventory-manager'); ?>
            </p>
        </div>
        <?php
    }
}

// Initialize the plugin
function blank_inventory_manager_init() {
    return Blank_Inventory_Manager::get_instance();
}

add_action('plugins_loaded', 'blank_inventory_manager_init');
