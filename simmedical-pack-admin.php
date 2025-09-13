<?php
if (!defined('ABSPATH')) exit;

// Formatea precios como CLP, solo para mostrar
function smp_admin_price_format($price) {
    return number_format($price, 0, '', '.') . ' CLP';
}

// Hook para agregar el metabox en productos
add_action('add_meta_boxes', function() {
    add_meta_box(
        'simmedical_pack_admin',
        __('Configurar Pack', 'woocommerce'),
        'simmedical_pack_admin_metabox',
        'product',
        'normal',
        'default'
    );
});

// Mostrar el metabox
function simmedical_pack_admin_metabox($post) {
    $current_id = $post->ID;
    $is_pack = get_post_meta($post->ID, '_is_pack', true);
    echo '<label style="font-weight:600;"><input type="checkbox" name="is_pack" id="is_pack" value="yes" '.checked($is_pack, 'yes', false).'> ¿Este producto es un pack?</label>';

    echo '<div id="pack-config-area" '.($is_pack === 'yes' ? '' : 'style="display:none;"').'>';

    $pack_products   = get_post_meta($post->ID, '_pack_products', true);
    $pack_sale_price = get_post_meta($post->ID, '_pack_sale_price', true);
    $pack_qty_map    = get_post_meta($post->ID, '_pack_qty', true);
    if (!is_array($pack_qty_map)) $pack_qty_map = [];
    $product_ids = $pack_products ? array_filter(array_map('intval', explode(',', $pack_products))) : [];

    // Obtener precios nativos actuales del producto pack (para la nota)
    $product_obj = wc_get_product($post->ID);
    $existing_regular = $product_obj ? $product_obj->get_regular_price() : '';
    $existing_sale    = $product_obj ? $product_obj->get_sale_price()    : '';

    // Listado de productos del pack y sumatorias
    echo '<h4 style="margin-top:1em;">Productos en el Pack:</h4>';

    // Estilos mínimos para el handle y sortable
    echo '<style>
    #pack-products-list .pack-sort-handle { cursor: move; color: #666; }
    #pack-products-list tr.ui-sortable-helper { background: #fffff3; }
    #pack-products-list td, #pack-products-list th { vertical-align: middle; }
    .small-text { width: 70px; }
    </style>';

    echo '<table class="widefat" style="margin-bottom:1em;"><thead>
        <tr>
            <th style="width:32px;">Mover</th>
            <th>Producto</th>
            <th>Precio normal</th>
            <th>Precio rebajado</th>
            <th style="width:120px;">Cantidad</th>
            <th style="width:80px;">Eliminar</th>
        </tr></thead><tbody id="pack-products-list">';

    $sum_normal = 0;
    foreach ($product_ids as $pid) {
        $prod = wc_get_product($pid);
        if ($prod) {
            $edit_link = admin_url('post.php?post=' . $pid . '&action=edit');
            if ($prod->is_type('variable')) {
                $min_price = null;
                $min_sale = null;
                foreach ($prod->get_children() as $vid) {
                    $variation = wc_get_product($vid);
                    if (!$variation) continue;
                    $reg = $variation->get_regular_price();
                    $sale = $variation->get_sale_price();
                    if (is_numeric($reg) && ($min_price === null || $reg < $min_price)) $min_price = $reg;
                    if (is_numeric($sale) && ($min_sale === null || $sale < $min_sale)) $min_sale = $sale;
                }
                $price = $min_price !== null ? floatval($min_price) : null;
                $sale = ($min_sale !== null && $min_sale < $min_price) ? floatval($min_sale) : null;
            } else {
                $price = $prod->get_regular_price() !== '' && is_numeric($prod->get_regular_price()) ? floatval($prod->get_regular_price()) : null;
                $sale = $prod->get_sale_price() !== '' && is_numeric($prod->get_sale_price()) ? floatval($prod->get_sale_price()) : null;
            }

            $qty = isset($pack_qty_map[$pid]) ? max(1, intval($pack_qty_map[$pid])) : 1;

            if ($price !== null && $price !== '') $sum_normal += ($price * $qty);

            echo '<tr data-pid="'.$pid.'" data-normal="'.($price !== null ? $price : '').'" data-sale="'.($sale !== null ? $sale : '').'" data-qty="'.$qty.'">
                <td class="pack-sort-handle"><span class="dashicons dashicons-move"></span></td>
                <td><a href="'.$edit_link.'" target="_blank">'.esc_html($prod->get_name()).'</a></td>
                <td>'.($price !== null ? smp_admin_price_format($price) : '-').'</td>
                <td>'.($sale !== null ? smp_admin_price_format($sale) : '-').'</td>
                <td>
                    <input type="number" min="1" step="1" class="small-text pack-qty-input" name="pack_qty['.$pid.']" value="'.esc_attr($qty).'">
                </td>
                <td><button type="button" class="button remove-pack-product" data-pid="'.$pid.'">✕</button></td>
            </tr>';
        }
    }
    echo '</tbody></table>';

    // Muestra el total de precios hijos debajo de la tabla
    echo '<div style="margin-bottom:1em;background:#f0f7ff;border:1px solid #b2d1ff;padding:8px;border-radius:4px;">';
    echo '<strong>Total productos en pack:&nbsp;</strong><span id="pack-total-normal">'.smp_admin_price_format($sum_normal).'</span></div>';

    // Buscador de productos para agregar al pack
    echo '<h4>Agregar producto:</h4>';
    echo '<input type="search" id="pack-product-search" style="width:80%;" placeholder="Buscar por nombre o SKU...">';
    echo '<div id="pack-product-search-results" style="margin:0.5em 0;"></div>';

    // Precio del pack y porcentaje de descuento
    $pack_price = $pack_sale_price ? floatval($pack_sale_price) : $sum_normal;
    $discount = ($sum_normal > 0 && $pack_price < $sum_normal) ? $sum_normal - $pack_price : 0;
    $percentage = ($sum_normal > 0 && $pack_price < $sum_normal) ? round(($discount / $sum_normal) * 100) : 0;

    echo '<h4>Precio del Pack:</h4>';
    echo '<input type="number" min="0" step="0.01" style="width:140px;" name="pack_sale_price" id="pack_sale_price" value="'.esc_attr($pack_price).'"> ';

    // Porcentaje de descuento
    echo '<span id="pack-discount-info" style="margin-left:10px;color:green;">';
    if ($discount > 0) {
        echo 'Ahorro: '.smp_admin_price_format($discount).' ('.$percentage.'%)';
    }
    echo '</span>';

    // Nota/advertencia de precedencia de precios nativos
    if ($existing_regular !== '' || $existing_sale !== '') {
        echo '<div class="notice inline notice-warning" style="margin-top:10px;">';
        echo '<p><strong>Importante:</strong> Los precios nativos del producto (General &gt; Precio normal / Precio rebajado) están definidos y <u>prevalecen</u> sobre el "Precio del Pack" de este metabox al mostrar el precio del pack y calcular el descuento.</p>';
        echo '<p style="margin-top:6px;">Valores actuales:&nbsp; ';
        echo '<em>Precio normal:</em> ' . ($existing_regular !== '' ? smp_admin_price_format((float) $existing_regular) : '-') . ' &nbsp;|&nbsp; ';
        echo '<em>Precio rebajado:</em> ' . ($existing_sale !== '' ? smp_admin_price_format((float) $existing_sale) : '-') . '</p>';
        echo '</div>';
    } else {
        echo '<div class="notice inline notice-info" style="margin-top:10px;">';
        echo '<p>Si completas los precios nativos del producto (General &gt; Precio normal / Precio rebajado), esos valores <u>prevalecerán</u> sobre el "Precio del Pack" de este metabox.</p>';
        echo '</div>';
    }

    // Campo oculto para guardar ids de productos (en orden)
    echo '<input type="hidden" name="pack_products" id="pack_products" value="'.esc_attr(implode(',', $product_ids)).'">';

    // --- Bloque resumen bien calculado ---
    echo '<div style="margin:1em 0; background: #fffbe5; border:1px solid #ffe580; padding:6px 12px; border-radius:4px;">';
    echo '<strong>Precio normal del pack: </strong><span class="smp-pack-summary-normal">'.smp_admin_price_format($sum_normal).'</span><br>';
    echo '<strong>Precio rebajado del pack: </strong><span class="smp-pack-summary-rebajado">'.smp_admin_price_format($pack_price).'</span>';
    echo '</div>';

    // JS para eliminar/agregar productos, actualizar descuento, ordenar y mostrar/ocultar área según checkbox
    ?>
    <script>
    jQuery(function($){
        $('#is_pack').on('change', function(){
            if($(this).is(':checked')){
                $('#pack-config-area').show();
            }else{
                $('#pack-config-area').hide();
            }
        });

        // Sortable por arrastrar
        if ($.fn.sortable) {
            $('#pack-products-list').sortable({
                handle: '.pack-sort-handle',
                helper: function(e, ui){
                    ui.children().each(function(){ $(this).width($(this).width()); });
                    return ui;
                },
                update: function(){ updateIds(); }
            });
        } else {
            console.warn('jQuery UI Sortable no disponible');
        }

        // Eliminar producto
        function rebindRemoveBtn(){
            $('.remove-pack-product').off('click').on('click', function(){
                var pid = $(this).data('pid');
                $(this).closest('tr').remove();
                updateIds();
                updateTotal();
                updateDiscount();
            });
        }
        rebindRemoveBtn();

        // Cambiar cantidad
        function rebindQtyChange(){
            $('.pack-qty-input').off('input change').on('input change', function(){
                var $tr = $(this).closest('tr');
                var qty = parseInt($(this).val(), 10) || 1;
                if (qty < 1) { qty = 1; $(this).val(1); }
                $tr.attr('data-qty', qty);
                updateTotal();
                updateDiscount();
            });
        }
        rebindQtyChange();

        function updateIds(){
            var ids = [];
            $('#pack-products-list tr').each(function(){ ids.push($(this).data('pid')); });
            $('#pack_products').val(ids.join(','));
        }

        function updateTotal(){
            var total = 0;
            $('#pack-products-list tr').each(function(){
                var normal = parseFloat($(this).data('normal')) || 0;
                var qty = parseInt($(this).attr('data-qty'), 10);
                if (!qty || qty < 1) {
                    var $qtyInput = $(this).find('.pack-qty-input');
                    qty = parseInt($qtyInput.val(), 10) || 1;
                    $(this).attr('data-qty', qty);
                }
                total += normal * qty;
            });
            $('#pack-total-normal').text(total.toLocaleString('es-CL',{style:'currency',currency:'CLP'}));
            $('.smp-pack-summary-normal').text(total.toLocaleString('es-CL',{style:'currency',currency:'CLP'}));
            var pack_price = parseFloat($('#pack_sale_price').val()) || 0;
            $('.smp-pack-summary-rebajado').text(pack_price.toLocaleString('es-CL',{style:'currency',currency:'CLP'}));
            return total;
        }

        // Utilidad: limpiar buscador y resultados
        function clearSearchUI(){
            $('#pack-product-search').val('').focus();
            $('#pack-product-search-results').html('');
        }

        // Buscar y agregar producto
        $('#pack-product-search').on('input', function(){
            var term = $(this).val();
            if(term.length < 3){ $('#pack-product-search-results').html(''); return; }
            $('#pack-product-search-results').html('Buscando...');
            $.post(ajaxurl, {
                action: 'simmedical_pack_product_search',
                term: term,
                current_id: '<?php echo $current_id; ?>'
            }, function(data){
                $('#pack-product-search-results').html(data);
                $('.add-pack-product-btn').off('click').on('click', function(){
                    var pid = $(this).data('pid');
                    var name = $(this).data('name');
                    var price = $(this).data('price');
                    var sale = $(this).data('sale');
                    var link = $(this).data('link');

                    if($('#pack-products-list [data-pid="'+pid+'"]').length == 0){
                        var row = '';
                        row += '<tr data-pid="'+pid+'" data-normal="'+price+'" data-sale="'+sale+'" data-qty="1">';
                        row += '<td class="pack-sort-handle"><span class="dashicons dashicons-move"></span></td>';
                        row += '<td><a href="'+link+'" target="_blank">'+name+'</a></td>';
                        row += '<td>'+(price ? parseFloat(price).toLocaleString("es-CL",{style:"currency",currency:"CLP"}) : "-")+'</td>';
                        row += '<td>'+(sale ? parseFloat(sale).toLocaleString("es-CL",{style:"currency",currency:"CLP"}) : "-")+'</td>';
                        row += '<td><input type="number" min="1" step="1" class="small-text pack-qty-input" name="pack_qty['+pid+']" value="1"></td>';
                        row += '<td><button type="button" class="button remove-pack-product" data-pid="'+pid+'">✕</button></td>';
                        row += '</tr>';
                        $('#pack-products-list').append(row);
                        updateIds();
                        rebindRemoveBtn();
                        rebindQtyChange();
                        updateTotal();
                        updateDiscount();
                    }

                    // Limpiar buscador SIEMPRE tras click en Agregar
                    clearSearchUI();
                });
            });
        });

        $('#pack_sale_price').on('input', function(){
            updateDiscount();
            var pack_price = parseFloat($('#pack_sale_price').val()) || 0;
            $('.smp-pack-summary-rebajado').text(pack_price.toLocaleString('es-CL',{style:'currency',currency:'CLP'}));
        });

        function updateDiscount(){
            var pack_price = parseFloat($('#pack_sale_price').val()) || 0;
            var total = updateTotal();
            var discount = total - pack_price;
            var percent = total > 0 ? Math.round((discount / total) * 100) : 0;
            if(discount > 0){
                $('#pack-discount-info').text('Ahorro: '+discount.toLocaleString('es-CL',{style:'currency',currency:'CLP'})+' ('+percent+'%)').css('color','green');
            }else{
                $('#pack-discount-info').text('').css('color','');
            }
        }
        updateDiscount();
    });
    </script>
    <?php
    echo '</div>';
}

