<?php
/**
 * Mi firma (admin/signers.php). Variables: $user, $signer, $invitation, $active, $notice, $switched, $can_register.
 */
if (!defined('ABSPATH')) exit;
?>
<div class="wrap">
    <h1><?= esc_html__('My signature', 'edusystem') ?></h1>

    <?php if ($notice) : ?>
        <div class="notice <?= $notice['ok'] ? 'notice-success' : 'notice-error' ?> is-dismissible"><p><?= esc_html($notice['message']) ?></p></div>
    <?php endif; ?>

    <?php if ($switched) : ?>
        <div class="notice notice-error"><p><?= esc_html__('A signature cannot be registered from a switched session.', 'edusystem') ?></p></div>
    <?php elseif (!$signer) : ?>
        <p><?= esc_html__('You are not a signer of this system.', 'edusystem') ?></p>
    <?php elseif (in_array($signer->status, ['suspended', 'retired'], true)) : ?>
        <div class="notice notice-warning"><p><?= esc_html__('Your signer account is suspended. Contact the administration.', 'edusystem') ?></p></div>
    <?php endif; ?>

    <?php if ($signer) : ?>
        <p><?= esc_html(sprintf(__('Charge: %s', 'edusystem'), (string) $signer->charge)) ?></p>
    <?php endif; ?>

    <?php if ($active) : ?>
        <h2><?= esc_html__('Your registered signature', 'edusystem') ?></h2>
        <canvas id="edusystem-signature-current" width="600" height="180" style="border:1px solid #c3c4c7;background:#fff;max-width:100%" data-strokes="<?= esc_attr((string) $active->strokes) ?>"></canvas>
        <p class="description"><?= esc_html(sprintf(__('Registered on %s. Changing it does not modify documents you already signed.', 'edusystem'), get_date_from_gmt((string) $active->created_at_utc, get_option('date_format') . ' ' . get_option('time_format')))) ?></p>
    <?php endif; ?>

    <?php if ($can_register) : ?>
        <h2><?= $active ? esc_html__('Replace your signature', 'edusystem') : esc_html__('Draw your signature', 'edusystem') ?></h2>
        <form method="post" action="<?= esc_url(admin_url('admin-post.php')) ?>" id="edusystem-my-signature-form" style="max-width:640px">
            <input type="hidden" name="action" value="edusystem_save_my_signature">
            <input type="hidden" name="strokes" id="edusystem-signature-strokes" value="">
            <?php wp_nonce_field('edusystem_save_my_signature'); ?>
            <canvas id="edusystem-signature-new" style="border:1px solid #8c8f94;background:#fffef0;width:100%;height:200px;display:block"></canvas>
            <p><button type="button" class="button" id="edusystem-signature-clear"><?= esc_html__('Clear', 'edusystem') ?></button></p>
            <p><label><input type="checkbox" name="consent" value="1" required> <?= esc_html(edusystem_signer_profile_consent_text()) ?></label></p>
            <p><label for="edusystem-signature-password"><?= esc_html__('Confirm your password', 'edusystem') ?></label><br>
                <input type="password" id="edusystem-signature-password" name="password" required autocomplete="current-password" style="min-width:280px"></p>
            <p><button type="submit" class="button button-primary"><?= $active ? esc_html__('Replace my signature', 'edusystem') : esc_html__('Register my signature', 'edusystem') ?></button></p>
        </form>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const canvas = document.getElementById("edusystem-signature-new");
                const ratio = Math.max(window.devicePixelRatio || 1, 1);
                canvas.width = canvas.offsetWidth * ratio;
                canvas.height = canvas.offsetHeight * ratio;
                const pad = new EdusystemSignaturePad(canvas);
                document.getElementById("edusystem-signature-clear").addEventListener("click", () => pad.clear());
                document.getElementById("edusystem-my-signature-form").addEventListener("submit", function (event) {
                    if (pad.isEmpty()) {
                        event.preventDefault();
                        alert(<?= wp_json_encode(__('Draw your signature in the box before saving.', 'edusystem')) ?>);
                        return;
                    }
                    document.getElementById("edusystem-signature-strokes").value = JSON.stringify(pad.toData());
                });
            });
        </script>
    <?php endif; ?>

    <?php if ($active) : ?>
        <script>
            document.addEventListener("DOMContentLoaded", function () {
                const canvas = document.getElementById("edusystem-signature-current");
                const pad = new EdusystemSignaturePad(canvas);
                pad.fromData(JSON.parse(canvas.dataset.strokes || "[]"));
                pad.off();
            });
        </script>
    <?php endif; ?>
</div>
