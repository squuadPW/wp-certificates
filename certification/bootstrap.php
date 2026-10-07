<?php
declare(strict_types=1);

/**
 * Certificación - Arranque del módulo de firmas de wp-certificates (ADR 0004, paso 4b-4).
 *
 * Lo carga wp-certificates.php en plugins_loaded, solo si EduSystem no carga su propio módulo (nunca dos a la vez:
 * compartirían acciones AJAX, pantallas y el modal). Mismo orden que tenía en EduSystem: funciones compartidas,
 * migración, admin y público.
 */

if (!defined('ABSPATH')) exit;

define('SQUUAD_CERT_MODULE_PATH', WP_C_PATH . 'certification/');
define('SQUUAD_CERT_MODULE_URL', plugin_dir_url(SQUUAD_CERT_MODULE_PATH . 'bootstrap.php'));

// Compartido (antes includes/functions.php de EduSystem)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/document-fields.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-integrity.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-integrity-cli.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-requests.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signers.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signer-variables.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signer-numbers.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signing-turns.php'; // turno de firma y solicitudes del sistema (ADR 0012 de Edusof)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-format.php'; // flujo de firma nuevo (ADR 0011 de Edusof)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/final-pdf.php'; // PDF final por el servidor de PDF (ADR 0013 de Edusof)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-sheet.php'; // hoja del certificado de firmas opcional (ADR 0014 de Edusof)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/signature-certificate-copy.php'; // certificado de firmas descargable aparte (ADR 0014)
require_once SQUUAD_CERT_MODULE_PATH . 'includes/certification-orders.php';
require_once SQUUAD_CERT_MODULE_PATH . 'includes/html-signatures.php';

// Migración única de los documentos con PHP (antes edusystem.php)
require_once SQUUAD_CERT_MODULE_PATH . 'core/migration-documents-php.php';

// Admin (antes admin/functions.php de EduSystem)
require_once SQUUAD_CERT_MODULE_PATH . 'admin/signature-integrity.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/signers.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/document-signing.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/document-preview.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/signer-inbox.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/subject-panel.php';
require_once SQUUAD_CERT_MODULE_PATH . 'admin/signing-settings.php'; // Configuración › Firma de documentos (ADR 0011 de Edusof)

// Público (antes public/functions.php de EduSystem)
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/documents-signature.php';
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/signer-registration.php';
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/verify-page.php'; // página pública de verificación (ADR 0014 de Edusof)
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/signature-batch.php';
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/checkout/cart-signatures.php';
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/account/dashboard-signatures.php';
require_once SQUUAD_CERT_MODULE_PATH . 'public/functions/assets.php';
