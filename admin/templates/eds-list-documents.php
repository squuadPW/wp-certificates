<?php
/**
 * Lista de documentos con el diseño Edusof (la incluye list-documents.php solo si wpc_eds_documents_enabled()).
 * Mismas acciones que la lista de siempre: crear (alta en dos pasos), editar y eliminar con su nonce.
 */
if (!defined('ABSPATH')) exit;

$base_url = admin_url('admin.php?page=add_admin_form_documents_content');
$view = sanitize_key($_GET['view'] ?? 'all');
$search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
$paged = max(1, absint($_GET['paged'] ?? 1));
$list = wpc_eds_documents_list_data($view, $search, $paged);
$view = $list['view'];
$pages = max(1, (int) ceil($list['total'] / $list['per_page']));

$tabs = [
    'all' => __('All', 'wp-certificates'),
    'automatic' => __('Signed by the person in their account', 'wp-certificates'),
    'managed' => __('Issued by the school office', 'wp-certificates'),
    'inactive' => __('Inactive', 'wp-certificates'),
];
$view_url = static function (string $key, int $page = 1) use ($base_url, $search): string {
    return add_query_arg(array_filter([
        'view' => 'all' !== $key ? $key : null,
        's' => '' !== $search ? $search : null,
        'paged' => $page > 1 ? $page : null,
    ]), $base_url);
};

// Avisos que antes salían en todas las pantallas: documentos que piden firma sin firmantes y la carta de compromiso
$can_policies = defined('SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP') && current_user_can(SQUUAD_CERT_MANAGE_SIGNING_POLICIES_CAP);
$affected = ($can_policies && function_exists('squuad_cert_legacy_signature_affected_documents') && !get_option('squuad_cert_legacy_signatures_notice_dismissed'))
    ? squuad_cert_legacy_signature_affected_documents() : [];
