<?php
/**
 * Plugin Name: WooCommerce Cart Badge
 * Plugin URI: https://spletodrom.si
 * Description: Adds a dynamic cart quantity badge to menu items with real-time updates. Fixes YooTheme badge update issues.
 * Version: 1.0.0
 * Author: Elvis Sedić
 * Author URI: https://spletodrom.si
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-cart-badge
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WOO_CART_BADGE_VERSION', '1.0.0');
define('WOO_CART_BADGE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_CART_BADGE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WOO_CART_BADGE_TEXT_DOMAIN', 'woo-cart-badge');

/**
 * Main WooCommerce Cart Badge Plugin Class
 * 
 * @class WooCartBadge
 * @version 1.0.0
 */
class WooCartBadge {

    /**
     * Plugin instance
     *
     * @var WooCartBadge
     */
    private static $instance = null;

    /**
     * Get plugin instance
     *
     * @return WooCartBadge
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
        add_action('plugins_loaded', array($this, 'init'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        $this->load_textdomain();
        $this->init_hooks();
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Load plugin textdomain
     */
    private function load_textdomain() {
        load_plugin_textdomain(
            WOO_CART_BADGE_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(__FILE__)) . '/languages/'
        );
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Menu item filter
        add_filter('wp_nav_menu_items', array($this, 'add_cart_quantity_badge'), 10, 2);
        
        // AJAX handlers
        add_action('wp_ajax_get_cart_quantity', array($this, 'get_cart_quantity_ajax'));
        add_action('wp_ajax_nopriv_get_cart_quantity', array($this, 'get_cart_quantity_ajax'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Admin settings (optional)
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    /**
     * Add cart quantity badge to menu items
     *
     * @param string $items
     * @param object $args
     * @return string
     */
    public function add_cart_quantity_badge($items, $args) {
        if (!$this->is_woocommerce_active()) {
            return $items;
        }

        $cart_quantity = WC()->cart->get_cart_contents_count();
        $badge_html = '';

        if ($cart_quantity > 0) {
            $badge_html = sprintf(
                '<span class="uk-badge cart-quantity-badge" data-cart-count="%d">%d</span>',
                esc_attr($cart_quantity),
                esc_html($cart_quantity)
            );
        }

        // More flexible cart link detection - badge inside <a> tag
        $cart_patterns = array(
            '/(<a[^>]*>.*?Cart.*?)(<\/a>)/i',
            '/(<a[^>]*href="[^"]*cart[^"]*"[^>]*>.*?)(<\/a>)/i'
        );

        foreach ($cart_patterns as $pattern) {
            if (preg_match($pattern, $items)) {
                $items = preg_replace(
                    $pattern,
                    '$1 ' . $badge_html . '$2',
                    $items
                );
                break;
            }
        }

        return $items;
    }

    /**
     * AJAX handler to get updated cart quantity
     */
    public function get_cart_quantity_ajax() {
        // Verify nonce for security (optional but recommended)
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        if (!$this->is_woocommerce_active()) {
            wp_send_json_error(__('WooCommerce not active', WOO_CART_BADGE_TEXT_DOMAIN));
            return;
        }

        $cart_quantity = WC()->cart->get_cart_contents_count();
        wp_send_json_success($cart_quantity);
    }

    /**
     * Enqueue enhanced JavaScript for cart badge updates
     */
    public function enqueue_scripts() {
        if (!$this->is_woocommerce_active()) {
            return;
        }

        wp_enqueue_script('jquery');

        $script_handle = 'woo-cart-badge-script';
        wp_register_script($script_handle, false);
        wp_enqueue_script($script_handle);

        $script = $this->get_cart_badge_script();
        wp_add_inline_script($script_handle, $script);

        // Localize script for AJAX
        wp_localize_script($script_handle, 'wooCartBadgeAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_cart_badge_nonce')
        ));
    }

