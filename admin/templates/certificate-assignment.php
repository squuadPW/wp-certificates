<div class="wrap">

    <div style="text-align:start;">
        <h3 style="margin-top:20px;margin-bottom:0px;text-align:center; border-bottom: 1px solid #8080805c;">
            <?= esc_html__('Certificate assignment', 'wp-certificates'); ?>
        </h3>
    </div>

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

    <div id="dashboard-widgets" class="metabox-holder admin-add-offer" style="width: 210mm !important">
        <div id="postbox-container-1" style="width:100% !important;">
            <div id="normal-sortables">
                <div id="metabox" class="postbox" style="width:100%;min-width:0px;">
                    <div class="inside">

                        <form method="POST" action="<?= esc_url(admin_url('admin-post.php?page=admin_certificate_assignment_content&action=generate_certificates')); ?>">

                            <div class="space-offer" >
                                <label for="certificate_id" >
                                    <b><?= __('Select Certificate','wp-certificates') ?></b>
                                    <br>
                                    <select name="certificate_id" >
                                        <option value=""><?= __('Select a certificate', 'wp-certificates'); ?></option>
                                        <?php foreach ( $documents_certificates as $document_certificates ): ?>
                                            <option value="<?= $document_certificates->id ?>"> <?= $document_certificates->title ?> </option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                            </div>

                            <div class="space-offer" >
                                <label for="student_ids" >
                                    <b><?= __('Select Students','wp-certificates') ?></b>
                                    <br>
                                    <select name="student_ids[]" class="WPCselect2" multiple="multiple">
                                        <?php foreach ( $students as $student) : ?>
                                            <option value="<?= $student->id ?>">
                                                <?= "($student->id) $student->name $student->middle_name $student->last_name $student->middle_last_name" ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>

                                </label>
                            </div>

                            <div style="display:flex;width:100%;justify-content:end;">
                                <button class="button button-primary" type="submit"><?= __('Save Changes','edusystem'); ?></button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


