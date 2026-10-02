<?php

/**
 * Firmas-imagen antiguas de "Users and signatures" (tabla users_signatures_certificate), solo lectura.
 *
 * La pantalla antigua (alta, detalle, borrado) se retiró (ADR 0004 de EduSystem): ya nadie firma por otro con una
 * imagen. "Users and signatures" es ahora la pantalla de firmantes del sistema (certification/admin/signers.php), que
 * lista estas firmas como antiguas. Estas dos funciones se mantienen para quien las llame (ADR 0004, 2.2).
 */

function get_user_signature_detail($id)
{
    global $wpdb;
    $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
    $signature = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_users_signatures_certificate} WHERE id = %d", absint($id)));
    return $signature;
}

function get_users_signatures_certificates()
{
    global $wpdb;
    $table_users_signatures_certificate = $wpdb->prefix . 'users_signatures_certificate';
    $signatures = $wpdb->get_results("SELECT * FROM {$table_users_signatures_certificate}");
    return $signatures;
}