// GUARDADO CORRECTO DE PRECIOS EN CAMPOS NATIVOS Y LIMPIEZA SI NO ES PACK
add_action('woocommerce_process_product_meta', function($post_id){
    $is_pack = isset($_POST['is_pack']) ? $_POST['is_pack'] : get_post_meta($post_id, '_is_pack', true);
    $product = wc_get_product($post_id);

    if (isset($_POST['is_pack'])) {
        update_post_meta($post_id, '_is_pack', $_POST['is_pack']);
    } else {
        update_post_meta($post_id, '_is_pack', 'no');
    }

    if ($is_pack === 'yes') {
        if (isset($_POST['pack_products'])) {
            update_post_meta($post_id, '_pack_products', sanitize_text_field($_POST['pack_products']));
        }

        // Guardar mapa de cantidades por producto hijo
        $pack_qty_map = [];
        if (isset($_POST['pack_qty']) && is_array($_POST['pack_qty'])) {
            foreach ($_POST['pack_qty'] as $pid => $qty) {
                $pid = intval($pid);
                $qty = max(1, intval($qty));
                if ($pid > 0) $pack_qty_map[$pid] = $qty;
            }
        }
        update_post_meta($post_id, '_pack_qty', $pack_qty_map);

        if (isset($_POST['pack_sale_price'])) {
            update_post_meta($post_id, '_pack_sale_price', floatval($_POST['pack_sale_price']));
        }

        $product_ids = isset($_POST['pack_products']) ? array_filter(array_map('intval', explode(',', $_POST['pack_products']))) : [];
        $sum_normal = 0;
        foreach ($product_ids as $pid) {
            $prod = wc_get_product($pid);
            if ($prod) {
                if ($prod->is_type('variable')) {
                    $min_price = null;
                    foreach ($prod->get_children() as $vid) {
                        $variation = wc_get_product($vid);
                        if (!$variation) continue;
                        $reg = $variation->get_regular_price();
                        if (is_numeric($reg) && ($min_price === null || $reg < $min_price)) $min_price = $reg;
                    }
                    $price = $min_price !== null ? floatval($min_price) : 0;
                } else {
                    $price = $prod->get_regular_price() !== '' && is_numeric($prod->get_regular_price()) ? floatval($prod->get_regular_price()) : 0;
                }
                $qty = isset($pack_qty_map[$pid]) ? max(1, intval($pack_qty_map[$pid])) : 1;
                $sum_normal += ($price * $qty);
            }
        }

        $pack_sale_price = isset($_POST['pack_sale_price']) ? floatval($_POST['pack_sale_price']) : 0;

        if ($product) {
            // Dar prioridad a precios nativos si ya existen
            $existing_regular = $product->get_regular_price();
            $existing_sale    = $product->get_sale_price();

            $reg_to_set  = ($existing_regular !== '' && $existing_regular !== null) ? floatval($existing_regular) : floatval($sum_normal);
            $sale_to_set = ($existing_sale !== '' && $existing_sale !== null) ? floatval($existing_sale) : ( $pack_sale_price ? floatval($pack_sale_price) : '' );

            $product->set_regular_price($reg_to_set);
            $product->set_sale_price($sale_to_set);
            $product->set_price($sale_to_set !== '' && $sale_to_set > 0 ? $sale_to_set : $reg_to_set);
            $product->save();
        }
    } else {
        // Si NO es pack, borrar metadatos y restaurar precios a vacío
        delete_post_meta($post_id, '_pack_products');
        delete_post_meta($post_id, '_pack_sale_price');
        delete_post_meta($post_id, '_pack_qty');
        if ($product) {
            $product->set_regular_price('');
            $product->set_sale_price('');
            $product->set_price('');
            $product->save();
        }
    }
}, 20);

