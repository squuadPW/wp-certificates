<div class="wrap">
    <?php if (isset($signature) && !empty($signature)): ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Signature details', 'wp-certificates'); ?></h2>
    <?php else: ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Signature', 'wp-certificates'); ?></h2>
    <?php endif; ?>

    <?php if (isset($_COOKIE['message']) && !empty($_COOKIE['message'])) { ?>
        <div class="notice notice-success is-dismissible">
            <p><?= $_COOKIE['message']; ?></p>
        </div>
        <?php setcookie('message', '', time(), '/'); ?>
    <?php } ?>
    <?php if (isset($_COOKIE['message-error']) && !empty($_COOKIE['message-error'])) { ?>
        <div class="notice notice-error is-dismissible">
            <p><?= $_COOKIE['message-error']; ?></p>
        </div>
        <?php setcookie('message-error', '', time(), '/'); ?>
    <?php } ?>
    <div style="display:flex;width:100%;">
        <a class="button button-outline-primary" href="<?= $_SERVER['HTTP_REFERER']; ?>"><?= esc_html__('Back') ?></a>
    </div>

    <div id="dashboard-widgets" class="metabox-holder admin-add-offer" style="width: 70% !important">
        <div id="postbox-container-1" style="width:100% !important;">
            <div id="normal-sortables">
                <div id="metabox" class="postbox" style="width:100%;min-width:0px;">
                    <div class="inside">

                        <form method="post"
                            action="<?= admin_url('admin.php?page=add_admin_form_users_signatures_certificate_list_content&action=save_user_signature'); ?>"
                            enctype="multipart/form-data">
                            <div>
                                <h3
                                    style="margin-top:20px;margin-bottom:0px;text-align:center; border-bottom: 1px solid #8080805c;">
                                    <b><?= esc_html__('Signature Information', 'wp-certificates'); ?></b>
                                </h3>

                                <div style="margin: 18px;">
                                    <input type="hidden" name="signature_id" value="<?= $signature->id ?>">
                                    <input type="hidden" name="attach_id" value="<?= $signature->attach_id ?>">

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="hc"><b><?= esc_html__('User', 'wp-certificates'); ?></b></label><br>
                                        <select class="js-example-basic" name="user_id" required>
                                            <option value="" selected>Assigns an user</option>
                                            <?php foreach ($users as $user) { ?>
                                                <option value="<?= $user->ID ?>" <?= $signature->user_id == $user->ID ? 'selected' : '' ?>><?= $user->first_name ?> <?= $user->last_name ?>
                                                    (<?= $user->user_email ?>)</option>
                                            <?php } ?>
                                        </select>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="name"><b><?= esc_html__('Charge', 'wp-certificates'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="text" name="charge" value="<?= $signature->charge; ?>" required>
                                    </div>

                                    <div style="font-weight:400;" class="space-offer">
                                        <label for="name"><b><?= esc_html__('Signature image', 'wp-certificates'); ?></b>
                                            <?php if (!isset($signature->attach_id) || empty($signature->attach_id)): ?>
                                                <span class="text-danger">*</span>
                                            <?php endif; ?>
                                        </label><br>

                                        <input type="file" name="signature"
                                            <?= !isset($signature->attach_id) || empty($signature->attach_id) ? 'required' : '' ?>
                                            style="width: auto !important;">
                                    </div>

                                    <?php if ($signature) { ?>
                                        <div style="font-weight:400;" class="space-offer">
                                            <img style="width: 250px"
                                                src="<?= wp_get_attachment_image_url($signature->attach_id, 'full'); ?>"
                                                alt="">
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>

                            <?php if (isset($signature) && !empty($signature)): ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Saves changes', 'wp-certificates'); ?></button>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Add signature', 'wp-certificates'); ?></button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>