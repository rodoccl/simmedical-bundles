<?php
if (!defined('ABSPATH')) exit;

/**
 * Helpers WCPA para prerender rápido en servidor, con CONTEXTO de producto/variación.
 */
if (!function_exists('smp_wcpa_get_form_ids')) {
    function smp_wcpa_get_form_ids($post_id) {
        if (!$post_id) return [];
        $val = get_post_meta($post_id, 'wcpa_forms', true);
        if (!$val) return [];
        $found = [];

        if (is_numeric($val)) {
            $found[] = (int) $val;
        } elseif (is_array($val)) {
            foreach ($val as $item) {
                if (is_numeric($item)) $found[] = (int) $item;
                elseif (is_array($item)) {
                    if (isset($item['id']) && is_numeric($item['id'])) $found[] = (int) $item['id'];
                    if (isset($item['form_id']) && is_numeric($item['form_id'])) $found[] = (int) $item['form_id'];
                    foreach ($item as $v) if (is_numeric($v)) $found[] = (int) $v;
                } elseif (is_string($item)) {
                    $parts = array_map('trim', explode(',', $item));
                    foreach ($parts as $p) if (is_numeric($p)) $found[] = (int) $p;
                }
            }
        } elseif (is_string($val)) {
            $json = json_decode($val, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                foreach ($json as $item) {
                    if (is_numeric($item)) $found[] = (int) $item;
                    if (is_array($item)) {
                        if (isset($item['id']) && is_numeric($item['id'])) $found[] = (int) $item['id'];
                        if (isset($item['form_id']) && is_numeric($item['form_id'])) $found[] = (int) $item['form_id'];
                        foreach ($item as $v) if (is_numeric($v)) $found[] = (int) $v;
                    }
                }
            } else {
                $parts = array_map('trim', explode(',', $val));
                foreach ($parts as $p) if (is_numeric($p)) $found[] = (int) $p;
            }
        }

        return array_values(array_unique(array_filter($found)));
    }
}

if (!function_exists('smp_wcpa_render_forms_shortcode')) {
    /**
     * Renderiza formularios WCPA con el contexto global del producto/variación objetivo.
     */
    function smp_wcpa_render_forms_shortcode($form_ids, $context_product_id = 0) {
        if (empty($form_ids)) return '';

        global $product, $post;
        $old_product = $product ?? null;
        $old_post    = $post ?? null;

        if ($context_product_id) {
            $post    = get_post($context_product_id);
            $product = wc_get_product($context_product_id);
            if ($post) setup_postdata($post);
        }

        ob_start();
        foreach ($form_ids as $fid) {
            $fid = absint($fid);
            if ($fid) echo do_shortcode('[wcpa_form id="' . $fid . '"]');
        }
        $html = ob_get_clean();

        // Restaurar contexto previo
        $product = $old_product;
        $post    = $old_post;
        if ($old_post) setup_postdata($old_post); else wp_reset_postdata();

        return $html;
    }
}

if (!function_exists('smp_wcpa_find_default_variation')) {
    function smp_wcpa_find_default_variation($variable_product) {
        if (!$variable_product || !$variable_product->is_type('variable')) return 0;
        $defaults = (array) $variable_product->get_default_attributes();
        if (empty($defaults)) return 0;

        foreach ($variable_product->get_children() as $variation_id) {
            $var_attrs = wc_get_product_variation_attributes($variation_id); // attribute_pa_xxx => slug
            if (empty($var_attrs)) continue;
            $match = true;
            foreach ($defaults as $k => $v) {
                $key_sanit = sanitize_title($k);
                $norm      = (strpos($key_sanit, 'attribute_') === 0) ? $key_sanit : ('attribute_' . $key_sanit);
                if (!isset($var_attrs[$norm]) || (string) $var_attrs[$norm] !== (string) $v) { $match = false; break; }
            }
            if ($match) return (int) $variation_id;
        }
        return 0;
    }
}

