jQuery(document).ready(function($) {
    function updatePackChildPrice(product_id) {
        // Recoge todos los atributos seleccionados para este producto variable
        let attributes = {};
        let allSelects = $('[data-product-id="' + product_id + '"]');
        let hasAllRequiredValues = true;
        
        allSelects.each(function() {
            let attr = $(this).data('attribute');
            let val = $(this).val();
            if (val && val !== '') {
                attributes[attr] = val;
            } else {
                hasAllRequiredValues = false;
            }
        });
        
        // Only proceed if we have values for all attributes
        if (!hasAllRequiredValues || Object.keys(attributes).length === 0) {
            // Reset price to $0 if not all attributes are selected
            $('#pack-price-' + product_id).html('- $0');
            return;
        }
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
                    $('#pack-price-' + product_id).html('- ' + response.data.price_html);
                    // Actualizar precio normal del pack (opcional: suma de los hijos)
                    // Puedes recorrer todos los .pack-child-price y sumar
                    let total = 0;
                    $('.pack-child-price').each(function() {
                        let text = $(this).text().replace(/[^0-9,.]/g, '').replace('.', '').replace(',', '.');
                        let num = parseFloat(text) || 0;
                        total += num;
                    });
                    $('#pack-normal-price').html('$' + total.toLocaleString());
                } else {
                    console.error('Error getting variation price:', response);
                    $('#pack-price-' + product_id).html('- $0');
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX error getting variation price:', error);
                $('#pack-price-' + product_id).html('- $0');
            }
        });
    }

    $('.pack-variation-select').on('change', function() {
        let product_id = $(this).data('product-id');
        updatePackChildPrice(product_id);
    });
});