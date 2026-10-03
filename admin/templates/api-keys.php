<?php
/**
 * Certificación > Conexiones API. Variables: $keys (por id, más recientes primero), $scopes (permiso => [nombre,
 * descripción]), $new_key (clave recién creada o null), $notice, $endpoint (URL base de la API).
 */

defined('ABSPATH') || exit;

$date = static fn(?string $utc): string => $utc ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($utc . ' UTC')) : '—';
?>
<div class="wrap">
    <h1><?= esc_html__('API connections', 'wp-certificates') ?></h1>
    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <?php if ($new_key) : ?>
        <div class="notice notice-warning" style="padding:12px 16px">
            <p><strong><?= esc_html__('Copy this key now: it will not be shown again.', 'wp-certificates') ?></strong></p>
            <p><input type="text" readonly value="<?= esc_attr($new_key) ?>" id="squuad-cert-new-key" style="width:100%;max-width:640px;font-family:monospace" onclick="this.select()">
                <button type="button" class="button" onclick="navigator.clipboard.writeText(document.getElementById('squuad-cert-new-key').value);this.textContent=<?= esc_attr(wp_json_encode(__('Copied', 'wp-certificates'))) ?>;"><?= esc_html__('Copy', 'wp-certificates') ?></button></p>
            <p class="description"><?= esc_html__('Give it only to the team of the connected system, through a safe channel. It must be kept on their server, never in a web page or in a URL.', 'wp-certificates') ?></p>
        </div>
    <?php endif; ?>

    <p style="max-width:820px"><?= esc_html__('Keys for other systems (for example, the main site of the institution) to check this platform from their server. Today a key can only verify a document by its code; it never searches by person. Every creation, revocation and use is saved in the log.', 'wp-certificates') ?></p>
    <p style="max-width:820px"><?= esc_html__('Address:', 'wp-certificates') ?> <code><?= esc_html($endpoint) ?>&lt;<?= esc_html__('code', 'wp-certificates') ?>&gt;</code> · <?= esc_html__('Header:', 'wp-certificates') ?> <code>X-API-Key: &lt;<?= esc_html__('key', 'wp-certificates') ?>&gt;</code></p>

    <h2><?= esc_html__('New connection', 'wp-certificates') ?></h2>
    <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" style="max-width:820px">
        <input type="hidden" name="action" value="squuad_cert_api_key_create">
        <?php wp_nonce_field('squuad_cert_api_key_create'); ?>
        <p><label for="squuad-cert-api-name"><strong><?= esc_html__('Name of the connected system', 'wp-certificates') ?></strong></label><br>
            <input type="text" name="name" id="squuad-cert-api-name" required maxlength="100" style="width:100%;max-width:420px" placeholder="<?= esc_attr__('E.g. Main site', 'wp-certificates') ?>"></p>
        <p><strong><?= esc_html__('Permissions', 'wp-certificates') ?></strong></p>
        <?php foreach ($scopes as $scope => [$label, $description]) : ?>
            <p><label><input type="checkbox" name="scopes[]" value="<?= esc_attr($scope) ?>" checked> <?= esc_html($label) ?></label><br>
                <span class="description"><?= esc_html($description) ?></span></p>
        <?php endforeach; ?>
        <?php submit_button(__('Generate key', 'wp-certificates'), 'primary', 'submit', false); ?>
    </form>

    <h2 style="margin-top:28px"><?= esc_html__('Keys', 'wp-certificates') ?></h2>
    <table class="widefat striped" style="max-width:1000px">
        <thead>
            <tr>
                <th><?= esc_html__('Name', 'wp-certificates') ?></th>
                <th><?= esc_html__('Key', 'wp-certificates') ?></th>
                <th><?= esc_html__('Permissions', 'wp-certificates') ?></th>
                <th><?= esc_html__('Created', 'wp-certificates') ?></th>
                <th><?= esc_html__('Last use', 'wp-certificates') ?></th>
                <th><?= esc_html__('Status', 'wp-certificates') ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php if (!$keys) : ?>
                <tr><td colspan="7"><?= esc_html__('There are no keys yet.', 'wp-certificates') ?></td></tr>
            <?php endif; ?>
            <?php foreach ($keys as $id => $row) :
                $author = get_userdata((int) $row['created_by']); ?>
                <tr>
                    <td><strong><?= esc_html($row['name']) ?></strong></td>
                    <td><code><?= esc_html($row['prefix']) ?>…</code></td>
                    <td><?= esc_html(implode(', ', array_map(static fn($s) => $scopes[$s][0] ?? $s, (array) $row['scopes']))) ?></td>
                    <td><?= esc_html($date($row['created_at'])) ?><br><span class="description"><?= esc_html($author ? $author->display_name : '#' . (int) $row['created_by']) ?></span></td>
                    <td><?= esc_html($date($row['last_used_at'])) ?></td>
                    <td><?= $row['revoked_at'] ? '<span style="color:#b32d2e">' . esc_html(sprintf(__('Revoked %s', 'wp-certificates'), $date($row['revoked_at']))) . '</span>' : '<span style="color:green">' . esc_html__('Active', 'wp-certificates') . '</span>' ?></td>
                    <td>
                        <?php if (!$row['revoked_at']) : ?>
                            <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" onsubmit="return confirm(<?= esc_attr(wp_json_encode(__('Revoke this key? The connected system will stop working with it at once.', 'wp-certificates'))) ?>);">
                                <input type="hidden" name="action" value="squuad_cert_api_key_revoke">
                                <input type="hidden" name="id" value="<?= esc_attr($id) ?>">
                                <?php wp_nonce_field('squuad_cert_api_key_revoke_' . $id); ?>
                                <button type="submit" class="button button-link-delete"><?= esc_html__('Revoke', 'wp-certificates') ?></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
