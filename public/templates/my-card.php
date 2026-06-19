<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script type="text/javascript" src="https://unpkg.com/qr-code-styling@1.5.0/lib/qr-code-styling.js"></script>

<h2 style="font-size:24px;text-align:center;"><?= esc_html__('My ID', 'form-plugin'); ?></h2>

<?php if (!$card || ($expiration_date < $current_date)) { ?>
    <div style="text-align: center; margin: 30px;">
        <span><button type="button" class="submit" style="width: 220px !important"
                id="send-request-card"><?= esc_html__('Request ID Card', 'wp-certificates') ?></button></span>
    </div>
<?php } ?>

<?php if ($card) { ?>
    <input type="hidden" name="id" value="<?= $card->simple_uuid ?>">
    <input type="hidden" name="validation_url" value="<?= get_option('validation_url'); ?>">
    <input type="hidden" name="image_qr_url" value="<?= get_option('image_qr_url'); ?>">
    <?php
    $style_blur = '';
    if (!$user->profile_picture) {
        $style_blur = 'filter: blur(10px); pointer-events: none; user-select: none; cursor: not-allowed;';
    }
    ?>
    <?php if ($style_blur != '') { ?>
        <div
            style="display: flex;flex-direction: column; gap: 6px; align-items: center; width: 60%; margin: auto; text-align: center;">
            <h4><?= esc_html__('You do not yet have a photo to use on the ID card, please check its status in the documents screen', 'wp-certificates') ?><a
                    style="text-decoration: underline !important; color: #002fbd;"
                    href="<?= $final_url; ?>"><?= esc_html__('here', 'wp-certificates') ?></a></h4>
        </div>
    <?php } ?>
    <div style="display: flex; justify-content: center; align-items: center; gap: 20px; padding: 20px; flex-wrap: wrap; <?= $style_blur ?>"
        id="card-wrapper">
        <!-- Frente -->
        <div style="width: 53.98mm; height: 85.6mm; position: relative; overflow: hidden;" id="main-side">
            <?php echo wp_get_attachment_image($card_default->main_side_file, array('204', '325'), false, [
                'style' => 'width: 100% !important; height: 100% !important; object-fit: fill;'
            ]); ?>
            <div style="position: absolute; top: 25%; text-align: center; width: 100%; transform: translateZ(0);">
                <div>
                    <div
                        style="height: 80px; width: 80px; background-color: gray; margin: auto; border-radius: 100%; overflow: hidden; position: relative; border: 3px solid #E71F3B;">
                        <img src="<?= wp_get_attachment_image_url($user->profile_picture, 'full') ?>"
                            style="height: auto; width: 100%; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -45%);"
                            alt="">
                    </div>
                </div>
                <div
                    style="width: 25%; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5;">
                    <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                        <?= esc_html__('Name', 'wp-certificates') ?>:</div>
                </div>
                <div style="font-size: 14px; color: #0534a5; margin: 0 20px; text-shadow: 0 0 2px white;">
                    <?= $user->name ?>     <?= $user->last_name ?>
                </div>
                <div style="font-size: 8px; color: #0534a5; margin: 0 0 5px; text-shadow: 0 0 1px white;">
                    <?= esc_html__('High School', 'wp-certificates') ?>    <?= in_array('teacher', $roles) ? 'Teacher' : 'Student' ?>
                </div>
                <div style="display: flex; justify-content: center; align-items: center; flex-direction: column;">
                    <div id="qrcode" style="width: 60px; image-rendering: crisp-edges;"></div>
                    <div style="font-size: 8px; color: #0534a5; margin: -3px 0; text-shadow: 0 0 1px white;">
                        <?= $card->simple_uuid ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Reverso -->
        <div style="width: 53.98mm; height: 85.6mm; position: relative; overflow: hidden; transform: translateZ(0);"
            id="rear-side">
            <?php echo wp_get_attachment_image($card_default->rear_side_file, array('204', '325'), false, [
                'style' => 'width: 100% !important; height: 100% !important; object-fit: fill; position: absolute; top: 0; left: 0;'
            ]); ?>
            <div style="position: absolute; top: 56px !important; text-align: center; width: 100%; z-index: 1;">
                <div style="margin: 10px 0;">
                    <div
                        style="width: 40% !important; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5; background: rgba(255,255,255,0.9);">
                        <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                            <?= esc_html__('ID Number', 'wp-certificates') ?>:</div>
                    </div>
                    <div style="font-size: 14px; color: #0534a5; text-shadow: 0 0 2px white;"><?= $user->id_document ?>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-around; margin: 10px 0; padding: 0 5px;">
                    <div style="width: 35%; box-sizing: border-box;">
                        <div
                            style="width: 100% !important; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5; background: rgba(255,255,255,0.9);">
                            <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                                <?= esc_html__('Nacionality', 'wp-certificates') ?>:</div>
                        </div>
                        <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                            <?= $user->nacionality ?? 'N/A' ?>
                        </div>
                    </div>
                    <div style="width: 35%; box-sizing: border-box;">
                        <div
                            style="width: 100% !important; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5; background: rgba(255,255,255,0.9);">
                            <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                                <?= esc_html__('Date of birth', 'wp-certificates') ?>:</div>
                        </div>
                        <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                            <?= $birth_date->format('m/d/Y') ?>
                        </div>
                    </div>
                </div>
                <div style="display: flex; justify-content: space-around; margin: 10px 0; padding: 0 5px;">
                    <div style="width: 35%; box-sizing: border-box;">
                        <div
                            style="width: 100% !important; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5; background: rgba(255,255,255,0.9);">
                            <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                                <?= esc_html__('Emission', 'wp-certificates') ?>:</div>
                        </div>
                        <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                            <?= $emission_date ? $emission_date->format('M, Y') : 'N/A' ?>
                        </div>
                    </div>
                    <div style="width: 35%; box-sizing: border-box;">
                        <div
                            style="width: 100% !important; border-radius: 20px; border-bottom: 2px solid #b70616; margin: 3px auto; color: #0534a5; background: rgba(255,255,255,0.9);">
                            <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                                <?= esc_html__('Expiration', 'wp-certificates') ?>:</div>
                        </div>
                        <div style="height: 15px; font-size: 8px !important; text-shadow: 0 0 1px white;">
                            <?= $expiration_date ? $expiration_date->format('M, Y') : 'N/A' ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($style_blur == '') { ?>
        <div style="display: flex; flex-direction: column; gap: 6px; align-items: center;">
            <span><button type="button" class="submit" style="width: 220px !important"
                    id="download-card"><?= esc_html__('Download PDF', 'wp-certificates') ?></button></span>
            <span><button type="button" class="submit" style="width: 220px !important"
                    id="share-card"><?= esc_html__('Share', 'wp-certificates') ?></button></span>
        </div>
    <?php } ?>
<?php } ?>