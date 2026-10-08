<?php
declare(strict_types=1);

/**
 * Perfil del usuario (wp-admin): «Documento de identidad» (admin/id-document.php, ADR 0007 de Edusof). Variables:
 * $user, $user_id, $is_self, $is_admin, $locked, $can_edit, $document, $shown, $scope, $posted_type, $posted_number
 * (lo escrito si el perfil volvió con errores) y $late_error.
 */

defined('ABSPATH') || exit;

$squuad_cert_type = null !== $posted_type ? $posted_type : ($document ? (int) $document['type_id'] : 0);
$squuad_cert_number = null !== $posted_number ? $posted_number : ($document ? (string) $document['number'] : '');
?>
<div class="<?= esc_attr($scope) ?>">
<section class="eds-card squuad-cert-iddoc-profile" id="squuad-cert-id-document" style="max-width: 720px; margin: 24px 0" aria-labelledby="squuad-cert-iddoc-profile-title">
    <h2 id="squuad-cert-iddoc-profile-title"><?= esc_html__('Identity document', 'wp-certificates') ?></h2>
    <?php if (false !== $late_error && '' !== (string) $late_error) : ?>
        <div class="eds-notice eds-notice--bad notice notice-error inline" role="alert"><p><?= esc_html((string) $late_error) ?></p></div>
    <?php endif; ?>
    <?php if ($document) : ?>
        <p><?= esc_html__('Current identifier:', 'wp-certificates') ?> <strong><code><?= esc_html($shown) ?></code></strong>
            <?php if ($document['type']) : ?>(<?= esc_html(squuad_cert_id_document_type_label($document['type'])) ?>)<?php endif; ?></p>
        <?php if ($is_self || $is_admin) :
            $origin = squuad_cert_id_document_origin_parts($document['origin']); ?>
            <p class="description" style="color: var(--eds-muted, #50575e)"><?= esc_html(squuad_cert_id_document_origin_label($document['origin'])) ?>
                <?php if ('' !== $origin['at']) : ?> · <?= esc_html(squuad_cert_format_date($origin['at'], true, true)) ?><?php endif; ?></p>
        <?php endif; ?>
    <?php else : ?>
        <p><?= esc_html($is_self ? __('You have not registered your identity document yet. It is needed to sign documents.', 'wp-certificates') : __('This person has not registered an identity document yet. It is needed to sign documents.', 'wp-certificates')) ?></p>
    <?php endif; ?>

    <?php if ($can_edit) : ?>
        <?php wp_nonce_field('squuad_cert_profile_iddoc_' . $user_id, '_squuad_cert_profile_iddoc', false); ?>
        <div class="eds-filters">
            <?= str_replace('squuad-cert-iddoc-field"', 'squuad-cert-iddoc-field eds-field"', squuad_cert_id_document_fields_html(
                'squuad-cert-iddoc-profile',
                $squuad_cert_type,
                $squuad_cert_number,
                'squuad_cert_id_type',
                'squuad_cert_id_number',
                false
            )) ?>
        </div>
        <p class="description" style="color: var(--eds-muted, #50575e)">
            <?php if ($is_admin) {
                echo esc_html__('As administrator you can correct it at any time; each change is kept in the activity log (without the full number). The new value is recorded only in the signatures made from now on.', 'wp-certificates');
            } else {
                echo esc_html__('You can correct it until your first signature; after that, only the administration can change it.', 'wp-certificates');
            } ?>
        </p>
    <?php elseif ($is_self && $locked) : ?>
        <p class="description" style="color: var(--eds-muted, #50575e)"><?= esc_html__('Your identity document can no longer be changed because you already signed with it. Ask the administration to correct it.', 'wp-certificates') ?></p>
    <?php elseif ($is_self) : ?>
        <p class="description" style="color: var(--eds-muted, #50575e)"><?= esc_html__('You can register your identity document when you have a document to sign.', 'wp-certificates') ?></p>
    <?php endif; ?>
</section>
</div>
