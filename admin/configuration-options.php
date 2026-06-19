<?php

function add_admin_form_configuration_options_certificates_content()
{
    if (isset($_GET['action']) && !empty($_GET['action'])) {
        if ($_GET['action'] == 'save_options') {

            // URLS
            $validation_url = sanitize_text_field($_POST['validation_url']) ?? get_option('validation_url');
            $image_qr_url = sanitize_text_field($_POST['image_qr_url']) ?? get_option('image_qr_url');
            $disable_idcard = sanitize_text_field($_POST['disable_idcard']) ?? get_option('disable_idcard');

            update_option('validation_url', $validation_url);
            update_option('image_qr_url', $image_qr_url);
            update_option('disable_idcard', $disable_idcard);

            // Redirect to the same page with a success message
            wp_redirect(admin_url('admin.php?page=add_admin_form_configuration_options_certificates_content&success=true'));
        }
    }

    include(plugin_dir_path(__FILE__) . 'templates/configuration-options.php');
}