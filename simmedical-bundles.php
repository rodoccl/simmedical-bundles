<?php
/**
 * Plugin Name: SimMedical Packs Ultra Safe
 * Description: Packs WooCommerce con hijos variables, selectores y fee negativo.
 * Version: 6.1.2
 * Author: ID1.cl
 */

if (!defined('ABSPATH')) exit;

// Incluye SOLO la función de vista (NO ejecuta nada)
require_once plugin_dir_path(__FILE__) . 'simmedical-pack-form.php';
require_once plugin_dir_path(__FILE__) . 'simmedical-pack-admin.php';
require_once plugin_dir_path(__FILE__) . 'simmedical-pack-wcpa.php';

class SimMedical_Bundles {

    public function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Solo frontend
        add_action('woocommerce_single_product_summary', [$this, 'display_pack_info_form'], 35);
        add_action('wp_enqueue_scripts', [$this, 'frontend_scripts']);
        add_action('wp_ajax_smp_get_variation_price', [$this, 'ajax_variation_price']);
        add_action('wp_ajax_nopriv_smp_get_variation_price', [$this, 'ajax_variation_price']);

        // Lógica de packs
        add_filter('woocommerce_add_to_cart_validation', [$this, 'intercept_pack_add_to_cart'], 10, 3);
        add_filter('woocommerce_cart_item_quantity', [$this, 'hide_qty_input_for_pack_products'], 10, 3);
        add_filter('woocommerce_cart_item_name', [$this, 'modify_cart_item_name'], 10, 3);
        add_action('woocommerce_cart_item_removed', [$this, 'remove_pack_on_item_deleted'], 10, 2);

        // Forzar precios REGULARES en hijos del pack para que el Subtotal sea la suma de precios normales
        add_action('woocommerce_before_calculate_totals', [$this, 'force_pack_children_regular_price'], 10, 1);

        // Fee negativo con el descuento total del pack
        add_action('woocommerce_cart_calculate_fees', [$this, 'apply_pack_fee_discount']);

