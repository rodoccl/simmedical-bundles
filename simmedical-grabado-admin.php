<?php
if (!defined('ABSPATH')) exit;

/**
 * Grabado (Laser Engraving) Management System
 * Manages grabado configurations with name, unique ID, and product associations
 */

// Add admin menu for grabado management
add_action('admin_menu', 'simmedical_grabado_admin_menu');

function simmedical_grabado_admin_menu() {
    add_submenu_page(
        'edit.php?post_type=product',
        __('Grabados Láser', 'woocommerce'),
        __('Grabados Láser', 'woocommerce'),
        'manage_woocommerce',
        'simmedical-grabados',
        'simmedical_grabado_admin_page'
    );
}

// Admin page content
function simmedical_grabado_admin_page() {
    // Handle form submissions
    if (isset($_POST['action'])) {
        simmedical_handle_grabado_actions();
    }

    $grabados = simmedical_get_all_grabados();
    ?>
    <div class="wrap">
        <h1><?php _e('Gestión de Grabados Láser', 'woocommerce'); ?></h1>
        <style>
        .grabado-admin .card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin-bottom: 20px; }
        .grabado-admin .form-table th { width: 150px; }
        .grabado-admin .regular-text { width: 300px; }
        .grabado-admin .wp-list-table .column-id { width: 120px; }
        .grabado-admin .wp-list-table .column-actions { width: 100px; }
        .grabado-admin code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; }
        </style>
        <div class="grabado-admin">
        
        <?php simmedical_display_grabado_notices(); ?>
        
        <!-- Add New Grabado Form -->
        <div class="card" style="max-width: 500px; margin-bottom: 20px;">
            <h2><?php _e('Agregar Nuevo Grabado', 'woocommerce'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('simmedical_grabado_action', 'grabado_nonce'); ?>
                <input type="hidden" name="action" value="add_grabado">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="grabado_name"><?php _e('Nombre del Grabado', 'woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="grabado_name" name="grabado_name" class="regular-text" required>
                            <p class="description"><?php _e('Ingresa un nombre descriptivo para este grabado.', 'woocommerce'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Crear Grabado', 'woocommerce')); ?>
            </form>
        </div>

        <!-- Existing Grabados List -->
        <div class="card">
            <h2><?php _e('Grabados Existentes', 'woocommerce'); ?></h2>
            <?php if (!empty($grabados)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th scope="col"><?php _e('ID', 'woocommerce'); ?></th>
                            <th scope="col"><?php _e('Nombre', 'woocommerce'); ?></th>
                            <th scope="col"><?php _e('Productos Asociados', 'woocommerce'); ?></th>
                            <th scope="col"><?php _e('Acciones', 'woocommerce'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($grabados as $grabado): ?>
                            <tr>
                                <td><code><?php echo esc_html($grabado['id']); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($grabado['name']); ?></strong>
                                    <div class="row-actions">
                                        <span class="edit">
                                            <a href="#" onclick="showEditForm('<?php echo esc_js($grabado['id']); ?>', '<?php echo esc_js($grabado['name']); ?>')"><?php _e('Editar', 'woocommerce'); ?></a>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                    $product_count = simmedical_count_grabado_products($grabado['id']);
                                    echo $product_count . ' ' . _n('producto', 'productos', $product_count, 'woocommerce');
                                    ?>
                                </td>
                                <td>
                                    <form method="post" style="display: inline;" onsubmit="return confirm('<?php _e('¿Estás seguro? Esto eliminará el grabado y todas sus asociaciones de productos.', 'woocommerce'); ?>')">
                                        <?php wp_nonce_field('simmedical_grabado_action', 'grabado_nonce'); ?>
                                        <input type="hidden" name="action" value="delete_grabado">
                                        <input type="hidden" name="grabado_id" value="<?php echo esc_attr($grabado['id']); ?>">
                                        <button type="submit" class="button button-link-delete"><?php _e('Eliminar', 'woocommerce'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php _e('No hay grabados configurados aún.', 'woocommerce'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Edit Form (Hidden by default) -->
        <div id="edit-grabado-form" class="card" style="display: none; max-width: 500px; margin-top: 20px;">
            <h2><?php _e('Editar Grabado', 'woocommerce'); ?></h2>
            <form method="post" action="">
                <?php wp_nonce_field('simmedical_grabado_action', 'grabado_nonce'); ?>
                <input type="hidden" name="action" value="edit_grabado">
                <input type="hidden" id="edit_grabado_id" name="grabado_id" value="">
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="edit_grabado_name"><?php _e('Nombre del Grabado', 'woocommerce'); ?></label>
                        </th>
                        <td>
                            <input type="text" id="edit_grabado_name" name="grabado_name" class="regular-text" required>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <?php submit_button(__('Actualizar Grabado', 'woocommerce'), 'primary', 'submit', false); ?>
                    <button type="button" class="button" onclick="hideEditForm()"><?php _e('Cancelar', 'woocommerce'); ?></button>
                </p>
            </form>
        </div>
        </div>
    </div>

    <script>
    function showEditForm(id, name) {
        document.getElementById('edit_grabado_id').value = id;
        document.getElementById('edit_grabado_name').value = name;
        document.getElementById('edit-grabado-form').style.display = 'block';
        document.getElementById('edit_grabado_name').focus();
    }

    function hideEditForm() {
        document.getElementById('edit-grabado-form').style.display = 'none';
        document.getElementById('edit_grabado_id').value = '';
        document.getElementById('edit_grabado_name').value = '';
    }
    </script>
    <?php
}

// Handle grabado CRUD operations
function simmedical_handle_grabado_actions() {
    if (!wp_verify_nonce($_POST['grabado_nonce'], 'simmedical_grabado_action')) {
        wp_die('Invalid nonce');
    }

    $action = sanitize_text_field($_POST['action']);

    switch ($action) {
        case 'add_grabado':
            $name = sanitize_text_field($_POST['grabado_name']);
            if (!empty($name)) {
                $grabado_id = simmedical_create_grabado($name);
                if ($grabado_id) {
                    add_settings_error('simmedical_grabados', 'grabado_created', 
                        sprintf(__('Grabado "%s" creado exitosamente con ID: %s', 'woocommerce'), $name, $grabado_id), 'success');
                } else {
                    add_settings_error('simmedical_grabados', 'grabado_error', 
                        __('Error al crear el grabado.', 'woocommerce'), 'error');
                }
            }
            break;

        case 'edit_grabado':
            $grabado_id = sanitize_text_field($_POST['grabado_id']);
            $name = sanitize_text_field($_POST['grabado_name']);
            if (!empty($grabado_id) && !empty($name)) {
                if (simmedical_update_grabado($grabado_id, $name)) {
                    add_settings_error('simmedical_grabados', 'grabado_updated', 
                        __('Grabado actualizado exitosamente.', 'woocommerce'), 'success');
                } else {
                    add_settings_error('simmedical_grabados', 'grabado_error', 
                        __('Error al actualizar el grabado.', 'woocommerce'), 'error');
                }
            }
            break;

        case 'delete_grabado':
            $grabado_id = sanitize_text_field($_POST['grabado_id']);
            if (!empty($grabado_id)) {
                if (simmedical_delete_grabado($grabado_id)) {
                    add_settings_error('simmedical_grabados', 'grabado_deleted', 
                        __('Grabado eliminado exitosamente junto con todas sus asociaciones.', 'woocommerce'), 'success');
                } else {
                    add_settings_error('simmedical_grabados', 'grabado_error', 
                        __('Error al eliminar el grabado.', 'woocommerce'), 'error');
                }
            }
            break;
    }
}

// Display admin notices
function simmedical_display_grabado_notices() {
    settings_errors('simmedical_grabados');
}

// CRUD Functions for Grabados
function simmedical_create_grabado($name) {
    $grabados = get_option('simmedical_grabados', array());
    
    // Generate unique ID
    $grabado_id = 'grab_' . uniqid();
    
    // Ensure unique ID
    while (isset($grabados[$grabado_id])) {
        $grabado_id = 'grab_' . uniqid();
    }
    
    $grabados[$grabado_id] = array(
        'id' => $grabado_id,
        'name' => $name,
        'created_at' => current_time('mysql'),
        'updated_at' => current_time('mysql')
    );
    
    return update_option('simmedical_grabados', $grabados) ? $grabado_id : false;
}

function simmedical_get_all_grabados() {
    return get_option('simmedical_grabados', array());
}

function simmedical_get_grabado($grabado_id) {
    $grabados = get_option('simmedical_grabados', array());
    return isset($grabados[$grabado_id]) ? $grabados[$grabado_id] : false;
}

function simmedical_update_grabado($grabado_id, $name) {
    $grabados = get_option('simmedical_grabados', array());
    
    if (!isset($grabados[$grabado_id])) {
        return false;
    }
    
    $grabados[$grabado_id]['name'] = $name;
    $grabados[$grabado_id]['updated_at'] = current_time('mysql');
    
    return update_option('simmedical_grabados', $grabados);
}

function simmedical_delete_grabado($grabado_id) {
    $grabados = get_option('simmedical_grabados', array());
    
    if (!isset($grabados[$grabado_id])) {
        return false;
    }
    
    // Remove grabado
    unset($grabados[$grabado_id]);
    
    // Remove all product associations for this grabado
    simmedical_remove_all_grabado_associations($grabado_id);
    
    return update_option('simmedical_grabados', $grabados);
}

// Product-Grabado Association Functions
function simmedical_associate_product_with_grabado($product_id, $grabado_id) {
    return update_post_meta($product_id, '_grabado_id', $grabado_id);
}

function simmedical_remove_product_grabado_association($product_id) {
    return delete_post_meta($product_id, '_grabado_id');
}

function simmedical_remove_all_grabado_associations($grabado_id) {
    global $wpdb;
    
    // Remove all product associations for this grabado
    $wpdb->delete(
        $wpdb->postmeta,
        array(
            'meta_key' => '_grabado_id',
            'meta_value' => $grabado_id
        ),
        array('%s', '%s')
    );
}

function simmedical_get_product_grabado($product_id) {
    return get_post_meta($product_id, '_grabado_id', true);
}

function simmedical_count_grabado_products($grabado_id) {
    global $wpdb;
    
    $count = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_grabado_id' AND meta_value = %s",
        $grabado_id
    ));
    
    return intval($count);
}

// Helper function to get grabado dropdown options
function simmedical_get_grabado_options() {
    $grabados = simmedical_get_all_grabados();
    $options = array('' => __('Seleccionar grabado...', 'woocommerce'));
    
    foreach ($grabados as $grabado) {
        $options[$grabado['id']] = $grabado['name'];
    }
    
    return $options;
}