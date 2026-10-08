<?php
/**
 * WP Certificates - Fechas con el formato de la institución (ADR 0017 de Edusof).
 *
 * Solo dependen del core de WordPress (ADR 0004: WP Certificates no usa funciones de EduSystem). El formato es solo
 * visual: se lee de Ajustes (date_format, time_format, timezone_string), que en Edusof se edita en Theme Settings.
 * Lo guardado no cambia: fechas en Y-m-d y horas en UTC (columnas *_utc).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Marca de tiempo de una fecha en cualquiera de las formas en que llega.
 *
 * @param string|int|DateTimeInterface|null $value         Texto Y-m-d / Y-m-d H:i:s, marca de tiempo u objeto fecha.
 * @param bool|null                         $stored_in_utc null (por defecto): con hora = UTC (así se guarda todo, ADR 0017
 *                                                         de Edusof), solo fecha = día del calendario. true = UTC; false =
 *                                                         hora local del sitio.
 * @return int|null
 */
function squuad_cert_date_timestamp($value, ?bool $stored_in_utc = null): ?int
{
    if ($value instanceof DateTimeInterface) {
        return $value->getTimestamp();
    }
    if (is_int($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    if ('' === $value || 0 === strpos($value, '0000-00-00')) {
        return null;
    }
    if (null === $stored_in_utc) {
        $stored_in_utc = (bool) preg_match('/\d{1,2}:\d{2}/', $value);
    }
    try {
        return (new DateTimeImmutable($value, $stored_in_utc ? new DateTimeZone('UTC') : wp_timezone()))->getTimestamp();
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Fecha corta con el formato de la institución y, si se pide, la hora (con segundos si se piden).
 *
 * @param string|int|DateTimeInterface|null $value
 * @return string Vacío si no hay fecha.
 */
function squuad_cert_format_date($value, bool $with_time = false, ?bool $stored_in_utc = null, bool $seconds = false): string
{
    $timestamp = squuad_cert_date_timestamp($value, $stored_in_utc);
    if (null === $timestamp) {
        return '';
    }
    $format = (string) get_option('date_format') ?: 'Y-m-d';
    if ($with_time) {
        $time = (string) get_option('time_format') ?: 'H:i';
        if ($seconds && false === strpos($time, 's')) {
            // Los segundos van tras los minutos en cualquier formato de hora (g:i a → g:i:s a, H:i → H:i:s)
            $time = preg_replace('/i/', 'i:s', $time, 1);
        }
        $format .= ', ' . $time;
    }
    return (string) wp_date($format, $timestamp);
}

/**
 * Fecha larga del idioma del sitio («8 de octubre de 2026», «October 8, 2026»).
 *
 * @param string|int|DateTimeInterface|null $value
 */
function squuad_cert_format_date_long($value, ?bool $stored_in_utc = null): string
{
    $timestamp = squuad_cert_date_timestamp($value, $stored_in_utc);
    // El formato largo lo da la traducción del core de WordPress
    return null === $timestamp ? '' : (string) wp_date(__('F j, Y'), $timestamp);
}

/**
 * La conexión a MySQL trabaja en UTC (ADR 0017 de Edusof): CURRENT_TIMESTAMP y los DEFAULT CURRENT_TIMESTAMP de las
 * tablas guardan UTC sin depender de la zona del servidor. EduSystem hace lo mismo; repetirlo no cambia nada.
 */
function squuad_cert_db_use_utc(): void
{
    global $wpdb;
    static $done = false;
    if ($done || !$wpdb) {
        return;
    }
    $done = true;
    $wpdb->query("SET time_zone = '+00:00'");
}
squuad_cert_db_use_utc();

