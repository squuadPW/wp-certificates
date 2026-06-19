<div class="wrap">

    <?php if (isset($_COOKIE['message']) && !empty($_COOKIE['message'])): ?>
        <div class="notice notice-success is-dismissible">
            <p><?= $_COOKIE['message']; ?></p>
        </div>
        <?php setcookie('message', '', time(), '/'); ?>
    <?php endif; ?>

    <?php if (isset($_COOKIE['message-error']) && !empty($_COOKIE['message-error'])): ?>
        <div class="notice notice-error is-dismissible">
            <p><?= $_COOKIE['message-error']; ?></p>
        </div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php endif; ?>

    <div style="display:flex;width:100%;">
        <a class="button button-outline-primary"
            href="<?= admin_url('admin.php?page=add_admin_form_certificates_templates_content'); ?>"><?= esc_html__('Back', 'wp-certificates'); ?></a>
    </div>

    <div class="grid-container-report-2">
        <div class="card" style="margin: unset !important;">
            <div class="card-header">
                <h3><?= esc_html__('Template content', 'wp-certificates'); ?></h3>
            </div>
            <div class="card-body">

                <form method="post"
                    action="<?= admin_url('admin.php?page=add_admin_form_certificates_templates_content&action=save_template_certificate'); ?>">

                    <div class="form-group">
                        <input type="hidden" name="template_id" value="<?php echo $template->id ?>">

                        <div class="form-group">
                            <input type="checkbox" name="is_active" <?php echo $template->is_active ? 'checked' : '' ?>>
                            <label for="input_id"><?= esc_html__('Active', 'wp-certificates') ?></label>
                        </div>

                        <?php foreach (json_decode($template->fields) as $key => $field) { ?>
                            <?php if ($field->is_visible) { ?>
                                <div class="form-group">
                                    <label for="input_id"><?= $field->position; ?></label>
                                    <textarea class="content-textearea"
                                        name="content[<?= $key ?>]"><?= htmlspecialchars($field->content ?? '') ?></textarea>
                                </div>
                            <?php } ?>
                        <?php } ?>

                    </div>

                    <?php if (isset($template) && !empty($template)): ?>
                        <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                            <button type="submit" class="button button-success" name="action"
                                value="save"><?= esc_html__('Save', 'wp-certificates'); ?></button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <div class="card" style="margin: unset !important;">
            <div class="card-header">
                <h3><?= esc_html__('Preview', 'wp-certificates'); ?></h3>
            </div>
            <div class="card-body">
                <div class="paper">

                </div>
            </div>
        </div>
    </div>
</div>