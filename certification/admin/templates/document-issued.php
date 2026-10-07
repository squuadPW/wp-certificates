<?php
/**
 * Ficha del estudiante: "Documentos emitidos para firma" (admin/document-signing.php, ADR 0003 paso 7).
 * Variables: $student, $issued, $notice.
 */
if (!defined('ABSPATH')) exit;

$can_manage = squuad_cert_document_issue_can((int) $student->id);

$statuses = [
    'open' => __('Waiting for signatures', 'wp-certificates'),
    'partially_signed' => __('Waiting for signatures', 'wp-certificates'),
    'signed' => __('Signed; the final PDF is pending', 'wp-certificates'),
    'completed' => __('Completed', 'wp-certificates'),
    'declined' => __('Declined', 'wp-certificates'),
];
?>
<div id="edusystem-issued-documents">
    <h2 style="margin:20px 0 15px"><?= esc_html__('Documents issued for signature', 'wp-certificates') ?></h2>
    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> inline"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>
    <?php if ($issued) : ?>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr>
                <th><?= esc_html__('Document', 'wp-certificates') ?></th>
                <th><?= esc_html__('Issued', 'wp-certificates') ?></th>
                <th><?= esc_html__('Signatures', 'wp-certificates') ?></th>
                <th><?= esc_html__('Volume / Folio', 'wp-certificates') ?></th>
                <th><?= esc_html__('Status', 'wp-certificates') ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($issued as $row) : ?>
                    <tr>
                        <td><?= esc_html((string) ($row->document_title ?: $row->document_id)) ?><?= (int) $row->round > 1 ? ' <span class="description">(' . esc_html(sprintf(__('round %d', 'wp-certificates'), (int) $row->round)) . ')</span>' : '' ?></td>
                        <td><?= esc_html(get_date_from_gmt((string) ($row->frozen_at_utc ?: $row->created_at_utc), get_option('date_format') . ' ' . get_option('time_format'))) ?></td>
                        <td><?= (int) $row->signed_count ?> / <?= (int) $row->required_count ?></td>
                        <td>
                            <?php if ($row->book_entry) : ?>
                                <?= esc_html(sprintf(__('Volume %1$d · Folio %2$d', 'wp-certificates'), (int) $row->book_entry->tomo, (int) $row->book_entry->folio)) ?>
                                <?php if ('void' === $row->book_entry->status) : ?><br><span style="color:#b32d2e"><?= esc_html__('Voided in the book', 'wp-certificates') ?></span><?php endif; ?>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td><?= esc_html($statuses[$row->status] ?? $row->status) ?></td>
                        <td>
                            <?php if ('completed' === $row->status && (int) $row->final_attachment_id) : ?>
                                <a class="button" target="_blank" rel="noopener" href="<?= esc_url(wp_get_attachment_url((int) $row->final_attachment_id)) ?>"><?= esc_html__('View document', 'wp-certificates') ?></a>
                                <?php if (function_exists('squuad_cert_signature_certificate_access') && '' !== squuad_cert_signature_certificate_access($row, get_current_user_id())) : ?>
                                    <a class="button" href="<?= esc_url(squuad_cert_signature_certificate_url((int) $row->id)) ?>"><?= esc_html__('Certificate of signatures', 'wp-certificates') ?></a>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php // Retirar o restablecer la verificación pública por el QR (ADR 0014)
                            if (function_exists('squuad_cert_verify_can_withdraw') && squuad_cert_verify_can_withdraw() && squuad_cert_signature_sheet_ready() && null !== $row->frozen_at_utc) :
                                $withdrawn = squuad_cert_verify_withdrawn($row); ?>
                                <?php if ($withdrawn) : ?>
                                    <p style="margin:6px 0 0;color:#b32d2e"><?= esc_html__('Public verification withdrawn: the QR code shows that it could not be verified.', 'wp-certificates') ?></p>
                                <?php endif; ?>
                                <details style="margin-top:6px">
                                    <summary style="cursor:pointer"><?= esc_html($withdrawn ? __('Restore verification', 'wp-certificates') : __('Withdraw verification', 'wp-certificates')) ?></summary>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:6px">
                                        <input type="hidden" name="action" value="squuad_cert_verify_withdraw">
                                        <input type="hidden" name="request_id" value="<?= (int) $row->id ?>">
                                        <input type="hidden" name="withdraw" value="<?= $withdrawn ? '0' : '1' ?>">
                                        <?php wp_nonce_field('squuad_cert_verify_withdraw_' . (int) $row->id); ?>
                                        <?php if (!$withdrawn) : ?>
                                            <p><?= esc_html__('Anyone who scans the QR code of this document will see that it could not be verified, as with a false document. Use it, for example, if a photo of the QR code was published. It is recorded in the evidence of the document.', 'wp-certificates') ?></p>
                                        <?php endif; ?>
                                        <p><textarea name="reason" required maxlength="500" rows="2" style="width:100%" placeholder="<?= esc_attr__('Reason (required)', 'wp-certificates') ?>"></textarea></p>
                                        <p><label><input type="checkbox" name="confirm" value="1" required> <?= esc_html($withdrawn ? __('I confirm that the verification is shown again.', 'wp-certificates') : __('I confirm that the public verification of this document is withdrawn.', 'wp-certificates')) ?></label></p>
                                        <p><button type="submit" class="button"><?= esc_html($withdrawn ? __('Restore verification', 'wp-certificates') : __('Withdraw verification', 'wp-certificates')) ?></button></p>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php $has_line = $row->book_entry && 'active' === $row->book_entry->status; ?>
                            <?php if ($can_manage && $row->is_latest && in_array($row->status, ['open', 'partially_signed', 'signed', 'completed'], true)) : ?>
                                <details style="margin-top:6px">
                                    <summary style="cursor:pointer;color:#b32d2e;font-weight:600"><?= esc_html__('Decline', 'wp-certificates') ?></summary>
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="margin-top:6px">
                                        <input type="hidden" name="action" value="squuad_cert_issued_decline">
                                        <input type="hidden" name="request_id" value="<?= (int) $row->id ?>">
                                        <?php wp_nonce_field('squuad_cert_issued_decline_' . (int) $row->id); ?>
                                        <p style="color:#b32d2e"><strong><?= esc_html__('This action cannot be reverted.', 'wp-certificates') ?></strong> <?= esc_html__('The signatures of this document will be revoked.', 'wp-certificates') ?></p>
                                        <p><textarea name="reason" required rows="2" style="width:100%" placeholder="<?= esc_attr__('Reason why it is declined', 'wp-certificates') ?>"></textarea></p>
                                        <?php if ($has_line) : ?>
                                            <p><strong><?= esc_html(sprintf(__('Volume %1$d · Folio %2$d', 'wp-certificates'), (int) $row->book_entry->tomo, (int) $row->book_entry->folio)) ?>:</strong><br>
                                                <label><input type="radio" name="book_option" value="keep" required> <?= esc_html__('Revoke the signatures and keep the same volume and folio (the document is issued again with them)', 'wp-certificates') ?></label><br>
                                                <label><input type="radio" name="book_option" value="void" required> <?= esc_html__('Revoke the signatures and void the volume and folio in the book (the document is issued again with a new one)', 'wp-certificates') ?></label></p>
                                        <?php endif; ?>
                                        <p><label><input type="checkbox" name="confirm_irreversible" value="1" required> <?= esc_html__('I understand that declining is final and cannot be reverted.', 'wp-certificates') ?></label></p>
                                        <p><button type="submit" class="button" style="color:#b32d2e;border-color:#b32d2e"><?= esc_html__('Decline', 'wp-certificates') ?></button></p>
                                    </form>
                                </details>
                            <?php elseif ($can_manage && $row->book_pending) : ?>
                                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                                    <input type="hidden" name="action" value="squuad_cert_issued_book_decision">
                                    <input type="hidden" name="request_id" value="<?= (int) $row->id ?>">
                                    <?php wp_nonce_field('squuad_cert_issued_book_decision_' . (int) $row->id); ?>
                                    <p><strong><?= esc_html__('Declined: decide what to do with the volume and folio.', 'wp-certificates') ?></strong><br>
                                        <label><input type="radio" name="book_option" value="keep" required> <?= esc_html__('Issue again with the same volume and folio', 'wp-certificates') ?></label><br>
                                        <label><input type="radio" name="book_option" value="void" required> <?= esc_html__('Void the volume and folio in the book and issue again with a new one', 'wp-certificates') ?></label></p>
                                    <p><textarea name="reason" rows="2" style="width:100%" placeholder="<?= esc_attr__('Reason for voiding (required to void)', 'wp-certificates') ?>"></textarea></p>
                                    <p><button type="submit" class="button button-primary"><?= esc_html__('Apply', 'wp-certificates') ?></button></p>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
