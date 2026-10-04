<?php
/**
 * ¿Está EduSystem (estudiantes, representantes, requisitos)? wp-certificates funciona sin él (ADR 0004 de EduSystem):
 * lo que depende de estudiantes (asignar certificados, carné, documentos automáticos de estudiantes) solo se usa
 * cuando está.
 */
function wpc_edusystem_active(): bool
{
    return defined('EDUSYSTEM_VERSION') && function_exists('get_student');
}

/**
 * ¿Está activo el sistema de firmas (solicitudes con evidencia, ADR 0002/0003 de EduSystem; desde el paso 5b vive en el
 * módulo certification/ de wp-certificates)? Entonces las firmas son legales y selladas: no se borran en bloque, y las
 * firmas institucionales se gestionan como firmantes del sistema.
 */
function wpc_edusystem_signatures_active(): bool
{
    return function_exists('squuad_cert_signature_requests_enabled') && squuad_cert_signature_requests_enabled();
}

/**
 * ¿Rige la regla "nadie firma por otro" (ADR 0003 de EduSystem, paso 10)? Entonces las firmas-imagen de "Users and
 * signatures" no se insertan en ningún documento y los documentos que exigen firma se emiten para firma. Lo responde el
 * módulo de firmas de wp-certificates (antes preguntaba a EduSystem, cuyas funciones se retiraron en el paso 5b).
 */
function wpc_third_party_signatures_blocked(): bool
{
    return function_exists('squuad_cert_third_party_signatures_blocked') && squuad_cert_third_party_signatures_blocked();
}

/** ¿El módulo de firmas gestiona los firmantes institucionales (firmantes del sistema)? */
function wpc_edusystem_signers_active(): bool
{
    return function_exists('squuad_cert_signers_enabled') && squuad_cert_signers_enabled();
}

/** Registro de una acción sobre firmas: log propio de wp-certificates (con copia en el de EduSystem si existe). */
function wpc_log_signature_action(string $message): void
{
    squuad_cert_log($message, 'legacy_signature_used');
}

/**
 * Las pantallas del plugin guardan y redirigen (setcookie + wp_redirect) desde la propia función de la página, cuando
 * WordPress ya imprimió la cabecera del admin. Junto a EduSystem funcionaba porque otro código abría un búfer de salida;
 * solo (sin EduSystem) la redirección fallaba con "headers already sent" y la página quedaba a medias (p. ej. al crear
 * un documento). Se abre un búfer en las pantallas del plugin antes de que empiece la salida.
 */
add_action('admin_init', 'wpc_admin_screens_buffer');
function wpc_admin_screens_buffer(): void
{
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ('' === $page || wp_doing_ajax()) {
        return;
    }
    foreach (['add_admin_form_certificates', 'add_admin_form_documents_content', 'add_admin_form_users_signatures_certificate', 'add_admin_form_cards_content', 'add_admin_form_configuration_options_certificates', 'admin_certificate_', 'squuad-cert-'] as $prefix) {
        if (str_starts_with($page, $prefix)) {
            ob_start();
            return;
        }
    }
}

