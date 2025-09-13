<?php
if (!defined('ABSPATH')) exit;

class SimMedical_WCPA_Integration {

    public function __construct() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_ajax_smp_get_wcpa_form', [$this, 'ajax_get_wcpa_form']);
        add_action('wp_ajax_nopriv_smp_get_wcpa_form', [$this, 'ajax_get_wcpa_form']);
        add_action('wp_footer', [$this, 'print_inline_bootstrap'], 20);
    }

    public function enqueue_frontend_assets() {
        if (!is_product()) return;

        wp_register_script('smp-pack-wcpa-dummy', false);
        wp_enqueue_script('smp-pack-wcpa-dummy');
        wp_add_inline_script('smp-pack-wcpa-dummy', 'window.smpWcpaAjax = ' . wp_json_encode([
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('smp_wcpa_nonce'),
        ]) . ';', 'before');
    }

    public function print_inline_bootstrap() {
        if (!is_product()) return;

        global $product;
        if (!$product || 'yes' !== get_post_meta($product->get_id(), '_is_pack', true)) return;
        ?>
        <style>
        .smp-wcpa-slot { margin-top: 12px; }
        .smp-wcpa-slot-loading { opacity: .6; pointer-events: none; }
        </style>
        <script>
        (function($){
            if (!window.smpWcpaAjax) return;

            var timers = {};

            function packForm() { return $('.pack-add-to-cart-form').first(); }

            function ensureSlot(productId){
                var $selectors = $('.pack-variation-selectors[data-product-id="'+productId+'"]');
                if (!$selectors.length) {
                    var $status = $('#pack-status-' + productId).closest('.pack-child-status-area');
                    if ($status.length && !$('#smp-wcpa-slot-'+productId).length) {
                        $status.after('<div class="smp-wcpa-slot" id="smp-wcpa-slot-'+productId+'" data-smp-wcpa-for="'+productId+'"></div>');
                    }
                    return $('#smp-wcpa-slot-'+productId);
                }
                var $slot = $('#smp-wcpa-slot-'+productId);
                if (!$slot.length) {
                    $slot = $('<div class="smp-wcpa-slot" id="smp-wcpa-slot-'+productId+'" data-smp-wcpa-for="'+productId+'"></div>');
                    $selectors.after($slot);
                }
                return $slot;
            }

            function ensureHiddenStore(productId){
                var $form = packForm();
                var sel = 'input[type="hidden"][name="smp_wcpa_child['+productId+']"]';
                var $h = $form.find(sel);
                if (!$h.length) {
                    $h = $('<input type="hidden" data-smp-wcpa-store="'+productId+'" name="smp_wcpa_child['+productId+']" />');
                    $form.append($h);
                }
                return $h;
            }

            function serializeSlot($slot){
                var $inputs = $slot.find(':input[name]').filter(function(){
                    var $el = $(this);
                    if ($el.is('[type=button], [type=submit], [type=reset]')) return false;
                    if ($el.closest('[data-smp-wcpa-store]').length) return false;
                    return true;
                });
                return $inputs.serializeArray();
            }

            function restoreSlot($slot, serialized){
                if (!serialized || !serialized.length) return;
                var grouped = {};
                serialized.forEach(function(it){
                    if (!grouped[it.name]) grouped[it.name] = [];
                    grouped[it.name].push(it.value);
                });
                Object.keys(grouped).forEach(function(name){
                    var values = grouped[name];

                    var $radios = $slot.find('input[type="radio"][name="'+name+'"]');
                    if ($radios.length) {
                        $radios.prop('checked', false);
                        $radios.each(function(){
                            if (values.indexOf($(this).val()) !== -1) $(this).prop('checked', true);
                        });
                    }

                    var $checks = $slot.find('input[type="checkbox"][name="'+name+'"]');
                    if ($checks.length) {
                        $checks.prop('checked', false);
                        $checks.each(function(){
                            if (values.indexOf($(this).val()) !== -1) $(this).prop('checked', true);
                        });
                    }

                    var $els = $slot.find(':input[name="'+name+'"]').not('[type=radio],[type=checkbox]');
                    if ($els.length) {
                        if ($els.is('select[multiple]')) {
                            $els.val(values).trigger('change');
                        } else {
                            $els.val(values[0]).trigger('change');
                        }
                    }
                });
            }

            function writeHiddenStore(productId, serialized){
                var $h = ensureHiddenStore(productId);
                try { $h.val(JSON.stringify(serialized || [])); } catch(e) { $h.val('[]'); }
            }

            function readHiddenStore(productId){
                var $h = ensureHiddenStore(productId);
                var raw = $h.val();
                if (!raw) return [];
                try { var arr = JSON.parse(raw); if (Array.isArray(arr)) return arr; } catch(e){}
                return [];
            }

            // Ejecuta scripts inline del HTML inyectado (necesario para algunos formularios)
            function executeInlineScripts($ctx){
                try{
                    $ctx.find('script').each(function(){
                        var txt = this.text || this.textContent || this.innerHTML || '';
                        if (txt && $.trim(txt).length) {
                            $.globalEval(txt);
                        }
                    });
                }catch(e){}
            }

            // Re-evalúa condiciones WCPA tras cualquier cambio en el slot
            function runWcpaConditions($ctx){
                try {
                    $(document.body).trigger('wcpa_field_changed', [$ctx.get(0)]);
                    $(document.body).trigger('wcpa_updated');
                    $(document).trigger('wcpa_product_modal_ready');

                    if (window.wcpa_front) {
                        if (typeof window.wcpa_front.init === 'function') window.wcpa_front.init();
                        if (typeof window.wcpa_front.apply_conditional_rules === 'function') window.wcpa_front.apply_conditional_rules($ctx);
                        if (typeof window.wcpa_front.apply_condition === 'function') window.wcpa_front.apply_condition($ctx);
                        if (typeof window.wcpa_front.action_triggers === 'function') window.wcpa_front.action_triggers($ctx);
                    }

                    // Segundo tick por si hay cálculos diferidos
                    setTimeout(function(){
                        if (window.wcpa_front && typeof window.wcpa_front.init === 'function') window.wcpa_front.init();
                        $(document.body).trigger('wcpa_updated');
                    }, 0);
                } catch(e) {}
            }

            function bindStoreUpdates($slot, productId){
                $slot.off('.smpWcpa');
                $slot.on('input.smpWcpa change.smpWcpa', ':input[name]', function(){
                    writeHiddenStore(productId, serializeSlot($slot));
                    runWcpaConditions($slot);
                });
                writeHiddenStore(productId, serializeSlot($slot));
                runWcpaConditions($slot);
            }

            function htmlHasRenderableInputs(html) {
                if (!html) return false;
                var $tmp = $('<div>').html(html);
                var $real = $tmp.find(':input[name]').filter(function(){
                    var type = this.type ? this.type.toLowerCase() : '';
                    if (type === 'hidden' || type === 'submit' || type === 'button' || type === 'reset') return false;
                    return true;
                });
                var hasContainers = $tmp.find('.wcpa_form_outer, .wcpa_wrap, .wcpa-container, .wcpa_form').length > 0;
                return $real.length > 0 || hasContainers;
            }

            function doAjaxLoad(productId){
                var $selectors = $('.pack-variation-selectors[data-product-id="'+productId+'"]');
                var $slot = ensureSlot(productId);
                if (!$slot.length) return;

                var payload = {
                    action: 'smp_get_wcpa_form',
                    nonce: window.smpWcpaAjax.nonce,
                    product_id: productId
                };

                if ($selectors.length) {
                    var state = collectAttributes($selectors, productId);
                    if (state.complete) payload.attributes = state.attrs;
                }

                var prevSerialized = serializeSlot($slot);
                var prevHtml = $slot.html();

                $slot.addClass('smp-wcpa-slot-loading');

                $.post(window.smpWcpaAjax.ajax_url, payload, function(resp){
                    $slot.removeClass('smp-wcpa-slot-loading');

                    var replaced = false;
                    var html = resp && resp.success && resp.data ? (resp.data.html || '') : '';

                    if (htmlHasRenderableInputs(html)) {
                        $slot.html(html);
                        executeInlineScripts($slot);
                        replaced = true;
                    }

                    if (!replaced) {
                        $slot.html(prevHtml);
                    }

                    // Double-check: si quedó sin inputs, revertir
                    if (!htmlHasRenderableInputs($slot.html())) {
                        $slot.html(prevHtml);
                    }

                    if (!prevSerialized || !prevSerialized.length) {
                        prevSerialized = readHiddenStore(productId);
                    }
                    restoreSlot($slot, prevSerialized);
                    bindStoreUpdates($slot, productId);
                }).fail(function(){
                    $slot.removeClass('smp-wcpa-slot-loading');
                    bindStoreUpdates($slot, productId);
                });
            }

            function collectAttributes($container, productId){
                var attrs = {};
                var complete = true;
                $container.find('.pack-attr-input').each(function(){
                    var name = $(this).attr('name');
                    var key  = name.replace('pack_attr_' + productId + '_', '');
                    var val  = $(this).val();
                    attrs[key] = val;
                    if(!val) complete = false;
                });
                return {attrs: attrs, complete: complete};
            }

            function loadWcpaForm(productId){
                clearTimeout(timers[productId]);
                timers[productId] = setTimeout(function(){ doAjaxLoad(productId); }, 120);
            }

            // Inicial
            $('.pack-child-status-area [id^="pack-status-"]').each(function(){
                var productId = $(this).data('product-id');
                loadWcpaForm(productId);
            });

            $(document).on('click', '.pack-attr-btn', function(){
                var pid = $(this).data('product-id');
                loadWcpaForm(pid);
            });
            $(document).on('change', '.pack-attr-input', function(){
                var pid = $(this).data('product-id');
                loadWcpaForm(pid);
            });

        })(jQuery);
        </script>
        <?php
    }

    public function ajax_get_wcpa_form() {
        check_ajax_referer('smp_wcpa_nonce', 'nonce');

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $attributes = isset($_POST['attributes']) ? (array) $_POST['attributes'] : [];

        if (!$product_id) wp_send_json_success(['html' => '']);

        $product = wc_get_product($product_id);
        if (!$product) wp_send_json_success(['html' => '']);

        $target_id = $product_id;

        if ($product->is_type('variable') && !empty($attributes)) {
            $variation = $this->find_matching_variation($product, $attributes);
            if ($variation) $target_id = $variation->get_id();
        }

        // Formularios: primero variación, luego producto
        $form_ids_variation = $this->get_wcpa_form_ids($target_id);
        $form_ids_product   = $this->get_wcpa_form_ids($product_id);

        // Si no hay formularios en ningún lado, no renderizamos nada (ni hooks).
        if (empty($form_ids_variation) && empty($form_ids_product)) {
            wp_send_json_success(['html' => '']);
        }

        $html = '';

        if (!empty($form_ids_variation)) {
            $html = $this->render_forms_with_context($form_ids_variation, $target_id);
        } elseif (!empty($form_ids_product)) {
            $html = $this->render_forms_with_context($form_ids_product, $product_id);
        }

        // Fallback por hooks SOLO si se habilita explícitamente.
        if (empty($html) && apply_filters('smp_wcpa_enable_hooks_fallback', false, $product_id, $target_id)) {
            $html = $this->render_via_wc_hooks($target_id);
            if (empty($html) && $product->is_type('variable')) {
                $html = $this->render_via_wc_hooks($product_id);
            }
        }

        wp_send_json_success(['html' => $html]);
    }

    private function render_forms_with_context($form_ids, $context_product_id) {
        if (empty($form_ids) || !$context_product_id) return '';

        global $product, $post;
        $old_product = $product ?? null;
        $old_post    = $post ?? null;

        $post    = get_post($context_product_id);
        $product = wc_get_product($context_product_id);
        if ($post) setup_postdata($post);

        ob_start();
        foreach ($form_ids as $fid) {
            $fid = absint($fid);
            if ($fid) echo do_shortcode('[wcpa_form id="' . $fid . '"]');
        }
        $html = ob_get_clean();

        // Restaurar
        $product = $old_product;
        $post    = $old_post;
        if ($old_post) setup_postdata($old_post); else wp_reset_postdata();

        return $html;
    }

    private function get_wcpa_form_ids($post_id) {
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

    private function render_via_wc_hooks($target_product_id) {
        $target_product = wc_get_product($target_product_id);
        if (!$target_product) return '';

        global $product, $post;
        $old_product = $product ?? null;
        $old_post    = $post ?? null;

        $post = get_post($target_product_id);
        $product = $target_product;
        setup_postdata($post);

        ob_start();
        do_action('woocommerce_before_add_to_cart_form');
        do_action('woocommerce_before_add_to_cart_button');
        do_action('woocommerce_after_add_to_cart_button');
        do_action('woocommerce_after_add_to_cart_form');
        $html = ob_get_clean();

        $product = $old_product;
        $post    = $old_post;
        if ($old_post) setup_postdata($old_post); else wp_reset_postdata();

        return $html;
    }

    // Normalización consistente con el bundle
    private function find_matching_variation($variable_product, $attributes) {
        if (empty($attributes) || !$variable_product || !$variable_product->is_type('variable')) return false;

        $attributes = (array) $attributes;

        foreach ($variable_product->get_children() as $variation_id) {
            $var_attrs = wc_get_product_variation_attributes($variation_id); // keys: attribute_pa_xxx / attribute_xxx
            if (empty($var_attrs)) continue;

            $found = true;
            foreach ($attributes as $attr_key => $attr_value) {
                $key_sanit = sanitize_title($attr_key); // admite 'pa_color' o 'attribute_pa_color'
                $attr_key_norm = (strpos($key_sanit, 'attribute_') === 0) ? $key_sanit : ('attribute_' . $key_sanit);

                $var_val = isset($var_attrs[$attr_key_norm]) ? $var_attrs[$attr_key_norm] : '';

                if ((string) $var_val !== (string) $attr_value) { $found = false; break; }
            }

            if ($found) return wc_get_product($variation_id);
        }

        return false;
    }
}

new SimMedical_WCPA_Integration();