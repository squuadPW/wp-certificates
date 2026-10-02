<?php
/**
 * Mi Cuenta: "Documentos por firmar" (public/functions/account/dashboard.php). Variables: $items, $dashboard,
 * $batchable (ids de solicitudes que se pueden firmar en lote).
 */
if (!defined('ABSPATH')) exit;

$statuses = [
    'to_sign' => __('Waiting for your signature', 'wp-certificates'),
    'waiting' => __('Signed by you; waiting for other signatures', 'wp-certificates'),
    'pdf' => __('Signed by everyone; the final PDF is pending', 'wp-certificates'),
];
?>
<section class="edusystem-documents-to-sign" id="edusystem-documents-to-sign" style="margin-bottom:24px">
    <h3><?= esc_html__('Documents to sign', 'wp-certificates') ?></h3>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
    <input type="hidden" name="action" value="squuad_cert_holder_batch_prepare">
    <?php wp_nonce_field('squuad_cert_holder_batch_prepare'); ?>
    <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
        <thead><tr>
            <?php if ($batchable) : ?><th><span class="screen-reader-text"><?= esc_html__('Select', 'wp-certificates') ?></span></th><?php endif; ?>
            <th><?= esc_html__('Document', 'wp-certificates') ?></th>
            <th><?= esc_html__('Name', 'wp-certificates') ?></th>
            <th><?= esc_html__('Status', 'wp-certificates') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($items as $item) :
                $request_id = $item['request'] ? (int) $item['request']->id : 0; ?>
                <tr>
                    <?php if ($batchable) : ?>
                        <td>
                            <?php if ('to_sign' === $item['state'] && in_array($request_id, $batchable, true)) : ?>
                                <input type="checkbox" name="request_ids[]" value="<?= $request_id ?>" class="edusystem-batch-item" aria-label="<?= esc_attr__('Select', 'wp-certificates') ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td data-title="<?= esc_attr__('Document', 'wp-certificates') ?>"><?= esc_html((string) $item['document']->title) ?></td>
                    <td data-title="<?= esc_attr__('Name', 'wp-certificates') ?>"><?= esc_html(squuad_cert_account_name((int) $item['subject_id'])) ?></td>
                    <td data-title="<?= esc_attr__('Status', 'wp-certificates') ?>"><?= esc_html($statuses[$item['state']] ?? '') ?></td>
                    <td>
                        <?php if ('to_sign' === $item['state']) : ?>
                            <a class="woocommerce-button button" href="<?= esc_url(add_query_arg('squuad_cert_sign', (int) $item['subject_id'] . '-' . (int) $item['document']->id, $dashboard)) ?>"><?= esc_html__('Sign', 'wp-certificates') ?></a>
                        <?php elseif ('pdf' === $item['state']) : ?>
                            <a class="woocommerce-button button" href="<?= esc_url(add_query_arg('squuad_cert_pdf', $request_id, $dashboard)) ?>"><?= esc_html__('Generate PDF', 'wp-certificates') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($batchable) : ?>
        <p><button type="submit" class="woocommerce-button button"><?= esc_html__('Sign selected documents', 'wp-certificates') ?></button></p>
        <p class="description"><small><?= esc_html__('Only documents that someone else already signed can be signed together. You will review the list, draw your signature once and confirm with your password.', 'wp-certificates') ?></small></p>
    <?php endif; ?>
    </form>
</section>
