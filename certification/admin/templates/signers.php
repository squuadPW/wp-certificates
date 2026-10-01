<?php
/**
 * Firmantes del sistema (admin/signers.php). Variables: $can_manage, $notice, $search, $signers, $legacy, $results.
 */
if (!defined('ABSPATH')) exit;

$post_form = static function (string $action, array $fields, string $label, string $class = 'button', string $confirm = ''): void {
    ?>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:inline-block;margin:0 4px 4px 0"
        <?= $confirm ? 'onsubmit="return confirm(' . esc_attr(wp_json_encode($confirm)) . ');"' : '' ?>>
        <input type="hidden" name="action" value="<?= esc_attr($action) ?>">
        <?php foreach ($fields as $name => $value) : ?>
            <input type="hidden" name="<?= esc_attr($name) ?>" value="<?= esc_attr((string) $value) ?>">
        <?php endforeach; ?>
        <?php wp_nonce_field($action); ?>
        <button type="submit" class="<?= esc_attr($class) ?>"><?= esc_html($label) ?></button>
    </form>
    <?php
};
$status_labels = [
    'invited' => __('Invited', 'edusystem'),
    'active' => __('Active', 'edusystem'),
    'suspended' => __('Suspended', 'edusystem'),
    'retired' => __('Retired', 'edusystem'),
];
?>
<div class="wrap">
    <h1><?= esc_html__('Users and signatures', 'edusystem') ?></h1>
    <p style="max-width:820px"><?= esc_html__('Signers of the system (directors, coordinators...). Each one registers their own signature from their account after an invitation: nobody can upload the signature of another person. Students and parents sign their own documents and do not need an invitation.', 'edusystem') ?></p>

    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <?php if ($can_manage) : ?>
        <h2><?= esc_html__('Invite a signer', 'edusystem') ?></h2>
        <form method="get" action="<?= esc_url(admin_url('admin.php')) ?>" style="margin-bottom:10px">
            <input type="hidden" name="page" value="<?= esc_attr(EDUSYSTEM_SIGNERS_PAGE) ?>">
            <label for="edusystem-signer-search" class="screen-reader-text"><?= esc_html__('Search by name or email', 'edusystem') ?></label>
            <input type="search" id="edusystem-signer-search" name="q" value="<?= esc_attr($search) ?>" placeholder="<?= esc_attr__('Search by name or email', 'edusystem') ?>" style="min-width:320px">
            <button type="submit" class="button"><?= esc_html__('Search', 'edusystem') ?></button>
        </form>

        <?php if ('' !== $search) : ?>
            <?php if ($results) : ?>
                <table class="widefat striped" style="max-width:960px;margin-bottom:12px">
                    <thead><tr><th><?= esc_html__('User', 'edusystem') ?></th><th><?= esc_html__('Email', 'edusystem') ?></th><th><?= esc_html__('Charge', 'edusystem') ?></th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($results as $found) : ?>
                            <tr>
                                <td><?= esc_html($found->display_name) ?></td>
                                <td><?= esc_html($found->user_email) ?></td>
                                <td colspan="2">
                                    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:flex;gap:6px;align-items:center">
                                        <input type="hidden" name="action" value="edusystem_signer_invite">
                                        <input type="hidden" name="user_id" value="<?= (int) $found->ID ?>">
                                        <?php wp_nonce_field('edusystem_signer_invite'); ?>
                                        <input type="text" name="charge" required placeholder="<?= esc_attr__('Charge (e.g. Academic Director)', 'edusystem') ?>" style="min-width:240px">
                                        <button type="submit" class="button button-primary"><?= esc_html__('Invite', 'edusystem') ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p><?= esc_html__('No user with that name or email (students and parents are not listed). You can invite the person to register:', 'edusystem') ?></p>
            <?php endif; ?>
        <?php endif; ?>

        <details <?= ('' !== $search && !$results) ? 'open' : '' ?> style="max-width:960px;margin-bottom:24px">
            <summary style="cursor:pointer;font-weight:600"><?= esc_html__('Invite someone without an account', 'edusystem') ?></summary>
            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr)) auto;gap:8px;align-items:end;margin-top:8px">
                <input type="hidden" name="action" value="edusystem_signer_invite_new">
                <?php wp_nonce_field('edusystem_signer_invite_new'); ?>
                <label><?= esc_html__('Full name', 'edusystem') ?><br><input type="text" name="name" required style="width:100%"></label>
                <label><?= esc_html__('Email', 'edusystem') ?><br><input type="email" name="email" required value="<?= is_email($search) ? esc_attr($search) : '' ?>" style="width:100%"></label>
                <label><?= esc_html__('Charge', 'edusystem') ?><br><input type="text" name="charge" required style="width:100%"></label>
                <button type="submit" class="button button-primary"><?= esc_html__('Send invitation to register', 'edusystem') ?></button>
            </form>
            <p class="description"><?= esc_html__('An account with the Signer role is created (it can only access its own signature and the documents requested from it) and the person receives an email to create a password and draw the signature. The link expires in 72 hours.', 'edusystem') ?></p>
        </details>
    <?php endif; ?>

    <h2><?= esc_html__('Signers', 'edusystem') ?></h2>
    <?php if (!$signers) : ?>
        <p><?= esc_html__('There are no signers yet.', 'edusystem') ?></p>
    <?php else : ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr>
                <th><?= esc_html__('User', 'edusystem') ?></th>
                <th><?= esc_html__('Charge', 'edusystem') ?></th>
                <th><?= esc_html__('Status', 'edusystem') ?></th>
                <th><?= esc_html__('Signature', 'edusystem') ?></th>
                <th><?= esc_html__('Invitation', 'edusystem') ?></th>
                <th></th>
            </tr></thead>
            <tbody>
                <?php foreach ($signers as $signer) : ?>
                    <tr>
                        <td><?= esc_html((string) $signer->display_name) ?><br><span class="description"><?= esc_html((string) $signer->user_email) ?></span></td>
                        <td><?= esc_html((string) $signer->charge) ?></td>
                        <td><?= esc_html($status_labels[$signer->status] ?? $signer->status) ?></td>
                        <td><?= $signer->has_signature ? esc_html__('Registered by the user', 'edusystem') : '—' ?></td>
                        <td>
                            <?php if ($signer->pending_invitation_id) : ?>
                                <?= esc_html(sprintf(__('Pending until %s', 'edusystem'), get_date_from_gmt((string) $signer->invitation_expires, get_option('date_format') . ' ' . get_option('time_format')))) ?>
                            <?php elseif ('invited' === $signer->status) : ?>
                                <?= esc_html__('Expired or revoked', 'edusystem') ?>
                            <?php else : ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if ($can_manage) : ?>
                                <?php if ('active' !== $signer->status && 'suspended' !== $signer->status) {
                                    $post_form('edusystem_signer_resend', ['signer_id' => $signer->id], __('Resend invitation', 'edusystem'));
                                } ?>
                                <?php if ($signer->pending_invitation_id) {
                                    $post_form('edusystem_signer_revoke', ['invitation_id' => $signer->pending_invitation_id], __('Revoke invitation', 'edusystem'), 'button', __('Revoke this invitation?', 'edusystem'));
                                } ?>
                                <?php if ('suspended' === $signer->status) {
                                    $post_form('edusystem_signer_status', ['signer_id' => $signer->id, 'change' => 'reactivate'], __('Reactivate', 'edusystem'));
                                } else {
                                    $post_form('edusystem_signer_status', ['signer_id' => $signer->id, 'change' => 'suspend'], __('Suspend', 'edusystem'), 'button', __('Suspend this signer? Signed documents do not change.', 'edusystem'));
                                } ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($legacy) : ?>
        <h2><?= esc_html__('Legacy signatures (uploaded images)', 'edusystem') ?></h2>
        <p style="max-width:820px" class="description"><?= esc_html__('Signature images uploaded by an administrator before this system. Documents already issued with them remain valid; they are kept unchanged. Invite each person so that they register their own signature.', 'edusystem') ?></p>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th><?= esc_html__('User', 'edusystem') ?></th><th><?= esc_html__('Charge', 'edusystem') ?></th><th><?= esc_html__('Signature', 'edusystem') ?></th><th></th></tr></thead>
            <tbody>
                <?php foreach ($legacy as $old) : ?>
                    <tr>
                        <td><?= esc_html((string) $old->display_name) ?><br><span class="description"><?= esc_html((string) $old->user_email) ?></span></td>
                        <td><?= esc_html((string) $old->charge) ?></td>
                        <td><?= esc_html__('Legacy (image uploaded by an administrator)', 'edusystem') ?></td>
                        <td>
                            <?php if ($can_manage && $old->user_id && !edusystem_signer_by_user((int) $old->user_id)) {
                                $post_form('edusystem_signer_invite', ['user_id' => $old->user_id, 'charge' => $old->charge], __('Invite this user', 'edusystem'), 'button button-primary');
                            } ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