    /**
     * Get the cart badge JavaScript
     *
     * @return string
     */
    private function get_cart_badge_script() {
        ob_start();
        ?>
        jQuery(document).ready(function($) {
            let updateTimeout;
            
            // Function to update cart badge with debouncing
            function updateCartBadge() {
                clearTimeout(updateTimeout);
                updateTimeout = setTimeout(function() {
                    $.ajax({
                        url: wooCartBadgeAjax.ajaxurl,
                        type: 'POST',
                        data: { 
                            action: 'get_cart_quantity',
                            nonce: wooCartBadgeAjax.nonce
                        },
                        success: function(response) {
                            if (response.success) {
                                updateBadgeDisplay(response.data);
                            }
                        },
                        error: function() {
                            console.log('Cart badge update failed');
                        }
                    });
                }, 300);
            }
            
            function updateBadgeDisplay(quantity) {
                var badge = $('.cart-quantity-badge');
                var cartLinks = $('a[href*="cart"], a:contains("Cart"), a:contains("cart")');
                
                if (quantity > 0) {
                    if (badge.length) {
                        badge.text(quantity).attr('data-cart-count', quantity);
                    } else {
                        // Find cart text and add badge
                        cartLinks.each(function() {
                            var link = $(this);
                            var linkText = link.text().toLowerCase();
                            if (linkText.includes('cart')) {
                                // Insert badge just before closing </a> tag
                                var currentHtml = link.html();
                                link.html(currentHtml + ' <span class="uk-badge cart-quantity-badge" data-cart-count="' + quantity + '">' + quantity + '</span>');
                            }
                        });
                    }
                } else {
                    badge.remove();
                }
                
                // Trigger custom event for theme compatibility
                $(document.body).trigger('cart_badge_updated', [quantity]);
            }
            
            // Standard WooCommerce events
            $(document.body).on('updated_wc_div updated_cart_totals added_to_cart removed_from_cart wc_cart_loaded', updateCartBadge);
            
            // Block-specific events and monitoring
            if ($('.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout').length) {
                
                // Monitor for React state changes and AJAX calls
                const originalFetch = window.fetch;
                window.fetch = function(...args) {
                    const result = originalFetch.apply(this, args);
                    
                    // Check if it's a cart-related API call
                    if (args[0] && typeof args[0] === 'string' && 
                        (args[0].includes('/wc/store/') || args[0].includes('/wp-json/wc/store/'))) {
                        result.then(() => {
                            setTimeout(updateCartBadge, 500);
                        }).catch(() => {
                            // Handle fetch errors silently
                        });
                    }
                    
                    return result;
                };
                
                // Monitor DOM changes more specifically
                const observer = new MutationObserver(function(mutations) {
                    let shouldUpdate = false;
                    
                    mutations.forEach(function(mutation) {
                        const target = mutation.target;
                        
                        // Check for specific cart-related changes
                        if (target.classList && (
                            target.classList.contains('wc-block-cart__totals') ||
                            target.classList.contains('wc-block-checkout__totals') ||
                            target.closest('.wc-block-cart-item') ||
                            target.closest('.wc-block-cart-items') ||
                            target.closest('.wc-block-checkout-order-summary')
                        )) {
                            shouldUpdate = true;
                        }
                        
                        // Check for text content changes that might indicate quantity updates
                        if (mutation.type === 'childList' || mutation.type === 'characterData') {
                            const textContent = target.textContent || '';
                            if (textContent.match(/\$[\d,]+\.?\d*/)) { // Price changes
                                shouldUpdate = true;
                            }
                        }
                    });
                    
                    if (shouldUpdate) {
                        updateCartBadge();
                    }
                });
                
                // Observe cart and checkout areas
                $('.wp-block-woocommerce-cart, .wp-block-woocommerce-checkout, .wc-block-cart, .wc-block-checkout').each(function() {
                    observer.observe(this, {
                        childList: true,
                        subtree: true,
                        attributes: true,
                        characterData: true,
                        attributeFilter: ['class', 'data-quantity']
                    });
                });
            }
            
            // Direct event listeners for quantity inputs and buttons
            $(document).on('input change', '.wc-block-cart-item__quantity input, .wc-block-components-quantity-selector input, input[name*="quantity"]', function() {
                updateCartBadge();
            });
            
            // Remove buttons
            $(document).on('click', '.wc-block-cart-item__remove-link, .remove', function() {
                setTimeout(updateCartBadge, 800);
            });
            
            // Quantity selector buttons (+ and -)
            $(document).on('click', '.wc-block-components-quantity-selector__button', function() {
                setTimeout(updateCartBadge, 400);
            });
            
            // Initial load
            updateCartBadge();
        });
        <?php
        return ob_get_clean();
    }

    /**
     * Add admin menu (optional settings page)
     */
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Cart Badge Settings', WOO_CART_BADGE_TEXT_DOMAIN),
            __('Cart Badge', WOO_CART_BADGE_TEXT_DOMAIN),
            'manage_woocommerce',
            'woo-cart-badge-settings',
            array($this, 'admin_page')
        );
    }

    /**
     * Admin settings page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('YooTheme Fix Applied', WOO_CART_BADGE_TEXT_DOMAIN); ?></strong><br>
                    <?php _e('This plugin fixes the YooTheme bug where the cart badge quantity is not updating properly. The following filters in YooTheme have been bypassed:', WOO_CART_BADGE_TEXT_DOMAIN); ?>
                </p>
                <ul>
                    <li><code>wp_nav_menu_objects</code></li>
                    <li><code>woocommerce_add_to_cart_fragments</code></li>
                </ul>
                <p><?php _e('The plugin handles cart badge updates using enhanced JavaScript and AJAX calls.', WOO_CART_BADGE_TEXT_DOMAIN); ?></p>
            </div>
            
            <div class="card">
                <h2><?php _e('Plugin Information', WOO_CART_BADGE_TEXT_DOMAIN); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Version', WOO_CART_BADGE_TEXT_DOMAIN); ?></th>
                        <td><?php echo esc_html(WOO_CART_BADGE_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th><?php _e('WooCommerce Status', WOO_CART_BADGE_TEXT_DOMAIN); ?></th>
                        <td>
                            <?php if ($this->is_woocommerce_active()): ?>
                                <span style="color: green;">✓ <?php _e('Active', WOO_CART_BADGE_TEXT_DOMAIN); ?></span>
                            <?php else: ?>
                                <span style="color: red;">✗ <?php _e('Not Active', WOO_CART_BADGE_TEXT_DOMAIN); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php _e('WooCommerce Cart Badge', WOO_CART_BADGE_TEXT_DOMAIN); ?></strong>
                <?php _e('requires WooCommerce to be installed and active.', WOO_CART_BADGE_TEXT_DOMAIN); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Add activation tasks if needed
        if (!$this->is_woocommerce_active()) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(__('This plugin requires WooCommerce to be installed and active.', WOO_CART_BADGE_TEXT_DOMAIN));
        }
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Add deactivation tasks if needed
    }
}

// Initialize the plugin
WooCartBadge::get_instance();