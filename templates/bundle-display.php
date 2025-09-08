<div class="simmedical-bundle-info" data-bundle-id="<?php echo $product->get_id(); ?>">
    
    <?php if ($medical_description): ?>
    <div class="bundle-medical-description">
        <h4 class="bundle-section-title">
            <span class="medical-icon">🩺</span>
            <?php _e('Uso Médico del Pack', 'simmedical'); ?>
        </h4>
        <p class="medical-description-text"><?php echo nl2br(esc_html($medical_description)); ?></p>
    </div>
    <?php endif; ?>
    
    <div class="bundle-content-wrapper">
        <div class="bundle-header">
            <h3 class="bundle-title">
                <span class="medical-icon">📦</span>
                <?php _e('Este pack médico incluye:', 'simmedical'); ?>
            </h3>
            <span class="bundle-count"><?php echo count($bundle_products); ?> <?php _e('productos', 'simmedical'); ?></span>
        </div>
        
        <div class="bundle-products-grid">
            <?php foreach ($bundle_products as $product_id): 
                $bundle_item = wc_get_product($product_id);
                if (!$bundle_item) continue;
                $item_image = $bundle_item->get_image('thumbnail');
            ?>
            <div class="bundle-item" data-product-id="<?php echo $product_id; ?>">
                <div class="item-image">
                    <?php echo $item_image; ?>
                </div>
                <div class="item-details">
                    <h5 class="item-name"><?php echo $bundle_item->get_name(); ?></h5>
                    <span class="item-price"><?php echo wc_price($bundle_item->get_price()); ?></span>
                </div>
                <div class="item-check">
                    <span class="check-icon">✓</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <div class="bundle-pricing">
            <div class="pricing-row total-individual">
                <span class="pricing-label"><?php _e('Precio comprando por separado:', 'simmedical'); ?></span>
                <span class="price-individual"><?php echo wc_price($bundle_total); ?></span>
            </div>
            
            <?php if ($discount_percent > 0): ?>
            <div class="pricing-row bundle-savings">
                <div class="savings-content">
                    <span class="discount-badge">
                        🏥 <?php echo $discount_percent; ?>% <?php _e('descuento médico', 'simmedical'); ?>
                    </span>
                    <span class="savings-amount">
                        <?php _e('Ahorras:', 'simmedical'); ?> <?php echo wc_price($savings_amount); ?>
                    </span>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="pricing-row bundle-final-price">
                <span class="pricing-label-final"><?php _e('Precio del pack médico:', 'simmedical'); ?></span>
                <span class="price-bundle"><?php echo wc_price($bundle_sale); ?></span>
            </div>
            
            <div class="bundle-benefits">
                <div class="benefit-item">
                    <span class="benefit-icon">💰</span>
                    <span><?php _e('Máximo ahorro', 'simmedical'); ?></span>
                </div>
                <div class="benefit-item">
                    <span class="benefit-icon">🚚</span>
                    <span><?php _e('Envío optimizado', 'simmedical'); ?></span>
                </div>
                <div class="benefit-item">
                    <span class="benefit-icon">🩺</span>
                    <span><?php _e('Set completo', 'simmedical'); ?></span>
                </div>
            </div>
        </div>
    </div>
</div>