        add_filter('woocommerce_is_purchasable', [$this, 'hide_default_add_to_cart_button'], 10, 2);
    }

    public function frontend_scripts() {
        if (is_product()) {
            wp_enqueue_script('wc-add-to-cart-variation');
        }
    }

    public function display_pack_info_form() {
        global $product;
        if (!$product || 'yes' !== get_post_meta($product->get_id(), '_is_pack', true)) return;
        $pack_products = get_post_meta($product->get_id(), '_pack_products', true);
        $pack_sale_price = get_post_meta($product->get_id(), '_pack_sale_price', true);
        if (empty($pack_products)) return;
        $product_ids = explode(',', $pack_products);

        echo '<form class="cart pack-add-to-cart-form" method="post" enctype="multipart/form-data">';
        echo '<input type="hidden" name="add-to-cart" value="' . esc_attr($product->get_id()) . '">';
        simmedical_display_pack_form($product_ids);

        echo '<div class="smp-pack-actions" style="margin:20px 0;">';
        woocommerce_quantity_input([
            'min_value' => 1,
            'max_value' => 99,
            'input_value' => 1,
        ]);

        echo '<button type="submit" id="smp-pack-cta" class="single_add_to_cart_button button alt" disabled aria-disabled="true">Agregar Pack</button>';
        echo '</div>';

        echo '<script>jQuery(function($){ if (window.smpUpdatePackCTA) window.smpUpdatePackCTA(); });</script>';

        echo '</form>';
    }

    // AJAX para precio de variación - incluye stock
    public function ajax_variation_price() {
        check_ajax_referer('smp_pack_nonce', 'nonce');
        $product_id = intval($_POST['product_id']);
        $attributes = isset($_POST['attributes']) ? (array) $_POST['attributes'] : [];
        $product = wc_get_product($product_id);
        $price = 0;
        $in_stock = false;

        if ($product && $product->is_type('variable')) {
            $variation = $this->find_matching_variation($product, $attributes);
            if ($variation) {
                $price = floatval($variation->get_price());
                $in_stock = $variation->is_in_stock();
            }
        }

        wp_send_json_success([
            'price'      => $price,
            'price_html' => $price ? wc_price($price) : '$0',
            'in_stock'   => $in_stock
        ]);
    }

    // FIX: normaliza correctamente claves (acepta 'pa_color', 'attribute_pa_color' o custom)
    private function find_matching_variation($variable_product, $attributes) {
        if (empty($attributes) || !$variable_product || !$variable_product->is_type('variable')) {
            return false;
        }

        $attributes = (array) $attributes;

        foreach ($variable_product->get_children() as $variation_id) {
            $var_attrs = wc_get_product_variation_attributes($variation_id); // keys: attribute_pa_xxx / attribute_xxx
            if (empty($var_attrs)) continue;

            $found = true;
            foreach ($attributes as $attr_key => $attr_value) {
                $key_sanit = sanitize_title($attr_key); // admite 'pa_color' o 'attribute_pa_color'
                $attr_key_norm = (strpos($key_sanit, 'attribute_') === 0) ? $key_sanit : 'attribute_' . $key_sanit;

                $var_val = isset($var_attrs[$attr_key_norm]) ? $var_attrs[$attr_key_norm] : '';
                if ((string) $var_val !== (string) $attr_value) { $found = false; break; }
            }

            if ($found) return wc_get_product($variation_id);
        }

        return false;
    }

    // SOLO hijos al carrito, nunca el padre
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
        return false; // NO añade el padre, solo los hijos
    }

    // Helper para nombres con corchetes -> arrays
    private function smp_set_post_value_by_brackets(&$arr, $name, $value) {
        $keys = preg_split('/\[|\]/', (string) $name, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($keys)) return;
        $ref =& $arr;
        foreach ($keys as $k) {
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = [];
            $ref =& $ref[$k];
        }
        $ref = $value;
    }

    private function add_pack_products_to_cart($pack_products, $pack_sale_price, $quantity, $pack_id, $variations_data = []) {
        $product_ids = explode(',', $pack_products);
        $individual_total = 0;
        $children_regular_total = 0; // suma de precios REGULARES de hijos x cantidades
        $added_products = [];
        $error = false;
        $error_msgs = [];

        // Leer cantidades base por hijo desde el meta del pack
        $pack_qty_map = get_post_meta($pack_id, '_pack_qty', true);
        if (!is_array($pack_qty_map)) $pack_qty_map = [];

        foreach ($product_ids as $product_id) {
            if (empty($product_id)) continue;

            $product_id = intval($product_id);
            $product = wc_get_product($product_id);
            if (!$product || !$product->is_in_stock()) continue;

            // Cantidad por hijo = cantidad_base_del_hijo x cantidad_del_pack
            $base_child_qty = isset($pack_qty_map[$product_id]) ? max(1, intval($pack_qty_map[$product_id])) : 1;
            $child_qty = max(1, $base_child_qty * intval($quantity));

            // Captura campos del hijo (serializados en hidden)
            $wcpa_serialized_json = $_POST['smp_wcpa_child'][$product_id] ?? '';
            $wcpa_serialized = [];
            if (!empty($wcpa_serialized_json)) {
                $wcpa_serialized = json_decode(stripslashes($wcpa_serialized_json), true);
                if (!is_array($wcpa_serialized)) $wcpa_serialized = [];
            }

            $old_post = $_POST;

            // Simula submit del hijo
            $_POST['add-to-cart'] = $product_id;
            $_POST['quantity']    = $child_qty;

            if (!empty($wcpa_serialized)) {
                foreach ($wcpa_serialized as $pair) {
                    if (!isset($pair['name'])) continue;
                    $this->smp_set_post_value_by_brackets($_POST, $pair['name'], $pair['value'] ?? '');
                }
            }

            if ($product->is_type('variable')) {
                $variation_attributes = $variations_data[$product_id] ?? [];
                $variation = $this->find_matching_variation($product, $variation_attributes);
                if ($variation) {
                    if (!$variation->is_in_stock()) {
                        $error = true;
                        $error_msgs[] = 'La variación seleccionada de <a href="' . get_permalink($product_id) . '">' . $product->get_name() . '</a> no tiene stock. Elige otra opción.';
                        $_POST = $old_post;
                        continue;
                    }
                    $variation_id = $variation->get_id();
                    $variation_price = $variation->get_price();
                    $variation_regular = $variation->get_regular_price();
                    $reg_price = ($variation_regular !== '' && $variation_regular !== null) ? floatval($variation_regular) : floatval($variation_price);

                    $_POST['product_id']   = $product_id;
                    $_POST['variation_id'] = $variation_id;
                    if (!empty($variation_attributes)) {
                        foreach ($variation_attributes as $attr_key => $attr_val) {
                            $_POST[$attr_key] = $attr_val; // p.ej. attribute_pa_color => 'celeste' (o pa_color)
                        }
                    }

                    $cart_item_key = WC()->cart->add_to_cart($product_id, $child_qty, $variation_id, $variation_attributes, ['pack_parent' => $pack_id]);

                    $_POST = $old_post;

                    if ($cart_item_key) {
                        $individual_total += floatval($variation_price) * $child_qty;
                        $children_regular_total += $reg_price * $child_qty;
                        $added_products[] = [
                            'cart_key'   => $cart_item_key,
                            'product_id' => $product_id,
                            'price'      => floatval($variation_price),
                            'quantity'   => $child_qty,
                        ];
                    }
                } else {
                    $error = true;
                    $error_msgs[] = 'Por favor, elige las opciones del producto visitando <a href="' . get_permalink($product_id) . '">' . $product->get_name() . '</a> .';
                    $_POST = $old_post;
                    continue;
                }
            } else {
                $_POST['product_id'] = $product_id;

                $cart_item_key = WC()->cart->add_to_cart($product_id, $child_qty, 0, [], ['pack_parent' => $pack_id]);

                $_POST = $old_post;

                if ($cart_item_key) {
                    $individual_total += floatval($product->get_price()) * $child_qty;
                    $prod_regular = $product->get_regular_price();
                    $reg_price = ($prod_regular !== '' && $prod_regular !== null) ? floatval($prod_regular) : floatval($product->get_price());
                    $children_regular_total += $reg_price * $child_qty;

                    $added_products[] = [
                        'cart_key'   => $cart_item_key,
                        'product_id' => $product_id,
                        'price'      => floatval($product->get_price()),
                        'quantity'   => $child_qty,
                    ];
                }
            }
        }

        if ($error) {
            return ['error' => true, 'message' => implode('<br>', $error_msgs)];
        }

        // (Se mantiene la lógica de sesión para compatibilidad, pero el descuento visible se recalcula en fees)
        $pack_product = wc_get_product($pack_id);

        $normal_total = 0.0;
        if ($pack_product) {
            $pack_regular = $pack_product->get_regular_price();
            if ($pack_regular !== '' && $pack_regular !== null) {
                $normal_total = floatval($pack_regular) * intval($quantity);
            }
        }
        if ($normal_total <= 0) {
            $normal_total = floatval($children_regular_total); // fallback: suma regular de hijos
        }

        $sale_total = 0.0;
        if ($pack_product) {
            $pack_sale = $pack_product->get_sale_price();
            if ($pack_sale !== '' && $pack_sale !== null) {
                $sale_total = floatval($pack_sale) * intval($quantity);
            }
        }
        if ($sale_total <= 0 && !empty($pack_sale_price)) {
            $sale_total = floatval($pack_sale_price) * intval($quantity);
        }

        $discount = 0.0;
        if ($normal_total > 0 && $sale_total > 0 && $normal_total > $sale_total) {
            $discount = $normal_total - $sale_total;
        }

        if ($discount > 0) {
            WC()->session->set('simmedical_pack_discount', [
                'discount' => $discount,
                'pack_id'  => $pack_id,
                'label'    => __('Descuento Pack', 'woocommerce')
            ]);
        } else {
            WC()->session->__unset('simmedical_pack_discount');
        }

        wc_add_notice(sprintf(
            __('Pack "%s" agregado al carrito con %d productos.', 'woocommerce'),
            $pack_product ? $pack_product->get_name() : __('Pack', 'woocommerce'),
            count($added_products)
        ), 'success');

        return ['error' => false];
    }

    public function hide_qty_input_for_pack_products($product_quantity, $cart_item_key, $cart_item){
        if (isset($cart_item['pack_parent'])) {
            return '<span class="pack-product-qty">' . $cart_item['quantity'] . '</span>';
        }
        return $product_quantity;
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
        WC()->session->__unset('simmedical_pack_discount');
        wc_add_notice('Eliminaste un producto de un pack. Por eso se eliminaron todos los productos del pack y el descuento.', 'notice');
    }

    // 1) Forzar que los hijos del pack usen su PRECIO REGULAR en el carrito (para que el Subtotal sea la suma de precios normales)
    public function force_pack_children_regular_price($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (empty($cart) || !method_exists($cart, 'get_cart')) return;

        foreach ($cart->get_cart() as $cart_item) {
            if (!isset($cart_item['pack_parent'])) continue;

            $product_obj = $cart_item['data']; // WC_Product o WC_Product_Variation
            if (!$product_obj || !is_object($product_obj)) continue;

            $regular = $product_obj->get_regular_price();
            if ($regular !== '' && $regular !== null && floatval($regular) > 0) {
                $product_obj->set_price((float) $regular);
            }
        }
    }

    // 2) Añadir FEE negativo con el descuento total del pack
    //    - Si hay 2 o más packs (sumando unidades de todos los packs), se muestra UNA SOLA línea: "Descuento packs".
    //    - Si hay 1 pack, se muestra "Descuento Pack - {Nombre}".
    public function apply_pack_fee_discount($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        if (empty($cart) || !method_exists($cart, 'get_cart')) return;

        // Agrupar por pack_parent
        $packs = []; // pack_id => ['normal_total'=>float, 'children_qty'=>[product_id => qty]]
        foreach ($cart->get_cart() as $key => $cart_item) {
            if (empty($cart_item['pack_parent'])) continue;
            $pack_id = (int) $cart_item['pack_parent'];
            if (!isset($packs[$pack_id])) {
                $packs[$pack_id] = [
                    'normal_total' => 0.0,
                    'children_qty' => []
                ];
            }

            $price = floatval($cart_item['data']->get_price()); // YA es el precio regular (forzado arriba)
            $qty   = intval($cart_item['quantity']);
            $packs[$pack_id]['normal_total'] += ($price * $qty);

            $child_pid = intval($cart_item['product_id']); // parent de la variación si aplica
            if (!isset($packs[$pack_id]['children_qty'][$child_pid])) {
                $packs[$pack_id]['children_qty'][$child_pid] = 0;
            }
            $packs[$pack_id]['children_qty'][$child_pid] += $qty;
        }

        if (empty($packs)) return;

        $total_discount = 0.0;
        $total_pack_units = 0;
        $single_fee_label = '';
        $single_fee_discount = 0.0;
        $single_set = false;

        foreach ($packs as $pack_id => $data) {
            $pack_product = wc_get_product($pack_id);
            if (!$pack_product) continue;

            // Precio rebajado base del pack: sale del producto pack > meta _pack_sale_price
            $sale_base = 0.0;
            $sale = $pack_product->get_sale_price();
            if ($sale !== '' && $sale !== null) {
                $sale_base = floatval($sale);
            } else {
                $meta_sale = get_post_meta($pack_id, '_pack_sale_price', true);
                if (!empty($meta_sale)) $sale_base = floatval($meta_sale);
            }
            if ($sale_base <= 0) continue;

            // Determinar cantidad de packs en el carro a partir de _pack_qty
            $pack_qty_map = get_post_meta($pack_id, '_pack_qty', true);
            if (!is_array($pack_qty_map)) $pack_qty_map = [];

            $ratios = [];
            foreach ($data['children_qty'] as $child_pid => $child_qty_total) {
                $base_child_qty = isset($pack_qty_map[$child_pid]) ? max(1, intval($pack_qty_map[$child_pid])) : 1;
                $ratios[] = (int) floor($child_qty_total / $base_child_qty);
            }
            if (empty($ratios)) continue;

            $pack_count = max(1, min($ratios)); // unidades de ese pack presentes en el carrito

            $normal_total = floatval($data['normal_total']);
            $sale_total   = $sale_base * $pack_count;

            $discount = $normal_total - $sale_total;
            if ($discount > 0.0001) {
                $total_discount += $discount;
                $total_pack_units += $pack_count;

                if (!$single_set) {
                    $single_set = true;
                    $single_fee_label = sprintf(__('Descuento Pack - %s', 'woocommerce'), $pack_product->get_name());
                    $single_fee_discount = $discount;
                } else {
                    // ya hay al menos otro pack; consolidaremos más abajo si corresponde
                }
            }
        }

        if ($total_discount <= 0) return;

        if ($total_pack_units >= 2) {
            // Consolidado
            $cart->add_fee(__('Descuento packs', 'woocommerce'), -$total_discount, false, '');
        } else {
            // Solo un pack en el carrito
            $cart->add_fee($single_fee_label ?: __('Descuento Pack', 'woocommerce'), -$single_fee_discount, false, '');
        }
    }

    public function hide_default_add_to_cart_button($purchasable, $product) {
        if ('yes' === get_post_meta($product->get_id(), '_is_pack', true)) return false;
        return $purchasable;
    }
}
new SimMedical_Bundles();
?>