<?php
/**
 * Mi Cuenta: confirmación y resultado de la firma en lote (ADR 0003, paso 6b). Variables: $batch, $dashboard,
 * $pdf_requests.
 */
if (!defined('ABSPATH')) exit;

$items = (array) $batch->data['items'];
$expired = 'prepared' === $batch->status && strtotime($batch->expires_at_utc . ' UTC') < time();
wp_enqueue_script('edusystem-signature-pad', SQUUAD_CERT_MODULE_URL . 'admin/assets/js/signature-pad-edusystem.js', [], defined('WP_C_VERSION') ? WP_C_VERSION : null, true);
?>
<section class="edusystem-documents-to-sign" id="edusystem-documents-to-sign" style="margin-bottom:24px">
    <p><a href="<?= esc_url($dashboard) ?>">&larr; <?= esc_html__('Back to the list', 'wp-certificates') ?></a></p>

    <?php if ('prepared' === $batch->status && !$expired) : ?>
        <h3><?= esc_html__('Sign selected documents', 'wp-certificates') ?></h3>
        <p><?= esc_html__('Review the list. Each document keeps its own signature and its own record; any document that changed or no longer waits for your signature is skipped.', 'wp-certificates') ?></p>
        <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
            <thead><tr>
                <th>#</th>
                <th><?= esc_html__('Document', 'wp-certificates') ?></th>
                <th><?= esc_html__('Student', 'wp-certificates') ?></th>
                <th><?= esc_html__('Content fingerprint', 'wp-certificates') ?></th>
            </tr></thead>
            <tbody>
                <?php foreach ($items as $i => $item) : ?>
                    <tr>
                        <td><?= (int) $i + 1 ?></td>
                        <td data-title="<?= esc_attr__('Document', 'wp-certificates') ?>"><?= esc_html((string) $item['title']) ?></td>
                        <td data-title="<?= esc_attr__('Student', 'wp-certificates') ?>"><?= esc_html((string) $item['student']) ?></td>
                        <td data-title="<?= esc_attr__('Content fingerprint', 'wp-certificates') ?>"><code><?= esc_html(substr((string) $item['content_sha256'], 0, 12)) ?>…</code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="edusystem-batch-form">
            <input type="hidden" name="action" value="squuad_cert_holder_batch_confirm">
            <input type="hidden" name="batch_id" value="<?= (int) $batch->id ?>">
            <input type="hidden" name="strokes" id="edusystem-batch-strokes" value="">
            <?php wp_nonce_field('squuad_cert_holder_batch_confirm_' . (int) $batch->id); ?>
            <p><strong><?= esc_html__('Draw your signature', 'wp-certificates') ?></strong><br>
                <small><?= esc_html__('It will be applied to each document of the list.', 'wp-certificates') ?></small></p>
            <canvas id="edusystem-batch-pad" style="border:1px solid #8c8f94;background:#fffef0;width:100%;max-width:600px;height:200px;display:block;touch-action:none"></canvas>
            <p><button type="button" class="button" id="edusystem-batch-clear"><?= esc_html__('Clear', 'wp-certificates') ?></button></p>
            <p><label><input type="checkbox" name="consent_sha256" value="<?= esc_attr($batch->consent_sha256) ?>" required>
                <?= nl2br(esc_html((string) $batch->data['consent_text'])) ?></label></p>
            <p><label for="edusystem-batch-password"><?= esc_html__('Confirm with your password', 'wp-certificates') ?></label><br>
                <input type="password" id="edusystem-batch-password" name="password" required autocomplete="current-password" class="input-text"></p>
            <p id="edusystem-batch-error" style="color:#b32d2e;display:none"><?= esc_html__('Draw your signature before signing.', 'wp-certificates') ?></p>
            <p><button type="submit" class="woocommerce-button button"><?= esc_html(sprintf(
                /* translators: %d: number of documents */
                _n('Sign %d document', 'Sign %d documents', count($items), 'wp-certificates'),
                count($items)
            )) ?></button></p>
            <p><small><?= esc_html(sprintf(
                /* translators: %s: expiry date and time */
                __('This selection expires on %s.', 'wp-certificates'),
                get_date_from_gmt((string) $batch->expires_at_utc, get_option('date_format') . ' ' . get_option('time_format'))
            )) ?></small></p>
        </form>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const canvas = document.getElementById("edusystem-batch-pad");
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                const pad = new EdusystemSignaturePad(canvas);
                document.getElementById("edusystem-batch-clear").addEventListener("click", function () { pad.clear(); });
                document.getElementById("edusystem-batch-form").addEventListener("submit", function (event) {
                    const error = document.getElementById("edusystem-batch-error");
                    if (pad.isEmpty()) {
                        event.preventDefault();
                        error.style.display = "";
                        return;
                    }
                    error.style.display = "none";
                    document.getElementById("edusystem-batch-strokes").value = JSON.stringify(pad.toData());
                });
            });
        </script>

    <?php elseif ('finished' === $batch->status && $batch->result_data) : ?>
        <h3><?= esc_html__('Batch result', 'wp-certificates') ?></h3>
        <?php $titles = array_column($items, null, 'request_id'); ?>
        <table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders">
            <thead><tr><th><?= esc_html__('Document', 'wp-certificates') ?></th><th><?= esc_html__('Student', 'wp-certificates') ?></th><th><?= esc_html__('Result', 'wp-certificates') ?></th></tr></thead>
            <tbody>
                <?php foreach ((array) $batch->result_data['signed'] as $id) : ?>
                    <tr><td><?= esc_html((string) ($titles[$id]['title'] ?? $id)) ?></td><td><?= esc_html((string) ($titles[$id]['student'] ?? '')) ?></td>
                        <td><?= in_array($id, (array) $batch->result_data['completed'], true) ? esc_html__('Signed. All signatures are complete.', 'wp-certificates') : esc_html__('Signed. Waiting for the remaining signatures.', 'wp-certificates') ?></td></tr>
                <?php endforeach; ?>
                <?php foreach ((array) $batch->result_data['skipped'] as $skip) : ?>
                    <tr><td><?= esc_html((string) ($titles[$skip['request_id']]['title'] ?? $skip['request_id'])) ?></td><td><?= esc_html((string) ($titles[$skip['request_id']]['student'] ?? '')) ?></td>
                        <td><strong><?= esc_html__('Skipped:', 'wp-certificates') ?></strong> <?= esc_html((string) $skip['reason']) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($pdf_requests) : ?>
            <h4><?= esc_html__('Final PDF', 'wp-certificates') ?></h4>
            <?php include SQUUAD_CERT_MODULE_PATH . 'public/templates/signature-final-pdf.php'; ?>
        <?php endif; ?>

    <?php else : ?>
        <p><?= esc_html($expired || 'expired' === $batch->status ? __('This batch expired. Select the documents again.', 'wp-certificates') : __('This batch was already processed.', 'wp-certificates')) ?></p>
    <?php endif; ?>
</section>
