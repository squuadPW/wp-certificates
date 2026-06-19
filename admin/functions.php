<?php
require plugin_dir_path(__FILE__) . 'certificates.php';
require plugin_dir_path(__FILE__) . 'cards.php';
require plugin_dir_path(__FILE__) . 'configuration-options.php';
require plugin_dir_path(__FILE__) . 'users-signatures.php';
require plugin_dir_path(__FILE__) . 'documents.php';

add_action('wp_enqueue_scripts', 'certificates_scripts');
function certificates_scripts()
{
    wp_enqueue_style('style-admin', plugins_url('wp-certificates') . '/admin/assets/css/style.css');
}

add_action('admin_enqueue_scripts', function () {

    // select2
    wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0' );
    wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true );

    // Escapamos la traducción para asegurar que no rompa el JS si lleva comillas o apóstrofes
    $placeholder_text = esc_js(__('Select option', 'wp-certificates'));

    wp_add_inline_script('select2-js', "
        jQuery(document).ready(function($) {
            jQuery('.WPCselect2').select2({
                placeholder: '" . $placeholder_text . "',
                allowClear: true,
                width: '100%',
                templateResult: function(option) {
                    return ( jQuery('.select2').val().includes(option.id) ) ? null : option.text;
                }
            });
        });
    ");
});

function admin_wp_certificates_scripts()
{
    $version = '1.0.1';
    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_certificates_templates_content') {
        wp_enqueue_script('templates', plugins_url('wp-certificates') . '/admin/assets/js/templates.js', array('jquery'), $version, true);
    }

    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_cards_content') {
        wp_enqueue_script('cards', plugins_url('wp-certificates') . '/admin/assets/js/cards.js', array('jquery'), $version, true);
    }


    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_documents_content') {
        wp_enqueue_script('documents', plugins_url('wp-certificates') . '/admin/assets/js/documents.js', array('jquery'), $version, true);
    }

    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_users_signatures_certificate_list_content') {
        wp_enqueue_style('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css');
        wp_enqueue_script('select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', ['jquery']);

        // Especifica jQuery como dependencia y usa la versión empaquetada con WordPress
        wp_enqueue_script(
            'pensum',
            plugins_url('wp-certificates') . '/admin/assets/js/user_signatures.js',
            ['jquery', 'select2'], // Asegura que jQuery y Select2 se carguen primero
            $version,
            true
        );
    }
}

add_action('admin_enqueue_scripts', 'admin_wp_certificates_scripts', 3);

function add_certificates_page_admin()
{
    $subscription_status = get_option('site_status_subscription');
    if ($subscription_status != 'expired') {
        add_menu_page(
            esc_html__('Certification', 'wp-certificates'),
            esc_html__('Certification', 'wp-certificates'),
            'manager_certificates',
            'add_admin_form_certificates_content',
            'add_admin_form_certificates_content',
            'dashicons-awards',
            4
        );
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Student certificates', 'wp-certificates'), esc_html__('Student certificate', 'wp-certificates'), 'manager_certificates', 'add_admin_form_certificates_list_content', 'add_admin_form_certificates_list_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Documents', 'wp-certificates'), esc_html__('Documents', 'wp-certificates'), 'manager_documents_certificates', 'add_admin_form_documents_content', 'add_admin_form_documents_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Certificate assignment', 'wp-certificates'), esc_html__('Certificate assignment', 'wp-certificates'), 'manager_certificate_assignment', 'admin_certificate_assignment_content', 'admin_certificate_assignment_content', 10);
        // add_submenu_page('add_admin_form_certificates_content', esc_html__('Certificates', 'wp-certificates'), esc_html__('Certificates', 'wp-certificates'), 'manager_certificates_templates', 'add_admin_form_certificates_templates_content', 'add_admin_form_certificates_templates_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Users and signatures', 'wp-certificates'), esc_html__('Users and signatures', 'wp-certificates'), 'manager_users_signatures_certificate', 'add_admin_form_users_signatures_certificate_list_content', 'add_admin_form_users_signatures_certificate_list_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('ID card', 'wp-certificates'), esc_html__('ID card', 'wp-certificates'), 'manager_id_card', 'add_admin_form_cards_content', 'add_admin_form_cards_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Configuration', 'wp-Configuration'), esc_html__('Configuration', 'wp-certificates'), 'manager_configuration_certificates', 'add_admin_form_configuration_options_certificates_content', 'add_admin_form_configuration_options_certificates_content', 10);
        remove_submenu_page('add_admin_form_certificates_content', 'add_admin_form_certificates_content');
    }
}

add_action('admin_menu', 'add_certificates_page_admin');

function add_certificates_to_administrator()
{
    $role = get_role('administrator');
    $role->add_cap('manager_certificates');
    $role->add_cap('manager_id_card');
    $role->add_cap('manager_certificate_assignment');
    $role->add_cap('manager_users_signatures_certificate');
    $role->add_cap('manager_certificates_templates');
    $role->add_cap('manager_documents_certificates');
    $role->add_cap('manager_configuration_certificates');
}

add_action('admin_init', 'add_certificates_to_administrator');