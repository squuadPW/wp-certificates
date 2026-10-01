<?php
/**
 * Ficha del estudiante: "Documentos emitidos para firma" (admin/document-signing.php, ADR 0003 paso 7).
 * Variables: $student, $issued, $notice.
 */
if (!defined('ABSPATH')) exit;

$can_manage = edusystem_document_issue_can();

$statuses = [
    'open' => __('Waiting for signatures', 'edusystem'),
    'partially_signed' => __('Waiting for signatures', 'edusystem'),
    'signed' => __('Signed; the final PDF is pending', 'edusystem'),
    'completed' => __('Completed', 'edusystem'),
    'declined' => __('Declined', 'edusystem'),
];
?>
<div id="edusystem-issued-documents">
    <h2 style="margin:20px 0 15px"><?= esc_html__('Documents issued for signature', 'edusystem') ?></h2>
    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> inline"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>
    <?php if ($issued) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?= esc_html__('Document', 'edusystem') ?></th>
                <th><?= esc_html__('Issued', 'edusystem') ?></th>
                <th><?= esc_html__('Signatures', 'edusystem') ?></th>
                <th><?= esc_html__('Volume / Folio', 'edusystem') ?></th>
                <th><?= esc_html__('Status', 'edusystem') ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($issued as $row) : ?>
                    <tr>
                        <td><?= esc_html((string) ($row->document_title ?: $row->document_id)) ?><?= (int) $row->round > 1 ? ' <span class="description">(' . esc_html(sprintf(__('round %d', 'edusystem'), (int) $row->round)) . ')</span>' : '' ?></td>
                        <td><?= esc_html(get_date_from_gmt((string) ($row->frozen_at_utc ?: $row->created_at_utc), get_option('date_format') . ' ' . get_option('time_format'))) ?></td>
                        <td><?= (int) $row->signed_count ?> / <?= (int) $row->required_count ?></td>
                        <td>
                            <?php if ($row->book_entry) : ?>
                                <?= esc_html(sprintf(__('Volume %1$d · Folio %2$d', 'edusystem'), (int) $row->book_entry->tomo, (int) $row->book_entry->folio)) ?>
                                <?php if ('void' === $row->book_entry->status) : ?><br><span style="color:#b32d2e"><?= esc_html__('Voided in the book', 'edusystem') ?></span><?php endif; ?>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?= esc_html($statuses[$row->status] ?? $row->status) ?></td>
                        <td>
                            <?php if ('completed' === $row->status && (int) $row->final_attachment_id) : ?>
                                <a class="button" target="_blank" rel="noopener" href="<?= esc_url(wp_get_attachment_url((int) $row->final_attachment_id)) ?>"><?= esc_html__('View document', 'edusystem') ?></a>
                            <?php endif; ?>
                            <?php $has_line = $row->book_entry && 'active' === $row->book_entry->status; ?>
                            <?php if ($can_manage && $row->is_latest && in_array($row->status, ['open', 'partially_signed', 'signed', 'completed'], true)) : ?>
                                <details style="margin-top:6px">
                                    <summary style="cursor:pointer;color:#b32d2e;font-weight:600"><?= esc_html__('Decline', 'edusystem') ?></summary>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:6px">
                                        <input type="hidden" name="action" value="edusystem_issued_decline">
                                        <input type="hidden" name="request_id" value="<?= (int) $row->id ?>">
                                        <?php wp_nonce_field('edusystem_issued_decline_' . (int) $row->id); ?>
                                        <p style="color:#b32d2e"><strong><?= esc_html__('This action cannot be reverted.', 'edusystem') ?></strong> <?= esc_html__('The signatures of this document will be revoked.', 'edusystem') ?></p>
                                        <p><textarea name="reason" required rows="2" style="width:100%" placeholder="<?= esc_attr__('Reason why it is declined', 'edusystem') ?>"></textarea></p>
                                        <?php if ($has_line) : ?>
                                            <p><strong><?= esc_html(sprintf(__('Volume %1$d · Folio %2$d', 'edusystem'), (int) $row->book_entry->tomo, (int) $row->book_entry->folio)) ?>:</strong><br>
                                                <label><input type="radio" name="book_option" value="keep" required> <?= esc_html__('Revoke the signatures and keep the same volume and folio (the document is issued again with them)', 'edusystem') ?></label><br>
                                                <label><input type="radio" name="book_option" value="void" required> <?= esc_html__('Revoke the signatures and void the volume and folio in the book (the document is issued again with a new one)', 'edusystem') ?></label></p>
                                        <?php endif; ?>
                                        <p><label><input type="checkbox" name="confirm_irreversible" value="1" required> <?= esc_html__('I understand that declining is final and cannot be reverted.', 'edusystem') ?></label></p>
                                        <p><button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e"><?= esc_html__('Decline', 'edusystem') ?></button></p>
                                    </form>
                                </details>
                            <?php elseif ($can_manage && $row->book_pending) : ?>
                                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                                    <input type="hidden" name="action" value="edusystem_issued_book_decision">
                                    <input type="hidden" name="request_id" value="<?= (int) $row->id ?>">
                                    <?php wp_nonce_field('edusystem_issued_book_decision_' . (int) $row->id); ?>
                                    <p><strong><?= esc_html__('Declined: decide what to do with the volume and folio.', 'edusystem') ?></strong><br>
                                        <label><input type="radio" name="book_option" value="keep" required> <?= esc_html__('Issue again with the same volume and folio', 'edusystem') ?></label><br>
                                        <label><input type="radio" name="book_option" value="void" required> <?= esc_html__('Void the volume and folio in the book and issue again with a new one', 'edusystem') ?></label></p>
                                    <p><textarea name="reason" rows="2" style="width:100%" placeholder="<?= esc_attr__('Reason for voiding (required to void)', 'edusystem') ?>"></textarea></p>
                                    <p><button type="submit" class="button button-primary"><?= esc_html__('Apply', 'edusystem') ?></button></p>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
