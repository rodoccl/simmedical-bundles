<?php
/**
 * Plugin Name: SimMedical Packs Ultra Safe
 * Description: Packs WooCommerce con productos hijos variables y selectores de variaciones en el formulario. Visual Blocksy, precios en tiempo real y validación robusta.
 * Version: 3.6.5
 * Author: ID1.cl
 */

if (!defined('ABSPATH')) exit;

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return;

class SimMedical_Packs_Ultra_Safe {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_pack_fields']);
        add_action('woocommerce_process_product_meta', [$this, 'save_pack_data']);
        add_action('wp_ajax_smp_search_products', [$this, 'ajax_search_products']);
        add_action('wp_ajax_smp_calculate_pack_total', [$this, 'ajax_calculate_pack_total']);
        add_action('wp_ajax_smp_get_pack_products', [$this, 'ajax_get_pack_products']);
        add_action('woocommerce_single_product_summary', [$this, 'display_pack_info_form'], 35);
        add_filter('woocommerce_product_get_price', [$this, 'get_pack_price'], 10, 2);
        add_filter('woocommerce_product_get_regular_price', [$this, 'get_pack_price'], 10, 2);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'intercept_pack_add_to_cart'], 10, 3);
        add_filter('woocommerce_cart_item_name', [$this, 'modify_cart_item_name'], 10, 3);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_action('wp_ajax_smp_get_variation_price', [$this, 'ajax_variation_price']);
        add_filter('woocommerce_is_purchasable', [$this, 'hide_default_add_to_cart_button'], 10, 2);
        add_filter('woocommerce_cart_totals_coupon_label', [$this, 'custom_coupon_label'], 10, 2);
        add_action('woocommerce_cart_item_removed', [$this, 'remove_pack_on_item_deleted'], 10, 2);

        add_filter('woocommerce_cart_item_quantity', [$this, 'hide_qty_input_for_pack_products'], 10, 3);
        add_filter('woocommerce_cart_totals_coupon_html', [$this, 'hide_remove_link_from_pack_coupon'], 10, 2);

        if (!is_admin() && !wp_doing_ajax()) {
            add_filter('woocommerce_get_price_html', [$this, 'pack_price_html'], 15, 2);
        }

        add_filter('woocommerce_coupon_get_discount_amount', [$this, 'limit_pack_coupon_discount_to_pack_products'], 10, 5);

        add_action('admin_enqueue_scripts', [$this, 'admin_scripts']);
    }

    public function admin_scripts($hook) {
        if ($hook === 'post.php' || $hook === 'post-new.php') {
            wp_enqueue_script('smp-pack-admin', plugin_dir_url(__FILE__) . 'js/smp-pack-admin.js', ['jquery', 'jquery-ui-autocomplete'], '1.4', true);
            wp_localize_script('smp-pack-admin', 'smp_pack_admin_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('smp_pack_admin_nonce')
            ]);
            wp_enqueue_style('smp-pack-admin-style', plugin_dir_url(__FILE__) . 'assets/css/smp-pack-admin.css', [], '1.1');
        }
    }

    public function add_pack_fields() {
        global $woocommerce, $post;

        $pack_products = get_post_meta($post->ID, '_pack_products', true);
        $pack_sale_price = get_post_meta($post->ID, '_pack_sale_price', true);
        $selected_ids = array_filter(array_map('intval', explode(',', $pack_products)));

        $selected_products = [];
        $individual_total = 0;
        foreach ($selected_ids as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $price = floatval($p->get_price());
                $individual_total += $price;
                $selected_products[] = [
                    'id'    => $pid,
                    'name'  => $p->get_name(),
                    'price' => $price,
                    'price_html' => wc_price($price, array('decimals' => 0)),
                    'edit_url' => admin_url('post.php?post='.$pid.'&action=edit'),
                ];
            }
        }

        $pack_price = ($pack_sale_price > 0) ? floatval($pack_sale_price) : $individual_total;
        $ahorro = ($individual_total > $pack_price) ? ($individual_total - $pack_price) : 0;
        $porcentaje = ($individual_total > 0 && $ahorro > 0) ? round(($ahorro * 100) / $individual_total) : 0;

        echo '<div class="options_group">';
        woocommerce_wp_checkbox([
            'id' => '_is_pack',
            'label' => __('¿Es un pack de productos?', 'woocommerce'),
            'description' => __('Marca esta casilla si este producto es un pack.', 'woocommerce')
        ]);
        echo '<p class="form-field _pack_products_field">';
        echo '<label for="_pack_products">Productos del pack</label>';
        echo '<input type="text" id="pack_product_search" placeholder="Buscar productos..." style="width: 70%; margin-bottom: 10px;" />';
        echo '<div id="pack_products_container" style="border: 1px solid #ddd; padding: 10px; min-height: 100px; background: #f9f9f9;">';

        echo '<div id="selected_pack_products">';
        if (!empty($selected_products)) {
            echo '<ul style="list-style: none; padding-left: 0;">';
            foreach ($selected_products as $prod) {
                echo '<li style="margin-bottom:5px;border-bottom:1px solid #eaeaea;padding-bottom:5px;">';
                echo '<strong><a href="' . esc_url($prod['edit_url']) . '" target="_blank">' . esc_html($prod['name']) . '</a></strong>';
                echo ' <span style="color:#2271b1;">' . $prod['price_html'] . '</span>';
                echo ' <a href="#" class="remove-pack-product" data-id="' . $prod['id'] . '" style="color:red;text-decoration:none;margin-left:10px;" title="Quitar">&#10006;</a>';
                echo '</li>';
            }
            echo '</ul>';
        } else {
            echo '<em>No hay productos en el pack</em>';
        }
        echo '</div>';
        echo '</div>';
        echo '<input type="hidden" id="_pack_products" name="_pack_products" value="' . esc_attr($pack_products) . '" />';
        echo '</p>';

        woocommerce_wp_text_input([
            'id' => '_pack_sale_price',
            'label' => __('Precio del pack ($)', 'woocommerce'),
            'placeholder' => 'Precio especial del pack',
            'description' => __('Precio especial cuando se compran todos los productos juntos.', 'woocommerce'),
            'type' => 'number',
            'custom_attributes' => [
                'step' => 'any',
                'min' => '0'
            ]
        ]);

        echo '<div id="pack_total_info" style="padding: 10px; background: #e7f3ff; border: 1px solid #2271b1; border-radius: 4px; margin-top: 10px;">';
        echo '<strong>Total individual: <span id="pack_individual_total">' . wc_price($individual_total, array('decimals' => 0)) . '</span></strong><br>';
        echo '<strong>Precio del pack: <span id="pack_price_display">' . wc_price($pack_price, array('decimals' => 0)) . '</span></strong><br>';
        echo '<strong style="color: green;">Ahorro: <span id="pack_savings">' . wc_price($ahorro, array('decimals' => 0)) . '</span></strong>';
        if ($porcentaje > 0) {
            echo ' <span style="background:#6fcf97;color:#fff;padding:2px 12px;border-radius:12px;font-weight:700;margin-left:10px;">-' . $porcentaje . '%</span>';
        }
        echo '</div>';

        echo '</div>';
    }

    public function ajax_get_pack_products() {
        check_ajax_referer('smp_pack_admin_nonce', 'nonce');
        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_POST['product_ids']))));
        $products = [];
        $individual_total = 0;
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $price = floatval($p->get_price());
                $individual_total += $price;
                $products[] = [
                    'id'    => $pid,
                    'name'  => $p->get_name(),
                    'price' => $price,
                    'price_html' => wc_price($price, array('decimals' => 0)),
                    'edit_url' => admin_url('post.php?post='.$pid.'&action=edit'),
                ];
            }
        }
        wp_send_json_success([
            'products' => $products,
            'individual_total' => $individual_total
        ]);
    }

    public function ajax_calculate_pack_total() {
        check_ajax_referer('smp_pack_admin_nonce', 'nonce');
        $ids = array_filter(array_map('intval', explode(',', sanitize_text_field($_POST['product_ids']))));
        $pack_sale_price = isset($_POST['pack_sale_price']) ? floatval($_POST['pack_sale_price']) : 0;
        $individual_total = 0;
        foreach ($ids as $pid) {
            $p = wc_get_product($pid);
            if ($p) {
                $individual_total += floatval($p->get_price());
            }
        }
        $pack_price = ($pack_sale_price > 0) ? $pack_sale_price : $individual_total;
        $ahorro = ($individual_total > $pack_price) ? ($individual_total - $pack_price) : 0;
        $porcentaje = ($individual_total > 0 && $ahorro > 0) ? round(($ahorro * 100) / $individual_total) : 0;
        wp_send_json_success([
            'individual_total' => $individual_total,
            'pack_price' => $pack_price,
            'ahorro' => $ahorro,
            'porcentaje' => $porcentaje
        ]);
    }

    public function ajax_search_products() {
        check_ajax_referer('smp_pack_admin_nonce', 'nonce');
        $query = sanitize_text_field($_POST['query']);
        $products = wc_get_products(['status' => 'publish','limit' => 10,'s' => $query]);
        $results = [];
        foreach ($products as $product) {
            $results[] = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'price' => $product->get_price(),
                'price_formatted' => wc_price($product->get_price(), array('decimals' => 0))
            ];
        }
        wp_send_json_success($results);
    }

    // ---- resto del plugin igual ----

    public function pack_price_html($price_html, $product) {
        if (is_admin() || wp_doing_ajax()) return $price_html;
        if ('yes' === get_post_meta($product->get_id(), '_is_pack', true)) {
            $pack_sale_price = get_post_meta($product->get_id(), '_pack_sale_price', true);
            $pack_products = get_post_meta($product->get_id(), '_pack_products', true);
            $product_ids = !empty($pack_products) ? explode(',', $pack_products) : [];
            $real_total = 0;
            foreach ($product_ids as $pid) {
                $p = wc_get_product(intval($pid));
                if ($p) $real_total += floatval($p->get_price());
            }
            $pack_price = (!empty($pack_sale_price) && $pack_sale_price > 0) ? floatval($pack_sale_price) : $real_total;
            $show_discount = ($pack_price < $real_total);
            $percentage = $show_discount ? round(($real_total-$pack_price)*100/$real_total) : 0;

            $html = '<div class="pack-price-main" style="display:flex;align-items:center;gap:16px;">';
            $html .= '<span class="pack-price" style="font-size:1.5em;font-weight:700;color:var(--cty-color-primary);">' . wc_price($pack_price) . '</span>';
            if ($show_discount) {
                $html .= '<span class="pack-discount-badge" style="background:#6fcf97;color:#fff;padding:2px 12px;border-radius:12px;font-weight:700;">-' . $percentage . '% OFF</span>';
                $html .= '<span class="pack-price-normal" style="font-size:1em;font-weight:400;color:#888;text-decoration:line-through;margin-left:10px;">' . wc_price($real_total) . '</span>';
            }
            $html .= '</div>';
            return $html;
        }
        return $price_html;
    }

    public function get_pack_price($price, $product) {
        if ('yes' === get_post_meta($product->get_id(), '_is_pack', true)) {
            $pack_sale_price = get_post_meta($product->get_id(), '_pack_sale_price', true);
            $pack_products = get_post_meta($product->get_id(), '_pack_products', true);

            if (!empty($pack_sale_price) && $pack_sale_price > 0) return floatval($pack_sale_price);

            if (!empty($pack_products)) {
                $product_ids = explode(',', $pack_products);
                $total = 0;
                foreach ($product_ids as $pid) {
                    $p = wc_get_product(intval($pid));
                    if ($p) $total += floatval($p->get_price());
                }
                return $total;
            }
        }
        return $price;
    }

    public function hide_remove_link_from_pack_coupon($html, $coupon){
        if (strpos($coupon->get_code(), 'pack_') === 0) {
            $html = preg_replace('/<a[^>]*class="woocommerce-remove-coupon"[^>]*>[^<]*<\/a>/', '', $html);
        }
        return $html;
    }

    public function hide_default_add_to_cart_button($purchasable, $product) {
        if ('yes' === get_post_meta($product->get_id(), '_is_pack', true)) return false;
        return $purchasable;
    }

    public function custom_coupon_label($label, $coupon) {
        if (strpos($coupon->get_code(), 'pack_') === 0) {
            return __('Descuento Pack:', 'woocommerce');
        }
        return $label;
    }

    public function frontend_scripts() {
        if (is_product()) {
            wp_enqueue_script('wc-add-to-cart-variation');
            wp_enqueue_script('smp-pack-js', plugin_dir_url(__FILE__) . 'js/smp-pack.js', ['jquery'], '3.0', true);
            wp_localize_script('smp-pack-js', 'smp_pack_ajax', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('smp_pack_nonce')
            ]);
        }
    }

    public function display_pack_info_form() {
        global $product;

        if (!$product || 'yes' !== get_post_meta($product->get_id(), '_is_pack', true)) return;

        $pack_products = get_post_meta($product->get_id(), '_pack_products', true);
        $pack_sale_price = get_post_meta($product->get_id(), '_pack_sale_price', true);

        if (empty($pack_products)) return;

        $product_ids = explode(',', $pack_products);

        $real_total = 0;

        echo '<form class="cart pack-add-to-cart-form" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="add-to-cart" value="' . esc_attr($product->get_id()) . '">';
        echo '<div class="ct-card cty-blocksy-card" style="margin: 24px 0; box-shadow: var(--cty-shadow-sm); border-radius: var(--cty-radius-lg); background: var(--cty-color-bg-secondary);">';
        echo '<div class="ct-card__body" style="padding: 1.5rem;">';

        echo '<h4 class="ct-card__title" style="color: var(--cty-color-primary); margin-top: 0;">📦 Este pack incluye:</h4>';
        echo '<ul class="ct-list ct-list--check" style="font-size: 1.1em; margin-bottom: 1rem;">';

        foreach ($product_ids as $product_id) {
            if (!empty($product_id)) {
                $prod = wc_get_product(intval($product_id));
                if ($prod) {
                    $default_price = $prod->is_type('variable') ? 0 : floatval($prod->get_price());
                    $real_total += $default_price;
                    echo '<li class="ct-list__item" style="margin: 5px 0;"><strong>' . esc_html($prod->get_name()) . '</strong> <span class="pack-child-price" id="pack-price-' . $product_id . '" style="color: var(--cty-color-secondary);">- ' . ($default_price ? wc_price($default_price) : '$0') . '</span>';

                    if ($prod->is_type('variable')) {
                        $attributes = $prod->get_variation_attributes();
                        echo '<div class="pack-variation-selectors" style="margin-top: 0.5em;">';
                        foreach ($attributes as $attribute_name => $options) {
                            echo '<label for="pack_attr_' . $product_id . '_' . sanitize_title($attribute_name) . '" style="margin-right: 8px;">' . wc_attribute_label($attribute_name) . ':</label>';
                            echo '<select name="pack_attr_' . $product_id . '_' . sanitize_title($attribute_name) . '" id="pack_attr_' . $product_id . '_' . sanitize_title($attribute_name) . '" class="pack-variation-select" data-product-id="' . $product_id . '" data-attribute="' . esc_attr($attribute_name) . '" style="margin-right: 16px;">';
                            echo '<option value="">Selecciona una opción</option>';
                            foreach ($options as $option) {
                                echo '<option value="' . esc_attr($option) . '">' . esc_html($option) . '</option>';
                            }
                            echo '</select>';
                        }
                        echo '</div>';
                    }

                    echo '</li>';
                }
            }
        }
        echo '</ul>';

        if ($real_total > 0) {
            $pack_price = (!empty($pack_sale_price) && $pack_sale_price > 0) ? floatval($pack_sale_price) : $real_total;
            $show_discount = (!empty($pack_sale_price) && $pack_sale_price > 0 && $pack_price < $real_total);

            echo '<div class="ct-alert ct-alert--info" style="margin-bottom: 10px;">';
            echo '<span style="font-weight: 600;">Precio normal: </span> <span style="color: var(--cty-color-secondary);" id="pack-normal-price">' . wc_price($real_total) . '</span><br>';
            echo '<span style="font-weight: 600;">Precio del pack: </span> <span style="color: var(--cty-color-primary);">' . wc_price($pack_price) . '</span>';
            if ($show_discount) {
                $savings = $real_total - $pack_price;
                $percentage = round(($savings / $real_total) * 100);
                echo '<br><span style="color: var(--cty-color-success); font-weight: 600;">💸 Ahorro: ' . wc_price($savings) . ' (' . $percentage . '%)</span>';
            }
            echo '</div>';
        }

        echo '</div></div>';

        echo '<div style="margin:20px 0;">';
        woocommerce_quantity_input([
            'min_value' => 1,
            'max_value' => 99,
            'input_value' => 1,
        ]);
        echo '<button type="submit" class="single_add_to_cart_button button alt" style="margin-left: 10px;">Agregar pack al carrito</button>';
        echo '</div>';

        echo '</form>';
    }

    public function ajax_variation_price() {
        check_ajax_referer('smp_pack_nonce', 'nonce');
        $product_id = intval($_POST['product_id']);
        $attributes = $_POST['attributes'];
        $product = wc_get_product($product_id);
        $price = 0;
        if ($product && $product->is_type('variable')) {
            $variation = $this->find_matching_variation($product, $attributes);
            if ($variation) $price = floatval($variation->get_price());
        }
        wp_send_json_success(['price' => $price, 'price_html' => $price ? wc_price($price) : '$0']);
    }

    public function intercept_pack_add_to_cart($passed, $product_id, $quantity) {
        $product = wc_get_product($product_id);

        if (!$product || 'yes' !== get_post_meta($product_id, '_is_pack', true)) return $passed;

        $pack_products = get_post_meta($product_id, '_pack_products', true);
        $pack_sale_price = get_post_meta($product_id, '_pack_sale_price', true);

        if (empty($pack_products)) {
            wc_add_notice(__('Este pack no tiene productos configurados.', 'woocommerce'), 'error');
            return false;
        }

        $variations_data = [];
        $product_ids = explode(',', $pack_products);
        foreach ($product_ids as $child_id) {
            $child_product = wc_get_product($child_id);
            if ($child_product && $child_product->is_type('variable')) {
                $variation_attributes = $child_product->get_variation_attributes();
                $selected = [];
                foreach ($variation_attributes as $attr_key => $options) {
                    $form_key = 'pack_attr_' . $child_id . '_' . sanitize_title($attr_key);
                    if (!empty($_POST[$form_key])) {
                        $selected[$attr_key] = wc_clean($_POST[$form_key]);
                    }
                }
                $variations_data[$child_id] = $selected;
            }
        }

        $result = $this->add_pack_products_to_cart($pack_products, $pack_sale_price, $quantity, $product_id, $variations_data);

        if ($result['error']) {
            wc_add_notice($result['message'], 'error');
            return false;
        }

        return false;
    }

    private function add_pack_products_to_cart($pack_products, $pack_sale_price, $quantity, $pack_id, $variations_data = []) {
        $product_ids = explode(',', $pack_products);
        $individual_total = 0;
        $added_products = [];
        $error = false;
        $error_msgs = [];

        foreach ($product_ids as $product_id) {
            if (!empty($product_id)) {
                $product = wc_get_product(intval($product_id));
                if ($product && $product->is_in_stock()) {
                    if ($product->is_type('variable')) {
                        $variation_id = null;
                        $variation_attributes = $variations_data[$product_id] ?? [];
                        $variation = $this->find_matching_variation($product, $variation_attributes);
                        if ($variation) {
                            $variation_id = $variation->get_id();
                            $variation_price = $variation->get_price();
                        } else {
                            $error = true;
                            $error_msgs[] = 'Por favor, elige las opciones del producto visitando <a href="' . get_permalink($product_id) . '">' . $product->get_name() . '</a> .';
                            continue;
                        }
                        $cart_item_key = WC()->cart->add_to_cart(intval($product_id), $quantity, $variation_id, $variation_attributes, ['pack_parent' => $pack_id]);
                        if ($cart_item_key) {
                            $individual_total += $variation_price * $quantity;
                            $added_products[] = [
                                'cart_key' => $cart_item_key,
                                'product_id' => intval($product_id),
                                'price' => $variation_price
                            ];
                        }
                    } else {
                        $cart_item_key = WC()->cart->add_to_cart(intval($product_id), $quantity, 0, [], ['pack_parent' => $pack_id]);
                        if ($cart_item_key) {
                            $individual_total += $product->get_price() * $quantity;
                            $added_products[] = [
                                'cart_key' => $cart_item_key,
                                'product_id' => intval($product_id),
                                'price' => $product->get_price()
                            ];
                        }
                    }
                }
            }
        }

        if ($error) {
            return [
                'error' => true,
                'message' => implode('<br>', $error_msgs)
            ];
        }

        foreach (WC()->cart->get_applied_coupons() as $code) {
            if (strpos($code, 'pack_') === 0) {
                WC()->cart->remove_coupon($code);
            }
        }

        if (!empty($pack_sale_price) && $pack_sale_price > 0 && $individual_total > $pack_sale_price) {
            $pack_price = floatval($pack_sale_price) * $quantity;
            $discount_amount = $individual_total - $pack_price;
            $this->apply_pack_discount($discount_amount, $pack_id);
        }

        $pack_product = wc_get_product($pack_id);
        wc_add_notice(sprintf(
            __('Pack "%s" agregado al carrito con %d productos.', 'woocommerce'),
            $pack_product->get_name(),
            count($added_products)
        ), 'success');

        return [ 'error' => false ];
    }

    private function find_matching_variation($variable_product, $attributes) {
        if (empty($attributes)) return false;
        foreach ($variable_product->get_available_variations() as $variation_array) {
            $found = true;
            foreach ($attributes as $attr_key => $attr_value) {
                $variation_attr = $variation_array['attributes']['attribute_' . sanitize_title($attr_key)] ?? '';
                if ($variation_attr !== $attr_value) {
                    $found = false;
                    break;
                }
            }
            if ($found) return wc_get_product($variation_array['variation_id']);
        }
        return false;
    }

    private function apply_pack_discount($discount_amount, $pack_id) {
        foreach (WC()->cart->get_applied_coupons() as $code) {
            if (strpos($code, 'pack_') === 0) {
                WC()->cart->remove_coupon($code);
            }
        }
        $coupon_code = 'pack_' . $pack_id . '_' . time();
        $coupon = new WC_Coupon();
        $coupon->set_code($coupon_code);
        $coupon->set_amount($discount_amount);
        $coupon->set_discount_type('fixed_cart');
        $coupon->set_description('Descuento automático del pack');
        $coupon->set_usage_limit(1);
        $coupon->set_date_expires(strtotime('+1 hour'));
        if (method_exists($coupon, 'set_author')) {
            $coupon->set_author('ID1.cl');
        } else {
            update_post_meta($coupon->get_id(), 'post_author', 'ID1.cl');
        }
        $coupon->save();
        WC()->cart->apply_coupon($coupon_code);
        wc_add_notice(sprintf(__('Descuento Pack: %s', 'woocommerce'), wc_price($discount_amount)), 'success');
    }

    public function modify_cart_item_name($product_name, $cart_item, $cart_item_key) {
        if (isset($cart_item['pack_parent'])) {
            $pack_product = wc_get_product($cart_item['pack_parent']);
            if ($pack_product) {
                $product_name .= '<br><small style="color: var(--cty-color-secondary);">📦 Del pack: ' . $pack_product->get_name() . '</small>';
            }
        }
        return $product_name;
    }

    public function hide_qty_input_for_pack_products($product_quantity, $cart_item_key, $cart_item){
        if (isset($cart_item['pack_parent'])) {
            return '<span class="pack-product-qty">' . $cart_item['quantity'] . '</span>';
        }
        return $product_quantity;
    }

    public function remove_pack_on_item_deleted($cart_item_key, $cart) {
        $item = $cart->removed_cart_contents[$cart_item_key] ?? null;
        if (!$item || empty($item['pack_parent'])) return;
        $pack_id = $item['pack_parent'];

        $keys_to_remove = [];
        foreach ($cart->get_cart() as $key => $cart_item) {
            if (isset($cart_item['pack_parent']) && $cart_item['pack_parent'] == $pack_id) {
                $keys_to_remove[] = $key;
            }
        }
        foreach ($keys_to_remove as $key) {
            $cart->remove_cart_item($key);
        }

        foreach ($cart->get_applied_coupons() as $coupon_code) {
            if (strpos($coupon_code, 'pack_') === 0) {
                $cart->remove_coupon($coupon_code);
            }
        }

        wc_add_notice('Eliminaste un producto de un pack. Por eso se eliminaron todos los productos del pack y el descuento.', 'notice');
    }

    // ---- CORRECTO: SOLO PRODUCTOS DEL PACK RECIBEN EL DESCUENTO, Y SI HAY UN PESO SOBRANTE, SOLO VA A UN PRODUCTO DEL PACK ----
    public function limit_pack_coupon_discount_to_pack_products($discount, $discounting_amount, $cart_item, $single, $coupon) {
        // Solo para cupones de pack
        if (strpos($coupon->get_code(), 'pack_') !== 0) return 0;
        if (!isset($cart_item['pack_parent']) || !$cart_item['pack_parent']) return 0;

        // Todos los items del PACK en el carrito
        $pack_parent = $cart_item['pack_parent'];
        $pack_cart_keys = [];
        $pack_cart_totals = [];
        $total_pack_amount = 0;

        foreach (WC()->cart->get_cart() as $key => $item) {
            if (isset($item['pack_parent']) && $item['pack_parent'] == $pack_parent) {
                $pack_cart_keys[] = $key;
                $item_total = ($item['line_total'] + $item['line_subtotal_tax']);
                $pack_cart_totals[$key] = $item_total;
                $total_pack_amount += $item_total;
            }
        }

        if ($total_pack_amount == 0) return 0;

        // Proporción de este item
        $item_total = ($cart_item['line_total'] + $cart_item['line_subtotal_tax']);
        $item_prop = $item_total / $total_pack_amount;
        $raw_discount = $coupon->get_amount() * $item_prop;

        // Redondear hacia abajo, para evitar que Woo reparta el "peso" extra fuera del pack
        $item_discount = floor($raw_discount);

        // El último producto del pack recibe el peso sobrante
        $is_last = false;
        $this_key = isset($cart_item['key']) ? $cart_item['key'] : null;
        if ($this_key !== null && count($pack_cart_keys)) {
            $last_key = end($pack_cart_keys);
            $is_last = ($this_key === $last_key);
        }

        // Sumar sobrante al último item del pack
        static $acum = [];
        if (!isset($acum[$pack_parent])) $acum[$pack_parent] = 0;
        $acum[$pack_parent] += $item_discount;
        $sobrante = ($is_last) ? ($coupon->get_amount() - $acum[$pack_parent]) : 0;

        return $item_discount + $sobrante;
    }

    public function save_pack_data($post_id) {
        if (!isset($_POST['woocommerce_meta_nonce']) || !wp_verify_nonce($_POST['woocommerce_meta_nonce'], 'woocommerce_save_data')) return;
        $is_pack = isset($_POST['_is_pack']) ? 'yes' : 'no';
        update_post_meta($post_id, '_is_pack', $is_pack);
        if (isset($_POST['_pack_products'])) update_post_meta($post_id, '_pack_products', sanitize_text_field($_POST['_pack_products']));
        if (isset($_POST['_pack_sale_price'])) update_post_meta($post_id, '_pack_sale_price', floatval($_POST['_pack_sale_price']));
    }
}

new SimMedical_Packs_Ultra_Safe();
?>