$letter = $can_policies && function_exists('squuad_cert_missing_letter_conversion_available') && squuad_cert_missing_letter_conversion_available();
$signers_notice = function_exists('squuad_cert_signers_take_notice') ? squuad_cert_signers_take_notice() : null;
?>
<div class="wrap eds-page wpc-eds-documents">
    <header class="eds-page-header">
        <nav class="eds-breadcrumb" aria-label="<?= esc_attr__('Breadcrumb', 'wp-certificates') ?>">
            <?= esc_html__('Certification', 'wp-certificates') ?> / <span aria-current="page"><?= esc_html__('Documents', 'wp-certificates') ?></span>
        </nav>
        <h1 class="eds-title"><?= esc_html__('Documents', 'wp-certificates') ?></h1>
        <p class="eds-subtitle"><?= esc_html__('The templates used to create proofs, certificates and documents to sign.', 'wp-certificates') ?></p>
        <div class="eds-actions">
            <a class="eds-btn eds-btn--primary" href="<?= esc_url(add_query_arg('section_tab', 'document_detail', $base_url)) ?>"><?= esc_html__('Create', 'wp-certificates') ?></a>
        </div>
    </header>
    <hr class="wp-header-end">

    <?php if (!empty($_COOKIE['message'])) { ?>
        <div class="eds-notice eds-notice--ok" role="status"><p><?= esc_html(wp_unslash($_COOKIE['message'])) ?></p></div>
        <?php setcookie('message', '', time(), '/'); ?>
    <?php } ?>
    <?php if (!empty($_COOKIE['message-error'])) { ?>
        <div class="eds-notice eds-notice--bad" role="alert"><p><?= esc_html(wp_unslash($_COOKIE['message-error'])) ?></p></div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php } ?>
    <?php if ($signers_notice) { ?>
        <div class="eds-notice <?= $signers_notice['ok'] ? 'eds-notice--ok' : 'eds-notice--bad' ?>" role="status"><p><?= esc_html($signers_notice['message']) ?></p></div>
    <?php } ?>

    <?php if ($affected) { ?>
        <div class="eds-notice eds-notice--warn wpc-eds-affected">
            <p><strong><?= esc_html(sprintf(
                /* translators: %d: number of documents */
                _n('%d document asks for signatures but has no signers yet: it cannot be issued.', '%d documents ask for signatures but have no signers yet: they cannot be issued.', count($affected), 'wp-certificates'),
                count($affected)
            )) ?></strong>
                <?= esc_html__('For security, nobody can sign on behalf of someone else: choose the signers of each document; they will sign from their own account.', 'wp-certificates') ?></p>
            <details>
                <summary><?= esc_html__('See which ones', 'wp-certificates') ?></summary>
                <ul>
                    <?php foreach ($affected as $document) { ?>
                        <li><a href="<?= esc_url(add_query_arg(['section_tab' => 'document_detail', 'document_id' => (int) $document->id], $base_url) . '#edusystem-document-signers') ?>"><?= esc_html((string) $document->title) ?></a></li>
                    <?php } ?>
                </ul>
                <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                    <input type="hidden" name="action" value="squuad_cert_legacy_signatures_dismiss">
                    <?php wp_nonce_field('squuad_cert_legacy_signatures_dismiss'); ?>
                    <button type="submit" class="eds-btn eds-btn--link eds-btn--sm"><?= esc_html__('Hide this notice', 'wp-certificates') ?></button>
                </form>
            </details>
        </div>
    <?php } ?>

    <?php if ($letter) { ?>
        <div class="eds-notice eds-notice--info">
            <p><strong><?= esc_html__('Missing documents commitment letter', 'wp-certificates') ?></strong><br>
                <?= esc_html__('This site uses the old fixed letter. Convert it into an automatic document: it will be editable here, signed with the new signature system (frozen content, consent, "Documents to sign"). Like every automatic document, it is shown while the student has not signed it. Letters already signed keep their validity and are not asked again.', 'wp-certificates') ?></p>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>">
                <input type="hidden" name="action" value="squuad_cert_convert_missing_letter">
                <?php wp_nonce_field('squuad_cert_convert_missing_letter'); ?>
                <button type="submit" class="eds-btn eds-btn--secondary eds-btn--sm"><?= esc_html__('Convert the letter into an automatic document', 'wp-certificates') ?></button>
            </form>
        </div>
    <?php } ?>

    <nav class="eds-tabs" aria-label="<?= esc_attr__('Filter documents', 'wp-certificates') ?>">
        <?php foreach ($tabs as $key => $label) { ?>
            <a class="eds-tab" href="<?= esc_url($view_url($key)) ?>"<?= $key === $view ? ' aria-current="page"' : '' ?>><?= esc_html($label) ?> <span class="eds-tab__count"><?= (int) $list['counts'][$key] ?></span></a>
        <?php } ?>
    </nav>

    <form class="eds-filters" method="get" action="<?= esc_url(admin_url('admin.php')) ?>" role="search">
        <input type="hidden" name="page" value="add_admin_form_documents_content">
        <?php if ('all' !== $view) { ?><input type="hidden" name="view" value="<?= esc_attr($view) ?>"><?php } ?>
        <label class="eds-field"><?= esc_html__('Search by name or code', 'wp-certificates') ?>
            <input type="search" name="s" value="<?= esc_attr($search) ?>">
        </label>
        <button type="submit" class="eds-btn eds-btn--secondary"><?= esc_html__('Search', 'wp-certificates') ?></button>
        <?php if ('' !== $search) { ?>
            <a class="eds-btn eds-btn--link" href="<?= esc_url(add_query_arg(array_filter(['view' => 'all' !== $view ? $view : null]), $base_url)) ?>"><?= esc_html__('Clear search', 'wp-certificates') ?></a>
        <?php } ?>
    </form>

    <?php if (!$list['rows']) { ?>
        <div class="eds-empty">
            <p><?= esc_html('' !== $search ? __('No document matches the search.', 'wp-certificates') : __('There are no documents here yet.', 'wp-certificates')) ?></p>
        </div>
    <?php } else { ?>
        <div class="eds-table-wrap">
            <table class="eds-table wpc-eds-documents-table">
                <caption class="eds-sr-only"><?= esc_html__('Documents', 'wp-certificates') ?></caption>
                <thead>
                    <tr>
                        <th scope="col"><?= esc_html__('Name', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('How it is delivered', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Signatures', 'wp-certificates') ?></th>
                        <th scope="col"><?= esc_html__('Status', 'wp-certificates') ?></th>
                        <th scope="col"><span class="eds-sr-only"><?= esc_html__('Actions', 'wp-certificates') ?></span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($list['rows'] as $document) {
                        $id = (int) $document->id;
                        $edit_url = add_query_arg(['section_tab' => 'document_detail', 'document_id' => $id], $base_url);
                        $delete_url = wp_nonce_url(add_query_arg(['action' => 'delete_document', 'document_id' => $id], $base_url), 'wpc_delete_document_' . $id);
                        $signing = wpc_document_signing_summary($document);
                        $usage = $list['usage'][$id] ?? ['certificates' => 0, 'requests' => 0];
                        $automatic = 'automatic' === $document->type;
                        ?>
                        <tr>
                            <td>
                                <a class="wpc-eds-doc-name" href="<?= esc_url($edit_url) ?>"><?= esc_html((string) $document->title) ?></a>
                                <?php if ('' !== (string) $document->document_identificator) { ?>
                                    <br><code class="wpc-eds-doc-code"><?= esc_html((string) $document->document_identificator) ?></code>
                                <?php } ?>
                            </td>
                            <td><?= esc_html($automatic ? __('Signed by the person in their account', 'wp-certificates') : __('Issued by the school office', 'wp-certificates')) ?></td>
                            <td>
                                <?php if ($signing['missing']) { ?>
                                    <span class="eds-badge eds-badge--warn"><?= esc_html($signing['label']) ?></span>
                                <?php } elseif ($signing['asks']) { ?>
                                    <?= esc_html__('Asks for signatures', 'wp-certificates') ?><br><span class="wpc-eds-muted"><?= esc_html($signing['label']) ?></span>
                                <?php } else { ?>
                                    <span class="wpc-eds-muted"><?= esc_html($signing['label']) ?></span>
                                <?php } ?>
                            </td>
                            <td>
                                <?php if (1 === (int) $document->status) { ?>
                                    <span class="eds-badge eds-badge--ok"><?= esc_html__('Active', 'wp-certificates') ?></span>
                                <?php } else { ?>
                                    <span class="eds-badge eds-badge--neutral"><?= esc_html__('Inactive', 'wp-certificates') ?></span>
                                <?php } ?>
                            </td>
                            <td class="wpc-eds-actions">
                                <a class="eds-btn eds-btn--secondary eds-btn--sm" href="<?= esc_url($edit_url) ?>"><?= esc_html__('Edit', 'wp-certificates') ?><span class="eds-sr-only"> <?= esc_html((string) $document->title) ?></span></a>
                                <button type="button" class="eds-btn eds-btn--link eds-btn--sm wpc-eds-delete"
                                    data-title="<?= esc_attr((string) $document->title) ?>"
                                    data-url="<?= esc_url($delete_url) ?>"
                                    data-certificates="<?= (int) $usage['certificates'] ?>"
                                    data-requests="<?= (int) $usage['requests'] ?>"><?= esc_html__('Delete', 'wp-certificates') ?><span class="eds-sr-only"> <?= esc_html((string) $document->title) ?></span></button>
                            </td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <nav class="wpc-eds-pagination" aria-label="<?= esc_attr__('Pagination', 'wp-certificates') ?>">
            <span><?= esc_html(sprintf(
                /* translators: 1: first row, 2: last row, 3: total rows */
                __('Showing %1$d–%2$d of %3$d', 'wp-certificates'),
                ($paged - 1) * $list['per_page'] + 1,
                min($list['total'], $paged * $list['per_page']),
                $list['total']
            )) ?></span>
            <?php if ($pages > 1) { ?>
                <span class="wpc-eds-pagination__links">
                    <?php if ($paged > 1) { ?><a class="eds-btn eds-btn--secondary eds-btn--sm" href="<?= esc_url($view_url($view, $paged - 1)) ?>"><?= esc_html__('Previous', 'wp-certificates') ?></a><?php } ?>
                    <?php if ($paged < $pages) { ?><a class="eds-btn eds-btn--secondary eds-btn--sm" href="<?= esc_url($view_url($view, $paged + 1)) ?>"><?= esc_html__('Next', 'wp-certificates') ?></a><?php } ?>
                </span>
            <?php } ?>
        </nav>
    <?php } ?>
</div>
