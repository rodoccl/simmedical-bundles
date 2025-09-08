jQuery(document).ready(function($){
    let $container = $('#selected_pack_products');
    let $hidden = $('#_pack_products');
    let $search = $('#pack_product_search');
    let $total_info = $('#pack_total_info');
    let $sale_price = $('#_pack_sale_price');

    let $regular_price = $('#_regular_price');
    let $sale_price_native = $('#_sale_price');

    // CLP format: $12.590 (sin decimales, punto miles)
    function formatCLP(valor) {
        valor = Math.round(parseFloat(valor) || 0);
        return '$' + valor.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ".");
    }

    function refreshPackProducts() {
        $.post(smp_pack_admin_ajax.ajax_url, {
            action: 'smp_get_pack_products',
            product_ids: $hidden.val(),
            nonce: smp_pack_admin_ajax.nonce
        }, function(resp){
            if(resp.success){
                let html = '';
                if(resp.data.products.length){
                    html += '<ul style="list-style:none;padding-left:0;">';
                    $.each(resp.data.products, function(i,prod){
                        html += '<li style="margin-bottom:5px;border-bottom:1px solid #eaeaea;padding-bottom:5px;">';
                        html += '<strong><a href="'+prod.edit_url+'" target="_blank">'+prod.name+'</a></strong> ';
                        html += '<span style="color:#2271b1;">'+formatCLP(prod.price)+'</span>';
                        html += ' <a href="#" class="remove-pack-product" data-id="'+prod.id+'" style="color:red;text-decoration:none;margin-left:10px;" title="Quitar">&#10006;</a>';
                        html += '</li>';
                    });
                    html += '</ul>';
                } else {
                    html = '<em>No hay productos en el pack</em>';
                }
                $container.html(html);
                refreshTotals();
            }
        });
    }

    function refreshTotals() {
        $.post(smp_pack_admin_ajax.ajax_url, {
            action: 'smp_calculate_pack_total',
            product_ids: $hidden.val(),
            pack_sale_price: $sale_price.val(),
            nonce: smp_pack_admin_ajax.nonce
        }, function(resp){
            if(resp.success){
                $('#pack_individual_total').html(formatCLP(resp.data.individual_total));
                $('#pack_price_display').html(formatCLP(resp.data.pack_price));
                $('#pack_savings').html(formatCLP(resp.data.ahorro));
                let badge = '';
                if(resp.data.porcentaje > 0) {
                    badge = '<span style="background:#6fcf97;color:#fff;padding:2px 12px;border-radius:12px;font-weight:700;margin-left:10px;">-'+resp.data.porcentaje+'%</span>';
                }
                $('#pack_discount_badge').html(badge);

                if($regular_price.length) $regular_price.val(resp.data.individual_total);
                if($sale_price_native.length) $sale_price_native.val(resp.data.pack_price);
            }
        });
    }

    $container.on('click', '.remove-pack-product', function(e){
        e.preventDefault();
        let id = $(this).data('id');
        let ids = $hidden.val().split(',').filter(function(i){return i && parseInt(i) !== parseInt(id);});
        $hidden.val(ids.join(','));
        refreshPackProducts();
    });

    $search.autocomplete({
        source: function(request, response){
            $.post(smp_pack_admin_ajax.ajax_url, {
                action: 'smp_search_products',
                query: request.term,
                nonce: smp_pack_admin_ajax.nonce
            }, function(resp){
                if(resp.success){
                    response($.map(resp.data, function(prod){
                        return {
                            label: prod.name + ' - ' + formatCLP(prod.price),
                            value: prod.name,
                            id: prod.id
                        };
                    }));
                }
            });
        },
        minLength: 2,
        select: function(event, ui){
            let ids = $hidden.val().split(',').filter(function(i){return i;});
            if(ids.indexOf(String(ui.item.id)) === -1){
                ids.push(ui.item.id);
                $hidden.val(ids.join(','));
                refreshPackProducts();
            }
            $(this).val('');
            return false;
        }
    });

    $sale_price.on('input', function(){
        refreshTotals();
    });

    refreshPackProducts();
});