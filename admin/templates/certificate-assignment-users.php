<?php
/**
 * Certificación > Asignación de certificados sin EduSystem (admin/assignment-users.php). Variables: $search, $searched,
 * $users, $total_users, $documents, $notice e $id_required (columna «Documento de identidad», ADR 0007 de Edusof).
 */
defined('ABSPATH') || exit;

$page_url = admin_url('admin.php?page=admin_certificate_assignment_content');
?>
<div class="wrap">
    <h1 class="wp-heading-inline"><?= esc_html__('Issue documents', 'wp-certificates') ?></h1>
    <hr class="wp-header-end">

    <?php if ($notice) : ?>
        <div class="notice <?= !empty($notice['ok']) ? 'notice-success' : 'notice-error' ?> is-dismissible">
            <?php foreach ((array) ($notice['lines'] ?? []) as $line) : ?>
                <p><?= esc_html((string) $line) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <p class="description" style="max-width: 900px">
        <?= esc_html__('Issue a document to people with an account on this site. Their details (first names, last names and email) are taken from their WordPress account. The issued documents appear in "Issued documents" with their validation code. Documents that ask for signatures are not issued here.', 'wp-certificates') ?>
    </p>

    <form method="get" action="<?= esc_url(admin_url('admin.php')) ?>" style="margin: 16px 0">
        <input type="hidden" name="page" value="admin_certificate_assignment_content">
        <label for="squuad-cert-user-search" class="screen-reader-text"><?= esc_html__('Search users', 'wp-certificates') ?></label>
        <input type="search" id="squuad-cert-user-search" name="user_search" value="<?= esc_attr($search) ?>" placeholder="<?= esc_attr__('Name, username or email', 'wp-certificates') ?>" minlength="<?= (int) SQUUAD_CERT_ASSIGN_USERS_MIN_SEARCH ?>">
        <button type="submit" class="button"><?= esc_html__('Search', 'wp-certificates') ?></button>
        <?php if ('' !== $search) : ?>
            <a class="button-link" href="<?= esc_url($page_url) ?>" style="margin-left: 8px"><?= esc_html__('Clear', 'wp-certificates') ?></a>
        <?php endif; ?>
    </form>

    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
        <input type="hidden" name="action" value="squuad_cert_issue_to_users">
        <?php wp_nonce_field('squuad_cert_issue_to_users'); ?>

        <p>
            <label for="squuad-cert-document"><strong><?= esc_html__('Document', 'wp-certificates') ?></strong></label><br>
            <select id="squuad-cert-document" name="document_id" required>
                <option value=""><?= esc_html__('Select a document', 'wp-certificates') ?></option>
                <?php foreach ($documents as $document) : ?>
                    <option value="<?= (int) $document->id ?>"><?= esc_html((string) $document->title) ?> (<?= esc_html((string) $document->document_identificator) ?>)</option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php if (!$documents) : ?>
            <p class="description"><?= esc_html__('There are no active documents without signatures.', 'wp-certificates') ?></p>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="max-width: 900px">
            <thead>
                <tr>
                    <td class="check-column"><span class="screen-reader-text"><?= esc_html__('Select', 'wp-certificates') ?></span></td>
                    <th><?= esc_html__('Name', 'wp-certificates') ?></th>
                    <th><?= esc_html__('Email', 'wp-certificates') ?></th>
                    <?php if ($id_required) : ?><th style="min-width: 260px"><?= esc_html__('Identity document', 'wp-certificates') ?></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (!$searched) : ?>
                    <tr><td colspan="<?= $id_required ? 4 : 3 ?>"><?= esc_html(sprintf(
                        /* translators: %d: minimum number of characters */
                        __('Search the person by name or email (at least %d characters).', 'wp-certificates'),
                        SQUUAD_CERT_ASSIGN_USERS_MIN_SEARCH
                    )) ?></td></tr>
                <?php elseif (!$users) : ?>
                    <tr><td colspan="<?= $id_required ? 4 : 3 ?>"><?= esc_html__('No users found.', 'wp-certificates') ?></td></tr>
                <?php endif; ?>
                <?php foreach ($users as $user) : ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="user_ids[]" value="<?= (int) $user->ID ?>" id="squuad-cert-user-<?= (int) $user->ID ?>">
                        </th>
                        <td><label for="squuad-cert-user-<?= (int) $user->ID ?>"><?= esc_html(squuad_cert_wp_user_full_name((int) $user->ID)) ?></label></td>
                        <td><?= esc_html((string) $user->user_email) ?></td>
                        <?php if ($id_required) : ?>
                            <td>
                                <?php $masked = squuad_cert_id_document_masked((int) $user->ID); ?>
                                <?php if ('' !== $masked) : // en la lista, enmascarado ?>
                                    <code><?= esc_html($masked) ?></code>
                                <?php else : ?>
                                    <span class="squuad-cert-iddoc-inline" style="display: inline-flex; gap: 6px; align-items: center; white-space: nowrap">
                                        <?= squuad_cert_id_document_fields_html('squuad-cert-iddoc-' . (int) $user->ID, 0, '', 'id_doc_type[' . (int) $user->ID . ']', 'id_doc_number[' . (int) $user->ID . ']', false, true) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php if ($total_users > count($users)) : ?>
            <p class="description"><?= esc_html(sprintf(
                /* translators: 1: users shown, 2: users found */
                __('Showing %1$d of %2$d users. Search to narrow the list.', 'wp-certificates'),
                count($users),
                $total_users
            )) ?></p>
        <?php endif; ?>

        <?php if ($id_required) : ?>
            <p class="description" style="max-width: 900px"><?= esc_html__('Every person must have an identity document. For those who do not have one yet, write its type and number in their row: it is saved in their account and the document is issued. If you do not write it, the document is not issued to that person.', 'wp-certificates') ?></p>
        <?php endif; ?>
        <p><button type="submit" class="button button-primary"<?= $documents && $users ? '' : ' disabled' ?>><?= esc_html__('Issue to the selected people', 'wp-certificates') ?></button></p>
    </form>
</div>