require plugin_dir_path(__FILE__) . 'certificates.php';
require plugin_dir_path(__FILE__) . 'assignment-users.php';
require plugin_dir_path(__FILE__) . 'cards.php';
require plugin_dir_path(__FILE__) . 'configuration-options.php';
// Documento de identidad de quien firma (ADR 0007 de Edusof): sección de Configuración y perfil del usuario
require plugin_dir_path(__FILE__) . 'id-document.php';
require plugin_dir_path(__FILE__) . 'users-signatures.php';
require plugin_dir_path(__FILE__) . 'documents.php';
require plugin_dir_path(__FILE__) . 'variables.php';
require plugin_dir_path(__FILE__) . 'signing-roles.php';
require plugin_dir_path(__FILE__) . 'permissions.php';
require plugin_dir_path(__FILE__) . 'api-keys.php';
require plugin_dir_path(__FILE__) . 'generate.php';

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
    $version = '1.0.3'; // subir al cambiar los JS del admin
    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_cards_content') {
        wp_enqueue_script('cards', plugins_url('wp-certificates') . '/admin/assets/js/cards.js', array('jquery'), $version, true);
    }


    if (isset($_GET['page']) && !empty($_GET['page']) && $_GET['page'] == 'add_admin_form_documents_content') {
        wp_enqueue_script('documents', plugins_url('wp-certificates') . '/admin/assets/js/documents.js', array('jquery'), $version, true);
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
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Issued documents', 'wp-certificates'), esc_html__('Issued documents', 'wp-certificates'), 'manager_certificates', 'add_admin_form_certificates_list_content', 'add_admin_form_certificates_list_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Documents', 'wp-certificates'), esc_html__('Documents', 'wp-certificates'), 'manager_documents_certificates', 'add_admin_form_documents_content', 'add_admin_form_documents_content', 10);
        // Asignar certificados: con EduSystem, a sus estudiantes; sin él, a usuarios de WordPress (titular wp_user)
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Issue documents', 'wp-certificates'), esc_html__('Issue documents', 'wp-certificates'), 'manager_certificate_assignment', 'admin_certificate_assignment_content', 'admin_certificate_assignment_content', 10);
        // «Users and signatures» lo registra el módulo de firmas (certification/admin/signers.php), en esta misma URL
        add_submenu_page('add_admin_form_certificates_content', esc_html__('ID card', 'wp-certificates'), esc_html__('ID card', 'wp-certificates'), 'manager_id_card', 'add_admin_form_cards_content', 'add_admin_form_cards_content', 10);
        add_submenu_page('add_admin_form_certificates_content', esc_html__('Configuration', 'wp-certificates'), esc_html__('Configuration', 'wp-certificates'), 'manager_configuration_certificates', 'add_admin_form_configuration_options_certificates_content', 'add_admin_form_configuration_options_certificates_content', 10);
        remove_submenu_page('add_admin_form_certificates_content', 'add_admin_form_certificates_content');
    }
}

add_action('admin_menu', 'add_certificates_page_admin');

/**
 * Página principal del menú «Certificación»: no tiene pantalla propia (se quita del submenú y el menú enlaza con la
 * primera subpágina). Abierta a mano daba un error 500 porque la función no existía: lleva a «Student certificates».
 */
function add_admin_form_certificates_content()
{
    wp_safe_redirect(admin_url('admin.php?page=add_admin_form_certificates_list_content'));
    exit;
}

function add_certificates_to_administrator()
{
    $role = get_role('administrator');
    $role->add_cap('manager_certificates');
    $role->add_cap('manager_id_card');
    $role->add_cap('manager_certificate_assignment');
    $role->add_cap('manager_users_signatures_certificate');
    $role->add_cap('manager_documents_certificates');
    $role->add_cap('manager_configuration_certificates');
}

add_action('admin_init', 'add_certificates_to_administrator');

// agrega 
add_action('document_view', function ($document) {

    
    $documents_certificates = get_documents_certificates() ?? [];

    $document_template = $document->document_template ?? '';
    $downloadable_document = $document->downloadable_document ?? '';

    $form_data = [];
    if ( isset($_COOKIE['form_data']) ) {
        $form_data = json_decode(stripslashes($_COOKIE['form_data']), true);

        $document_template  = $form_data['document_template'] ?? $document_template;
        $downloadable_document  = $form_data['downloadable_document'] ?? $downloadable_document;
    }

    ?>  
        <div class="group-input" >
            <label for="document_template">

                <b><?= __('Document template','wp-certificates'); ?></b>
                <select name="document_template" >
                    <option value='' <?php selected( $document_template,'' ) ?> ><?= __('Select option','wp-certificates');?></option>
                    
                    <?php foreach( $documents_certificates as $document_certificate ): ?>
                        <option value='<?= $document_certificate->id ?>' <?php selected( $document_template, $document_certificate->id ) ?> ><?= $document_certificate->title ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </div> 

        <div class="group-input" >
            <label for="downloadable_document">
                    
                <input type="checkbox" name="downloadable_document" value="1" <?php checked( $downloadable_document, 1 ) ?>    >
                <?= __('Downloadable document','wp-certificates'); ?>
                
            </label>
        </div> 
    <?php
}, 10, 1);