if (!function_exists('smp_wcpa_initial_html_for_product')) {
    function smp_wcpa_initial_html_for_product($prod) {
        if (!$prod) return '';
        $product_id = $prod->get_id();

        // 1) Formularios asignados al PRODUCTO
        $form_ids = smp_wcpa_get_form_ids($product_id);
        if (!empty($form_ids)) {
            return smp_wcpa_render_forms_shortcode($form_ids, $product_id);
        }

        // 2) Si es variable, probar variación por defecto
        if ($prod->is_type('variable')) {
            $def_var_id = smp_wcpa_find_default_variation($prod);
            if ($def_var_id) {
                $v_forms = smp_wcpa_get_form_ids($def_var_id);
                if (!empty($v_forms)) {
                    return smp_wcpa_render_forms_shortcode($v_forms, $def_var_id);
                }
            }
        }

        return '';
    }
}

// SOLO función, NO ejecuta nada fuera de la función
function simmedical_display_pack_form($product_ids) {
    if (empty($product_ids) || !is_array($product_ids)) return;

    // Estilos
    echo '<style>
    .smp-pack-ui-wrap { position: relative; }
    .smp-pack-inline-title { position: absolute; top: -26px; left: 0; font-size: 0.95rem; line-height: 1; font-weight: 800; text-transform: uppercase; color: #111; letter-spacing: .02em; pointer-events: none; }
    @media (max-width: 782px){ .smp-pack-inline-title { top: -22px; font-size: 0.9rem; } }
    .pack-children-list { margin: 0 0 1rem 0; padding: 0; }
    .pack-child-li { margin: 10px 0 16px 0; padding: 10px 0 16px 0; border-bottom: 1px solid #e9edf3; list-style: none; }
    .ct-list.ct-list--check .pack-child-li { list-style: inherit; }
    .pack-child-li:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
    .pack-price-loader { display: inline-block; vertical-align: middle; margin-left: 5px; width: 18px; height: 18px; position: relative; }
    .pack-price-loader svg {animation: pack-spin 0.8s linear infinite;}
    @keyframes pack-spin {100% { transform: rotate(360deg);}}
    .pack-price-loader.hide { display: none;}
    .pack-status-ok { display: inline-block; margin-left: 8px; color: #1aa1dd; font-weight: 700; font-size: 0.95em; }
    .pack-status-ok.hide { display: none;}
    .pack-status-select { color: #666; font-size: 0.9em; font-weight: normal; margin-left: 6px; }
    .pack-status-unavailable { display: inline-block; margin-left: 8px; color: #e74c3c; font-weight: 600; font-size: 0.9em; }
    .pack-status-unavailable.hide { display: none;}
    .pack-variation-selectors { margin-top: 10px; }
    .pack-attr-group { margin: 12px 0 6px; }
    .pack-attr-label { display: block; margin: 0 0 6px 0; font-weight: 600; font-size: 0.95em; color: #2b2b2b; }
    .pack-attr-buttons { display: flex; flex-wrap: wrap; gap: 6px; }
    .pack-attr-btn { display: inline-block; border: 2px solid #e1e5ea; background: #f9fafb; color: #344054; border-radius: 18px; padding: 5px 10px; cursor: pointer; line-height: 1; font-size: 0.88em; transition: all .15s ease; user-select: none; }
    .pack-attr-btn:hover { border-color: #b7c6d5; color: #1aa1dd; background: #eef6fb; }
    .pack-attr-btn.is-selected { background: #1aa1dd; border-color: #1aa1dd; color: #fff; }
    .pack-attr-btn.is-disabled { opacity: .55; pointer-events: none; }
    .pack-add-to-cart-form .single_add_to_cart_button[disabled], .pack-add-to-cart-form .single_add_to_cart_button.is-disabled { opacity: .55; cursor: not-allowed; pointer-events: none; filter: grayscale(6%); }
    </style>';

    echo '<div class="smp-pack-ui-wrap">';
    echo '<div class="smp-pack-inline-title" aria-hidden="true">ARMA TU PACK</div>';

    echo '<ul class="ct-list ct-list--check pack-children-list">';
    foreach ($product_ids as $product_id) {
        if (empty($product_id)) continue;
        $prod = wc_get_product(intval($product_id));
        if (!$prod) continue;

        echo '<li class="ct-list__item pack-child-li"><strong>' . esc_html($prod->get_name()) . '</strong> ';

        // Estado
        echo '<span class="pack-child-status-area" style="position:relative;">';
        if ($prod->is_type('variable')) {
            echo '<span class="pack-status-select" id="pack-status-' . esc_attr($product_id) . '" data-product-id="' . esc_attr($product_id) . '">(selecciona opción ↓)</span>';
        } else {
            if ($prod->is_in_stock()) {
                echo '<span class="pack-status-ok" id="pack-status-' . esc_attr($product_id) . '" data-product-id="' . esc_attr($product_id) . '">OK!</span>';
            } else {
                echo '<span class="pack-status-unavailable" id="pack-status-' . esc_attr($product_id) . '" data-product-id="' . esc_attr($product_id) . '">No disponible</span>';
            }
        }
        echo '<span class="pack-price-loader hide" id="pack-loader-' . esc_attr($product_id) . '">
                <svg viewBox="0 0 38 38" xmlns="http://www.w3.org/2000/svg">
                    <g>
                        <circle cx="19" cy="19" r="16" stroke="#1aa1dd" stroke-width="4" fill="none" opacity="0.2"/>
                        <path d="M35 19a16 16 0 0 1-16 16" stroke="#1aa1dd" stroke-width="4" fill="none"/>
                    </g>
                </svg>
            </span>';
        echo '</span>';

        // Selectores de variaciones
        if ($prod->is_type('variable')) {
            $attributes = $prod->get_variation_attributes();
            echo '<div class="pack-variation-selectors" data-product-id="' . esc_attr($product_id) . '">';
            foreach ($attributes as $attribute_name => $options) {
                $attr_key_original = $attribute_name; // attribute_pa_color
                $attr_key_sanitized = sanitize_title($attr_key_original);
                $taxonomy = str_replace('attribute_', '', $attr_key_original);

                $attr_label = wc_attribute_label($taxonomy);
                echo '<div class="pack-attr-group" data-attr-key="' . esc_attr($attr_key_sanitized) . '" data-product-id="' . esc_attr($product_id) . '">';
                echo '<span class="pack-attr-label">' . esc_html($attr_label) . ':</span>';
                echo '<div class="pack-attr-buttons" role="group" aria-label="' . esc_attr($attr_label) . '">';

                foreach ($options as $option) {
                    $btn_label = $option;
                    if (taxonomy_exists($taxonomy)) {
                        $term = get_term_by('slug', $option, $taxonomy);
                        if ($term && !is_wp_error($term)) {
                            $btn_label = $term->name;
                        }
                    }
                    echo '<button type="button" class="pack-attr-btn" data-product-id="' . esc_attr($product_id) . '" data-attr-key="' . esc_attr($attr_key_sanitized) . '" data-attr-value="' . esc_attr($option) . '">' . esc_html($btn_label) . '</button>';
                }

                echo '</div>';
                echo '<input type="hidden" class="pack-attr-input" data-product-id="' . esc_attr($product_id) . '" data-attr-key="' . esc_attr($attr_key_sanitized) . '" name="pack_attr_' . esc_attr($product_id) . '_' . esc_attr($attr_key_sanitized) . '" value="">';
                echo '</div>';
            }
            echo '</div>';
        }

        // Slot WCPA con prerender en CONTEXTO del hijo (o su variación por defecto)
        $initial_wcpa_html = smp_wcpa_initial_html_for_product($prod);
        echo '<div class="smp-wcpa-slot" id="smp-wcpa-slot-' . esc_attr($product_id) . '" data-smp-wcpa-for="' . esc_attr($product_id) . '">' . $initial_wcpa_html . '</div>';

        echo '</li>';
    }
    echo '</ul>';
    echo '</div>'; // .smp-pack-ui-wrap
    ?>
    <script>
    jQuery(function($) {
        window.smpUpdatePackCTA = function updateCTA() {
            var disabled = false;
            $('.pack-child-status-area .pack-status-unavailable').each(function(){
                if (!$(this).hasClass('hide')) disabled = true;
            });
            $('.pack-variation-selectors').each(function(){
                var pid = $(this).data('product-id');
                var $st = $('#pack-status-' + pid);
                if (!$st.length || !$st.hasClass('pack-status-ok') || $st.hasClass('hide')) {
                    disabled = true;
                }
            });
            if ($('.pack-price-loader:not(.hide)').length) disabled = true;

            var $cta = $('#smp-pack-cta');
            $cta.prop('disabled', disabled).attr('aria-disabled', disabled ? 'true' : 'false');
            $cta.toggleClass('is-disabled', disabled);
        };

        // Botones atributos
        $(document).on('click', '.pack-attr-btn', function() {
            var $btn = $(this);
            var productId = $btn.data('product-id');
            var attrKey = $btn.data('attr-key');
            var attrValue = $btn.data('attr-value');

            var $group = $btn.closest('.pack-attr-group');
            $group.find('.pack-attr-btn').removeClass('is-selected');
            $btn.addClass('is-selected');

            var $hidden = $group.find('.pack-attr-input[name="pack_attr_' + productId + '_' + attrKey + '"]');
            $hidden.val(attrValue).trigger('change');

            evaluateAndFetch(productId, $group.closest('.pack-variation-selectors'));
        });

        $(document).on('change', '.pack-attr-input', function() {
            var productId = $(this).data('product-id');
            evaluateAndFetch(productId, $(this).closest('.pack-variation-selectors'));
        });

        function evaluateAndFetch(productId, $container) {
            var attributes = {};
            var allSelected = true;

            $container.find('.pack-attr-input').each(function() {
                var name = $(this).attr('name');
                var attrName = name.replace('pack_attr_' + productId + '_', '');
                var val = $(this).val();
                attributes[attrName] = val;
                if (!val) allSelected = false;
            });

            if (!allSelected) {
                $("#pack-status-" + productId)
                    .removeClass("pack-status-ok pack-status-unavailable")
                    .addClass("pack-status-select")
                    .html("(selecciona opción ↓)")
                    .removeClass("hide");
                window.smpUpdatePackCTA();
                return;
            }

            $("#pack-status-" + productId).addClass("hide");
            $("#pack-loader-" + productId).removeClass("hide");
            window.smpUpdatePackCTA();

            $.post("<?php echo admin_url('admin-ajax.php'); ?>", {
                action: "smp_get_variation_price",
                nonce: "<?php echo wp_create_nonce('smp_pack_nonce'); ?>",
                product_id: productId,
                attributes: attributes
            }, function(response) {
                $("#pack-loader-" + productId).addClass("hide");

                if (response.success && response.data) {
                    var hasPrice = response.data.price && response.data.price > 0;
                    var inStock = response.data.in_stock === true;

                    if (hasPrice && inStock) {
                        $("#pack-status-" + productId)
                            .removeClass("pack-status-select pack-status-unavailable hide")
                            .addClass("pack-status-ok")
                            .html("OK!")
                            .show();
                    } else if (hasPrice && !inStock) {
                        $("#pack-status-" + productId)
                            .removeClass("pack-status-select pack-status-ok hide")
                            .addClass("pack-status-unavailable")
                            .html("No disponible (selecciona otra opción ↓)")
                            .show();
                    } else {
                        $("#pack-status-" + productId)
                            .removeClass("pack-status-ok pack-status-unavailable hide")
                            .addClass("pack-status-select")
                            .html("(selecciona opción ↓)")
                            .show();
                    }
                } else {
                    $("#pack-status-" + productId)
                        .removeClass("pack-status-ok pack-status-unavailable hide")
                        .addClass("pack-status-select")
                        .html("(selecciona opción ↓)")
                        .show();
                }
                window.smpUpdatePackCTA();
            }).fail(function(xhr, status, error) {
                console.log("AJAX Error:", error);
                $("#pack-loader-" + productId).addClass("hide");
                $("#pack-status-" + productId)
                    .removeClass("pack-status-ok pack-status-unavailable hide")
                    .addClass("pack-status-select")
                    .html("(selecciona opción ↓)")
                    .show();
                window.smpUpdatePackCTA();
            });
        }

        window.smpUpdatePackCTA();
    });
    </script>
    <?php
}
?>