jQuery(document).ready(function($) {
    // Select2 autocompletado de productos
    $('#bundle_products').select2({
        ajax: {
            url: smb_admin.ajax_url,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    action: 'smb_search_products',
                    q: params.term,
                    nonce: smb_admin.nonce
                };
            },
            processResults: function(data) {
                return data;
            },
            cache: true
        },
        minimumInputLength: 2,
        width: 'style',
        allowClear: true,
        placeholder: 'Buscar productos para el pack...'
    });

    // Calcular precio total automáticamente
    function calculateBundleTotal() {
        const selectedProducts = $('#bundle_products').val();
        if (selectedProducts && selectedProducts.length > 0) {
            $.post(smb_admin.ajax_url, {
                action: 'smp_calculate_pack_total',
                products: selectedProducts,
                nonce: smb_admin.nonce
            }, function(response) {
                if (response.success) {
                    $('#_bundle_total_price').val(response.data.total);
                    updateDiscountDisplay();
                    showProductsPreview(response.data.products);
                    $('#_pack_products').val(selectedProducts.join(','));
                }
            });
        } else {
            $('#_bundle_total_price').val('0');
            updateDiscountDisplay();
            $('.smb-products-preview').remove();
            $('#_pack_products').val('');
        }
    }

    // Actualizar display de descuento
    function updateDiscountDisplay() {
        const totalPrice = parseFloat($('#_bundle_total_price').val()) || 0;
        const salePrice = parseFloat($('#_pack_sale_price').val()) || 0;
        if (totalPrice > 0 && salePrice > 0 && salePrice < totalPrice) {
            const discount = ((totalPrice - salePrice) / totalPrice) * 100;
            const savings = totalPrice - salePrice;
            $('#bundle_discount_display').html(
                `<div class="smb-discount-info-active">
                    <span class="discount-percent">🏥 ${discount.toFixed(1)}% descuento médico</span>
                    <span class="savings-amount">Ahorro: ${smb_admin.currency_symbol}${savings.toFixed(0)}</span>
                    <span class="medical-benefit">Ideal para consultorios</span>
                </div>`
            );
        } else if (salePrice >= totalPrice && totalPrice > 0) {
            $('#bundle_discount_display').html(
                '<span style="color: #dc3545;">⚠️ El precio del pack debe ser menor al total individual</span>'
            );
        } else {
            $('#bundle_discount_display').html('<span style="color: #6c757d;">Configure el precio del pack</span>');
        }
    }

    // Preview de productos seleccionados
    function showProductsPreview(products) {
        if (!products || products.length === 0) return;
        let previewHtml = '<div class="smb-products-preview"><h4>🩺 Productos en este pack médico:</h4><ul>';
        products.forEach(product => {
            previewHtml += `<li><strong>${product.name}</strong> - ${product.formatted_price}</li>`;
        });
        previewHtml += '</ul></div>';
        $('.smb-products-preview').remove();
        $('.simmedical_bundle_options').append(previewHtml);
    }

    // Event listeners
    $('#bundle_products').on('change', calculateBundleTotal);
    $('#_pack_sale_price').on('input', updateDiscountDisplay);

    // Mostrar/ocultar campos según checkbox
    $('#_is_pack').on('change', function() {
        if ($(this).is(':checked')) {
            $('.simmedical_bundle_options .form-field:not(:first)').slideDown(300);
            $('.simmedical_bundle_options').addClass('bundle-active');
        } else {
            $('.simmedical_bundle_options .form-field:not(:first)').slideUp(300);
            $('.simmedical_bundle_options').removeClass('bundle-active');
            $('.smb-products-preview').remove();
            $('#_pack_products').val('');
        }
    }).trigger('change');

    // Validación en tiempo real
    $('#_pack_sale_price').on('blur', function() {
        const salePrice = parseFloat($(this).val()) || 0;
        const totalPrice = parseFloat($('#_bundle_total_price').val()) || 0;
        if (salePrice >= totalPrice && totalPrice > 0) {
            $(this).css('border-color', '#dc3545');
            alert('⚠️ El precio del pack médico debe ser menor al precio total para generar descuento.');
        } else {
            $(this).css('border-color', '');
        }
    });

    // Inicialización automática de precios si ya hay productos seleccionados
    if ($('#bundle_products').val() && $('#bundle_products').val().length > 0) {
        calculateBundleTotal();
    }
});