// Buscador AJAX de productos (solo nombre o SKU), excluye el producto actual
add_action('wp_ajax_simmedical_pack_product_search', function(){
    $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
    $exclude_id = isset($_POST['current_id']) ? intval($_POST['current_id']) : 0;
    if (!$term) die;
    $args = [
        'post_type' => 'product',
        'post_status' => 'publish',
        'posts_per_page' => 10,
        's' => $term,
        'fields' => 'ids'
    ];
    $query = new WP_Query($args);
    if ($query->have_posts()) {
        foreach ($query->posts as $pid) {
            if ($pid == $exclude_id) continue;
            $prod = wc_get_product($pid);
            if ($prod) {
                if ($prod->is_type('variable')) {
                    $min_price = null;
                    $min_sale = null;
                    foreach ($prod->get_children() as $vid) {
                        $variation = wc_get_product($vid);
                        if (!$variation) continue;
                        $reg = $variation->get_regular_price();
                        $sale = $variation->get_sale_price();
                        if (is_numeric($reg) && ($min_price === null || $reg < $min_price)) $min_price = $reg;
                        if (is_numeric($sale) && ($min_sale === null || $sale < $min_sale)) $min_sale = $sale;
                    }
                    $price = $min_price !== null ? floatval($min_price) : null;
                    $sale = ($min_sale !== null && $min_sale < $min_price) ? floatval($min_sale) : null;
                } else {
                    $price = $prod->get_regular_price() !== '' && is_numeric($prod->get_regular_price()) ? floatval($prod->get_regular_price()) : null;
                    $sale = $prod->get_sale_price() !== '' && is_numeric($prod->get_sale_price()) ? floatval($prod->get_sale_price()) : null;
                }
                $edit_link = admin_url('post.php?post=' . $pid . '&action=edit');
                echo '<div style="padding:2px 0;">
                    <button type="button" class="button add-pack-product-btn" 
                        data-pid="'.$pid.'" 
                        data-name="'.esc_attr($prod->get_name()).'"
                        data-link="'.$edit_link.'"
                        data-price="'.($price !== null ? $price : '').'"
                        data-sale="'.($sale !== null ? $sale : '').'">Agregar</button> 
                    <a href="'.$edit_link.'" target="_blank">'.esc_html($prod->get_name()).'</a>
                    <span style="margin-left:10px;">'.($price !== null ? smp_admin_price_format($price) : '-').'</span>
                    <span style="margin-left:10px;">'.($sale !== null ? smp_admin_price_format($sale) : '-').'</span>
                </div>';
            }
        }
    } else {
        echo '<span style="color:#888;">Sin resultados.</span>';
    }
    wp_die();
});
?>