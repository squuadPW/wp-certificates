<?php
declare(strict_types=1);

/**
 * EduSystem - Módulo de certificación (firmas y documentos automáticos), reunido aquí antes de entregarlo a
 * wp-certificates (ADR 0004, paso 3). Los archivos conservan su ruta relativa dentro de certification/ y se siguen
 * cargando desde el mismo punto que antes (includes/functions.php, admin/functions.php, public/functions.php,
 * core/schema.php y edusystem.php), para no cambiar el orden de los hooks.
 */

if (!defined('ABSPATH')) exit;

define('EDUSYSTEM_CERTIFICATION_PATH', EDUSYSTEM_PATH . 'certification/');
define('EDUSYSTEM_CERTIFICATION_URL', EDUSYSTEM_URL . 'certification/');
