<?php
/**
 * Mi Cuenta: "Documentos por firmar" (public/functions/account/dashboard.php). Variables: $items, $dashboard,
 * $batchable (ids de solicitudes que se pueden firmar en lote).
 */
if (!defined('ABSPATH')) exit;

$statuses = [
    'to_sign' => __('Waiting for your signature', 'edusystem'),
    'waiting' => __('Signed by you; waiting for other signatures', 'edusystem'),
    'pdf' => __('Signed by everyone; the final PDF is pending', 'edusystem'),
];
?>
<section class="edusystem-documents-to-sign" id="edusystem-documents-to-sign" style="margin-bottom:24px">
    <h3><?= esc_html__('Documents to sign', 'edusystem') ?></h3>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
    <input type="hidden" name="action" value="squuad_cert_holder_batch_prepare">
    <?php wp_nonce_field('squuad_cert_holder_batch_prepare'); ?>
    <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
        <thead><tr>
            <?php if ($batchable) : ?><th><span class="screen-reader-text"><?= esc_html__('Select', 'edusystem') ?></span></th><?php endif; ?>
            <th><?= esc_html__('Document', 'edusystem') ?></th>
            <th><?= esc_html__('Student', 'edusystem') ?></th>
            <th><?= esc_html__('Status', 'edusystem') ?></th>
            <th></th>
        </tr></thead>
        <tbody>
            <?php foreach ($items as $item) :
                $student = $item['student'];
                $request_id = $item['request'] ? (int) $item['request']->id : 0; ?>
                <tr>
                    <?php if ($batchable) : ?>
                        <td>
                            <?php if ('to_sign' === $item['state'] && in_array($request_id, $batchable, true)) : ?>
                                <input type="checkbox" name="request_ids[]" value="<?= $request_id ?>" class="edusystem-batch-item" aria-label="<?= esc_attr__('Select', 'edusystem') ?>">
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                    <td data-title="<?= esc_attr__('Document', 'edusystem') ?>"><?= esc_html((string) $item['document']->title) ?></td>
                    <td data-title="<?= esc_attr__('Student', 'edusystem') ?>"><?= esc_html(trim((string) $student->name . ' ' . (string) $student->last_name)) ?></td>
                    <td data-title="<?= esc_attr__('Status', 'edusystem') ?>"><?= esc_html($statuses[$item['state']] ?? '') ?></td>
                    <td>
                        <?php if ('to_sign' === $item['state']) : ?>
                            <a class="woocommerce-button button" href="<?= esc_url(add_query_arg('squuad_cert_sign', (int) $student->id . '-' . (int) $item['document']->id, $dashboard)) ?>"><?= esc_html__('Sign', 'edusystem') ?></a>
                        <?php elseif ('pdf' === $item['state']) : ?>
                            <a class="woocommerce-button button" href="<?= esc_url(add_query_arg('squuad_cert_pdf', $request_id, $dashboard)) ?>"><?= esc_html__('Generate PDF', 'edusystem') ?></a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($batchable) : ?>
        <p><button type="submit" class="woocommerce-button button"><?= esc_html__('Sign selected documents', 'edusystem') ?></button></p>
        <p class="description"><small><?= esc_html__('Only documents that someone else already signed can be signed together. You will review the list, draw your signature once and confirm with your password.', 'edusystem') ?></small></p>
    <?php endif; ?>
    </form>
</section>
