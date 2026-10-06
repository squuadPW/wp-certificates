<?php
/**
 * Certificación - Esquema de las firmas de wp-certificates (ADR 0004, paso 3b: esquema nacido completo).
 *
 * Tablas propias, que antes creaba EduSystem atadas a la ficha del estudiante (student_id): solicitudes, firmas
 * nuevas con su evidencia, firmas anuladas, contenido congelado, eventos sellados y cabeza de la cadena de huellas.
 * El titular de cada solicitud es la cuenta que la recibe (subject_type + subject_id; decisión del dueño del
 * 2026-10-02: cada firmante es dueño del documento). external_ref es el requisito de EduSystem cuando el documento
 * está enlazado (Admisión > Documentos > «Document template»); si no, vacío.
 *
 * Las firmas antiguas siguen en {prefix}users_signatures (de EduSystem): wp-certificates solo las lee, nunca cambia
 * esa tabla. El mensaje sellado (EDUSIG2) conserva su formato: el campo que llevaba student_id lleva subject_id y el
 * de student_document_id lleva external_ref.
 *
 * La crea create_tables_certificates() (wp-certificates.php) junto con core/schema/signers.php; las tablas quedan
 * sin uso hasta que wp-certificates cargue el módulo (paso 4b-4).
 */

if (!defined('ABSPATH')) exit;

