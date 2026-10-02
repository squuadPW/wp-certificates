<?php
/**
 * EduSystem - Esquema de base de datos: firmantes del sistema (ADR 0003, esquema v6).
 *
 * Parte de create_tables() (core/schema.php): firmantes registrados, invitaciones, firma propia registrada,
 * política de firmantes por documento, firmantes fijados en cada solicitud y lotes de firma.
 */

if (!defined('ABSPATH')) exit;

function squuad_cert_schema_signers()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Firmantes registrados del sistema (otros roles: director, coordinador...). Un registro por usuario.
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signers (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        charge VARCHAR(191) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'invited',
        legacy_certificate_signature_id INT(11) NULL,
        created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at_utc DATETIME NOT NULL,
        updated_at_utc DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY user_id (user_id),
        KEY status (status))$charset_collate;"
    );

    // Invitaciones: el token solo viaja en el correo; aquí se guarda su HMAC con la clave del sitio
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signer_invitations (
        id INT(11) NOT NULL AUTO_INCREMENT,
        signer_id INT(11) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        email_at_invite VARCHAR(191) NOT NULL,
        token_hmac CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        expires_at_utc DATETIME NOT NULL,
        sent_count INT(11) NOT NULL DEFAULT 1,
        last_sent_at_utc DATETIME NULL,
        accepted_at_utc DATETIME NULL,
        revoked_at_utc DATETIME NULL,
        revoked_by BIGINT(20) UNSIGNED NULL,
        created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at_utc DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY token_hmac (token_hmac),
        KEY signer_id (signer_id),
        KEY user_status (user_id,status))$charset_collate;"
    );

    // Firma propia registrada: solo la crea su titular; inmutable (cambiarla crea otra). Sellada en la cadena (EDUPSG1).
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signer_signatures (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        strokes LONGTEXT NOT NULL,
        strokes_sha256 CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'active',
        replaced_by INT(11) NULL,
        invitation_id INT(11) NULL,
        consent_version VARCHAR(20) NULL,
        consent_sha256 CHAR(64) NULL,
        session_hash CHAR(64) NULL,
        ip_hmac CHAR(64) NULL,
        ua_sha256 CHAR(64) NULL,
        created_at_utc DATETIME NOT NULL,
        chain_seq BIGINT(20) UNSIGNED NULL,
        key_id VARCHAR(40) NULL,
        prev_fingerprint CHAR(64) NULL,
        fingerprint CHAR(64) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY chain_seq (chain_seq),
        KEY user_status (user_id,status))$charset_collate;"
    );

    // Política de firmantes por documento (versionada: un cambio crea una fila nueva y la anterior queda inactiva)
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signing_policies (
        id INT(11) NOT NULL AUTO_INCREMENT,
        document_certificate_id INT(11) NOT NULL,
        requires_signatures TINYINT(1) NOT NULL DEFAULT 1,
        is_current TINYINT(1) NOT NULL DEFAULT 1,
        policy_sha256 CHAR(64) NOT NULL,
        updated_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        updated_at_utc DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY document_current (document_certificate_id,is_current))$charset_collate;"
    );

    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signing_slots (
        id INT(11) NOT NULL AUTO_INCREMENT,
        policy_id INT(11) NOT NULL,
        position INT(11) NOT NULL DEFAULT 0,
        slot_type VARCHAR(10) NOT NULL,
        signer_id INT(11) NOT NULL DEFAULT 0,
        role_key VARCHAR(100) NOT NULL DEFAULT '',
        required TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY policy_slot (policy_id,slot_type,signer_id,role_key))$charset_collate;"
    );

    // Firmantes fijados en cada solicitud (generaliza student_user_id/parent_user_id); se sellan en el evento created
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_request_signers (
        id INT(11) NOT NULL AUTO_INCREMENT,
        request_id INT(11) NOT NULL,
        position INT(11) NOT NULL DEFAULT 0,
        slot_key VARCHAR(40) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        signer_id INT(11) NOT NULL DEFAULT 0,
        name_snapshot VARCHAR(191) NOT NULL DEFAULT '',
        charge_snapshot VARCHAR(191) NOT NULL DEFAULT '',
        required TINYINT(1) NOT NULL DEFAULT 1,
        phase TINYINT(1) NOT NULL DEFAULT 1,
        PRIMARY KEY (id),
        UNIQUE KEY request_slot (request_id,slot_key),
        KEY user_id (user_id))$charset_collate;"
    );

    // Lotes de firma: el manifiesto (request_id:content_sha256) se fija al preparar y se firma exactamente ese
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_batches (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        manifest LONGTEXT NOT NULL,
        manifest_sha256 CHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'prepared',
        consent_version VARCHAR(20) NULL,
        consent_sha256 CHAR(64) NULL,
        created_at_utc DATETIME NOT NULL,
        expires_at_utc DATETIME NOT NULL,
        finished_at_utc DATETIME NULL,
        result LONGTEXT NULL,
        PRIMARY KEY (id),
        KEY user_status (user_id,status))$charset_collate;"
    );

    // Tomo y folio (esquema v7, ADR 0003 paso 8b): la línea del libro de registro de EduSof que consume un documento
    // emitido. Se reserva al emitir; puede pasar a otra ronda del mismo documento (se reemite con el mismo tomo/folio)
    // o anularse en el libro ('void', con motivo) para reemitir con otra. request_id: la ronda que la usa ahora.
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_book_entries (
        id INT(11) NOT NULL AUTO_INCREMENT,
        student_id BIGINT(20) UNSIGNED NOT NULL,
        document_certificate_id INT(11) NOT NULL,
        request_id INT(11) NULL,
        book_id INT(11) NOT NULL,
        line_id INT(11) NOT NULL,
        tomo INT(11) NOT NULL DEFAULT 0,
        folio INT(11) NOT NULL DEFAULT 0,
        line_number INT(11) NOT NULL DEFAULT 0,
        status VARCHAR(10) NOT NULL DEFAULT 'active',
        created_at_utc DATETIME NOT NULL,
        created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        voided_at_utc DATETIME NULL,
        voided_by BIGINT(20) UNSIGNED NULL,
        void_reason TEXT NULL,
        PRIMARY KEY (id),
        KEY student_document (student_id,document_certificate_id),
        KEY line_id (line_id))$charset_collate;"
    );
}
