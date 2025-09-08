jQuery(document).ready(function($) {
    function updatePackChildPrice(product_id) {
        // Recoge todos los atributos seleccionados para este producto variable
        let attributes = {};
        $('[data-product-id="' + product_id + '"]').each(function() {
            let attr = $(this).data('attribute');
            let val = $(this).val();
            attributes[attr] = val;
        });
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
                }
            }
        });
    }

    $('.pack-variation-select').on('change', function() {
        let product_id = $(this).data('product-id');
        updatePackChildPrice(product_id);
    });
});