function squuad_cert_schema_signatures()
{
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Solicitudes de firma: una por titular (la cuenta), documento y ronda
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_requests (
        id INT(11) NOT NULL AUTO_INCREMENT,
        subject_type VARCHAR(40) NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        document_id VARCHAR(100) NOT NULL,
        round INT(11) NOT NULL DEFAULT 1,
        external_ref INT(11) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        template_version_sha256 CHAR(64) NULL,
        content_sha256 CHAR(64) NULL,
        frozen_at_utc DATETIME NULL,
        frozen_by BIGINT(20) UNSIGNED NULL,
        created_at_utc DATETIME NOT NULL,
        created_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        completed_at_utc DATETIME NULL,
        final_attachment_id BIGINT(20) UNSIGNED NULL,
        final_pdf_sha256 CHAR(64) NULL,
        final_uploaded_by BIGINT(20) UNSIGNED NULL,
        declined_at_utc DATETIME NULL,
        declined_by BIGINT(20) UNSIGNED NULL,
        decline_reason TEXT NULL,
        closed_by BIGINT(20) UNSIGNED NULL,
        closed_upload_sha256 CHAR(64) NULL,
        document_certificate_id INT(11) NULL,
        origin VARCHAR(10) NOT NULL DEFAULT 'opened',
        policy_sha256 CHAR(64) NULL,
        book_entry_id INT(11) NULL,
        pdf_attempts SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
        pdf_next_at_utc DATETIME NULL,
        pdf_last_error VARCHAR(190) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY subject_document_round (subject_type,subject_id,document_id,round),
        KEY status (status),
        KEY pdf_queue (status,pdf_next_at_utc),
        KEY external_ref (external_ref))$charset_collate;"
    );

    // Contenido de cada solicitud (borrador hasta la primera firma, congelado después). content_sha256 se calcula
    // sobre los bytes exactos guardados
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_request_contents (
        request_id INT(11) NOT NULL,
        content LONGTEXT NOT NULL,
        content_sha256 CHAR(64) NOT NULL,
        updated_at_utc DATETIME NOT NULL,
        PRIMARY KEY (request_id))$charset_collate;"
    );

    // Eventos de las solicitudes (creación, congelado, firmas, finalización, rechazo, cierre, anulación), sellados
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_events (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        request_id INT(11) NOT NULL,
        event_type VARCHAR(30) NOT NULL,
        actor_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        switched_from BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        created_at_utc DATETIME NOT NULL,
        data LONGTEXT NULL,
        chain_seq BIGINT(20) UNSIGNED NULL,
        key_id VARCHAR(40) NULL,
        prev_fingerprint CHAR(64) NULL,
        fingerprint CHAR(64) NULL,
        PRIMARY KEY (id),
        KEY request_id (request_id))$charset_collate;"
    );

    // Cabeza de la cadena de huellas (una fila)
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_chain (
        id TINYINT(3) UNSIGNED NOT NULL,
        last_seq BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        head_fingerprint CHAR(64) NOT NULL DEFAULT '',
        updated_at_utc DATETIME NULL,
        PRIMARY KEY (id))$charset_collate;"
    );

    // Firmas nuevas con su evidencia (ADR 0001/0002). signer_role es el puesto de la solicitud ('role:<rol>',
    // 'signer:<id>'). signer_id_document (esquema v12) y signer_id_origin (v13), ADR 0007 de Edusof: documento de
    // identidad de quien firma y quién lo registró, sellados en el mensaje EDUSIG3; vacíos (NULL) en las firmas
    // anteriores o con «Pedir documento de identidad» apagado. La tabla de anuladas lleva además KEY user_id (v13).
    // signature_image_sha256 (esquema 15, ADR 0011 de Edusof): huella del PNG de la firma (escrita o imagen subida),
    // sellada en EDUSIG4; vacía (NULL) en las firmas anteriores y en las dibujadas. Con índice (esquema 16) para rechazar
    // que otra cuenta reutilice la misma imagen
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signatures (
        id INT(11) NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        signature LONGTEXT NOT NULL,
        document_id VARCHAR(100) NOT NULL,
        grade_selected TEXT NULL,
        document_fields LONGTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        chain_seq BIGINT(20) UNSIGNED NULL,
        evidence_status VARCHAR(20) NULL,
        key_id VARCHAR(40) NULL,
        site_url VARCHAR(255) NULL,
        subject_type VARCHAR(40) NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        signer_role VARCHAR(110) NULL,
        actor_user_id BIGINT(20) UNSIGNED NULL,
        switched_from BIGINT(20) UNSIGNED NULL,
        doc_version_sha256 CHAR(64) NULL,
        signature_sha256 CHAR(64) NULL,
        document_fields_sha256 CHAR(64) NULL,
        signed_at_utc DATETIME NULL,
        ip VARCHAR(45) NULL,
        ip_hmac CHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        ua_sha256 CHAR(64) NULL,
        session_hash CHAR(64) NULL,
        ratifies_id INT(11) NULL,
        prev_fingerprint CHAR(64) NULL,
        fingerprint CHAR(64) NULL,
        external_ref INT(11) NULL,
        request_id INT(11) NULL,
        round INT(11) NULL,
        content_sha256 CHAR(64) NULL,
        request_created_fingerprint CHAR(64) NULL,
        consent_version VARCHAR(20) NULL,
        consent_sha256 CHAR(64) NULL,
        signature_method VARCHAR(10) NULL,
        reused_signature_id INT(11) NULL,
        evidence_format VARCHAR(10) NULL,
        signer_id_document VARCHAR(40) NULL,
        signer_id_origin VARCHAR(80) NULL,
        signature_image_sha256 CHAR(64) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY chain_seq (chain_seq),
        UNIQUE KEY request_role (request_id,signer_role),
        KEY subject (subject_type,subject_id),
        KEY user_id (user_id),
        KEY signature_image_sha256 (signature_image_sha256))$charset_collate;"
    );

    // Firmas anuladas: la fila completa (con su huella) se mueve aquí, para que la cadena se siga pudiendo verificar
    dbDelta(
        "CREATE TABLE " . $wpdb->prefix . "squuad_cert_signatures_revoked (
        id INT(11) NOT NULL AUTO_INCREMENT,
        signature_row_id INT(11) NOT NULL,
        user_id BIGINT(20) UNSIGNED NOT NULL,
        signature LONGTEXT NOT NULL,
        document_id VARCHAR(100) NOT NULL,
        grade_selected TEXT NULL,
        document_fields LONGTEXT NULL,
        created_at DATETIME NULL,
        chain_seq BIGINT(20) UNSIGNED NULL,
        evidence_status VARCHAR(20) NULL,
        key_id VARCHAR(40) NULL,
        site_url VARCHAR(255) NULL,
        subject_type VARCHAR(40) NOT NULL,
        subject_id BIGINT(20) UNSIGNED NOT NULL,
        signer_role VARCHAR(110) NULL,
        actor_user_id BIGINT(20) UNSIGNED NULL,
        switched_from BIGINT(20) UNSIGNED NULL,
        doc_version_sha256 CHAR(64) NULL,
        signature_sha256 CHAR(64) NULL,
        document_fields_sha256 CHAR(64) NULL,
        signed_at_utc DATETIME NULL,
        ip VARCHAR(45) NULL,
        ip_hmac CHAR(64) NULL,
        user_agent VARCHAR(255) NULL,
        ua_sha256 CHAR(64) NULL,
        session_hash CHAR(64) NULL,
        ratifies_id INT(11) NULL,
        prev_fingerprint CHAR(64) NULL,
        fingerprint CHAR(64) NULL,
        revoked_at_utc DATETIME NOT NULL,
        revoked_by BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
        reason TEXT NULL,
        external_ref INT(11) NULL,
        request_id INT(11) NULL,
        round INT(11) NULL,
        content_sha256 CHAR(64) NULL,
        request_created_fingerprint CHAR(64) NULL,
        consent_version VARCHAR(20) NULL,
        consent_sha256 CHAR(64) NULL,
        signature_method VARCHAR(10) NULL,
        reused_signature_id INT(11) NULL,
        evidence_format VARCHAR(10) NULL,
        signer_id_document VARCHAR(40) NULL,
        signer_id_origin VARCHAR(80) NULL,
        signature_image_sha256 CHAR(64) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY chain_seq (chain_seq),
        KEY signature_row_id (signature_row_id),
        KEY request_id (request_id),
        KEY subject (subject_type,subject_id),
        KEY user_id (user_id),
        KEY signature_image_sha256 (signature_image_sha256))$charset_collate;"
    );
}

/**
 * Datos iniciales de las firmas, idempotente: la fila de cabeza de la cadena (una sola vez) y la clave del sitio
 * (solo si no hay ninguna; ADR 0004, sección 6: se genera al instalar, sin autoload).
 */
function squuad_cert_signatures_install(): void
{
    global $wpdb;

    $wpdb->query(
        "INSERT IGNORE INTO {$wpdb->prefix}squuad_cert_chain (id, last_seq, head_fingerprint, updated_at_utc)
         VALUES (1, 0, '', NULL)"
    );
    squuad_cert_signature_ensure_key();
}
