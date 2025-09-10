jQuery(document).ready(function($) {
    function updatePackChildPrice(product_id) {
        // Recoge todos los atributos seleccionados para este producto variable
        let attributes = {};
        $('[data-product-id="' + product_id + '"]').each(function() {
            let attr = $(this).data('attribute');
            let val = $(this).val();
            attributes[attr] = val;
        });
        
        // Show loading state
        $('#pack-price-' + product_id).html('- (selecciona opción ↓)');
        
        $.ajax({
            url: smp_pack_ajax.ajax_url,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'smp_get_variation_price',
                nonce: smp_pack_ajax.nonce,
                product_id: product_id,
                attributes: attributes
            },
            success: function(response) {
                if (response.success) {
                    let hasPrice = response.data.price > 0;
                    let inStock = response.data.in_stock;
                    
                    if (hasPrice && !inStock) {
                        // Out of stock variation found
                        $('#pack-price-' + product_id).html('- <span style="color: #e74c3c;">No disponible (selecciona otra opción ↓)</span>');
                    } else if (hasPrice && inStock) {
                        // In stock variation
                        $('#pack-price-' + product_id).html('- <span style="color: #27ae60;">OK! ' + response.data.price_html + '</span>');
                    } else {
                        // No variation found or no attributes selected
                        $('#pack-price-' + product_id).html('- (selecciona opción ↓)');
                    }
                    
                    // Actualizar precio normal del pack (opcional: suma de los hijos)
                    // Puedes recorrer todos los .pack-child-price y sumar
                    let total = 0;
                    $('.pack-child-price').each(function() {
                        let text = $(this).text().replace(/[^0-9,.]/g, '').replace('.', '').replace(',', '.');
                        let num = parseFloat(text) || 0;
                        total += num;
                    });
                    $('#pack-normal-price').html('$' + total.toLocaleString());
                }
            }
        });
    }

    $('.pack-variation-select').on('change', function() {
        let product_id = $(this).data('product-id');
        updatePackChildPrice(product_id);
    });
});