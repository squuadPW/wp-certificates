<div class="wrap">
    <?php if (isset($card) && !empty($card)): ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Card Details', 'aes'); ?></h2>
    <?php else: ?>
        <h2 style="margin-bottom:15px;"><?= esc_html__('Add Card', 'aes'); ?></h2>
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
    <!-- <div style="display:flex;width:100%;">
        <a class="button button-outline-primary"
            href="<?= admin_url('admin.php?page=add_admin_form_cards_content'); ?>"><?= esc_html__('Back', 'aes'); ?></a>
    </div> -->

    <div id="dashboard-widgets" class="metabox-holder">
        <div id="postbox-container-1" style="width:100% !important;">
            <div id="normal-sortables">
                <div id="metabox" class="postbox" style="width:100%;min-width:0px;">
                    <div class="inside">

                        <form method="post"
                            action="<?= admin_url('admin.php?page=add_admin_form_cards_content&action=save_card'); ?>"
                            enctype="multipart/form-data">
                            <div>
                                <h3
                                    style="margin-top:20px;margin-bottom:0px;text-align:center; border-bottom: 1px solid #8080805c;">
                                    <b><?= esc_html__('Card details', 'aes'); ?></b>
                                </h3>
                                <div style="display: flex; justify-content: space-evenly; margin: 18px;">
                                    <div style="font-weight:400; text-align: center">
                                        <label for="input_id"><b><?= esc_html__('Name', 'aes'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="text" name="name" value="<?= $card->name; ?>">
                                        <input type="hidden" name="card_id" value="<?= $card->id; ?>">
                                    </div>
                                </div>
                                <div style="display: flex; justify-content: space-evenly; margin: 18px;">
                                    <div style="font-weight:400; text-align: start">
                                        <label for="input_id"><b><?= esc_html__('Main side', 'aes'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="file" name="main_side">
                                    </div>
                                    <div style="font-weight:400; text-align: start">
                                        <label for="input_id"><b><?= esc_html__('Rear side', 'aes'); ?></b><span
                                                class="text-danger">*</span></label><br>
                                        <input type="file" name="rear_side">
                                    </div>
                                </div>
                                <h3
                                    style="margin-top:20px;margin-bottom:0px;text-align:center; border-bottom: 1px solid #8080805c;">
                                    <b><?= esc_html__('Preview', 'aes'); ?></b>
                                </h3>
                                <div style="display: flex; justify-content: space-evenly; margin: 18px;">
                                    <div style="font-weight:400; text-align: start">
                                        <?php
                                        echo wp_get_attachment_image($card->main_side_file, [800, 600]);
                                        ?>
                                    </div>
                                    <div style="font-weight:400; text-align: start">
                                        <?php
                                        echo wp_get_attachment_image($card->rear_side_file, [800, 600]);
                                        ?>
                                    </div>
                                </div>
                            </div>

                            <?php if (isset($card) && !empty($card)): ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Saves changes', 'aes'); ?></button>
                                </div>
                            <?php else: ?>
                                <div style="margin-top:20px;display:flex;flex-direction:row;justify-content:end;gap:5px;">
                                    <button type="submit"
                                        class="button button-primary"><?= esc_html__('Add Card', 'aes'); ?></button>
                                </div>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>