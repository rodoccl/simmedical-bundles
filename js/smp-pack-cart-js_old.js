jQuery(function($){
    function showPackModal(continueCallback) {
        if ($('#packRemoveModal').length === 0) {
            $('body').append(
                '<div id="packRemoveModal" style="position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:10000;background:rgba(0,0,0,0.12);display:flex;align-items:center;justify-content:center;">' +
                    '<div style="background:#fff; border: 2px solid #c20000; border-radius:10px; padding:32px 24px; max-width:370px; box-shadow:0 6px 24px rgba(0,0,0,0.08); text-align:center;">' +
                        '<div style="font-size:1.1em;color:#222;margin-bottom:18px;">Si eliminas un producto del pack, el precio podría variar.</div>' +
                        '<div style="display:flex; gap:16px; justify-content:center;">' +
                            '<button id="packRemoveCancel" style="background:#fff; color:#c20000; border:1px solid #c20000; padding:7px 18px; border-radius:6px; font-weight:500; cursor:pointer;">Mantener producto</button>' +
                            '<button id="packRemoveConfirm" style="background:#c20000; color:#fff; border:none; padding:7px 18px; border-radius:6px; font-weight:500; cursor:pointer;">Eliminar producto</button>' +
                        '</div>' +
                    '</div>' +
                '</div>'
            );
        } else {
            $('#packRemoveModal').show();
        }
        $('#packRemoveCancel').off('click').on('click', function() {
            $('#packRemoveModal').hide();
        });
        $('#packRemoveConfirm').off('click').on('click', function() {
            $('#packRemoveModal').hide();
            if (typeof continueCallback === 'function') continueCallback();
        });
    }

    // Carrito clásico WooCommerce
    $('form.woocommerce-cart-form').on('click', '.remove', function(e) {
        var $row = $(this).closest('tr.cart_item');
        var isPack = $row.find('.product-name small').filter(function() {
            return $(this).text().includes('Del pack');
        }).length > 0;
        if (!isPack) return;

        e.preventDefault();
        var href = $(this).attr('href');
        showPackModal(function(){ window.location = href; });
    });

    // Mini-cart Blocksy y otros themes
    $(document).on('click', '.woocommerce-mini-cart .remove, .widget_shopping_cart .remove, .wd-empty-cart .remove', function(e) {
        var $item = $(this).closest('.woocommerce-mini-cart-item, .cart_list li, .wd-empty-cart');
        var isPackMini = false;
        $item.find('.mini_cart_item .product-name, .product-name, .product-title, .product__title').each(function() {
            if ($(this).text().includes('Del pack')) isPackMini = true;
        });
        if (!isPackMini) return;

        e.preventDefault();
        var href = $(this).attr('href');
        showPackModal(function(){ window.location = href; });
    });
});