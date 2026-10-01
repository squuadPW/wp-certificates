<?php
/**
 * EduSystem - Certificación: recuadros de firma de los documentos (get_signature_section, edusystem_signature_pad_box,
 * get_signature_section_fgu). Movido sin cambios desde includes/html-documents.php (ADR 0004, paso 3c).
 */

if (!defined('ABSPATH')) exit;

function get_signature_section($student): string
{

    global $current_user;
    $lastNameParts = array_filter([$student->last_name, $student->middle_last_name]);
    $firstNameParts = array_filter([$student->name, $student->middle_name]);

    $student_full_name = '';

    if (!empty($lastNameParts)) {
        $student_full_name .= implode(' ', $lastNameParts);
    }

    if (!empty($firstNameParts)) {
        if (!empty($student_full_name)) {
            $student_full_name .= ', ';
        }
        $student_full_name .= implode(' ', $firstNameParts);
    }
    $student_short_name = implode(' ', array_filter([$student->name, $student->last_name]));
    $user_partner = get_user_by('id', $student->partner_id);
    $parent_full_name = $user_partner ? trim($user_partner->first_name . ' ' . $user_partner->last_name) : '';
    $age = floor((time() - strtotime($student->birth_date)) / 31536000);
    
    // Solo se oculta la parte del representante si el estudiante es su propio representante. Antes se ocultaba
    // cuando entraba el representante, que entonces firmaba en el recuadro del estudiante.
    $student_user = get_user_by('email', $student->email);
    $show_parent_info = ($student_user && (int) $student_user->ID === (int) $student->partner_id) ? 0 : 1;
    ob_start();
    ?>
        <input type="hidden" name="auto_signature_student" value="0">
        <div class="signatures_squares">
            <div class="signature_square_field">
                <div>
                    <div style="padding: 8px; text-align: center"><strong><?= __('Signature of applicant:', 'edusystem') ?></strong>
                        <br> <?= esc_html($student_full_name) ?>
                    </div>
                </div>
                <div style="position: relative; padding: 8px;" id="signature-pad-student">
                    <canvas id="signature-student" width="100%" height="200"
                        style="border: 1px solid gray; margin: auto !important; background-color: #ffff005c"></canvas>
                    <div id="sign-here-student"
                        style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                        <span><?= __('SIGN HERE', 'edusystem'); ?></span>
                    </div>
                </div>
                <button id="clear-student" style="width: 100%;"><?= __('Clear', 'edusystem'); ?></button>
                <button id="generate-signature-student" style="width: 100%;"
                    onclick="autoSignature('signature-pad-student', 'signature-text-student', 'generate-signature-student', 'clear-student')"><?= __('Generate signature automatically', 'edusystem') ?></button>
                <div style="position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                    id="signature-text-student">
                    <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                        <?= esc_html($student_short_name) ?>
                    </div>
                </div>
                <button id="clear-student-signature"
                    style="width: 100%; display: none"><?= __('Cancel', 'edusystem') ?></button>
            </div>
            <?php if ($show_parent_info == 1) { ?>
                <input type="hidden" name="auto_signature_parent" value="0">
                <div class="signature_square_field">
                    <div>
                        <div style="padding: 8px; text-align: center"><strong><?= __('Signature of Parent/Legal Guardian:', 'edusystem') ?></strong>
                            <br> <?= esc_html($parent_full_name) ?>
                        </div>
                    </div>
                    <div style="position: relative; padding: 8px;" id="signature-pad-parent">
                        <canvas id="signature-parent" width="100%" height="200"
                            style="border: 1px solid gray; margin: auto !important;  background-color: #ffff005c"></canvas>
                        <div id="sign-here-parent"
                            style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                            <span><?= __('SIGN HERE', 'edusystem') ?></span>
                        </div>
                    </div>
                    <button id="clear-parent" style="width: 100%;"><?= __('Clear', 'edusystem') ?></button>
                    <button id="generate-signature-parent" style="width: 100%;"
                        onclick="autoSignature('signature-pad-parent', 'signature-text-parent', 'generate-signature-parent', 'clear-parent')"><?= __('Generate signature automatically', 'edusystem') ?></button>
                    <div style="    position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                        id="signature-text-parent">
                        <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                            <?= esc_html($parent_full_name) ?>
                        </div>
                    </div>
                    <button id="clear-parent-signature"
                        style="width: 100%; display: none"><?= __('Cancel', 'edusystem') ?></button>
                </div>
            <?php } ?>
        </div>
    <?php

    return ob_get_clean();
}

/**
 * Recuadro de firma de un solo firmante, para colocarlo por separado en la plantilla con {{signature_student}} o
 * {{signature_parent}} (ADR 0003, variables de firma por firmante). Mismo marcado e ids que get_signature_section(),
 * que sigue igual para {{signature_section}}. $role: 'student' o 'parent'. Sin recuadro del representante si el
 * estudiante es su propio representante.
 */
function edusystem_signature_pad_box($student, string $role): string
{
    if (!in_array($role, ['student', 'parent'], true)) {
        return '';
    }
    $student_user = get_user_by('email', $student->email);
    if ('parent' === $role && $student_user && (int) $student_user->ID === (int) $student->partner_id) {
        return '';
    }
    if ('student' === $role) {
        $last = implode(' ', array_filter([$student->last_name, $student->middle_last_name]));
        $first = implode(' ', array_filter([$student->name, $student->middle_name]));
        $full_name = trim($last . ($last && $first ? ', ' : '') . $first);
        $short_name = implode(' ', array_filter([$student->name, $student->last_name]));
        $label = __('Signature of applicant:', 'edusystem');
    } else {
        $partner = get_user_by('id', $student->partner_id);
        $full_name = $short_name = $partner ? trim($partner->first_name . ' ' . $partner->last_name) : '';
        $label = __('Signature of Parent/Legal Guardian:', 'edusystem');
    }
    ob_start();
    ?>
        <div class="signatures_squares">
            <input type="hidden" name="auto_signature_<?= esc_attr($role) ?>" value="0">
            <div class="signature_square_field">
                <div>
                    <div style="padding: 8px; text-align: center"><strong><?= esc_html($label) ?></strong>
                        <br> <?= esc_html($full_name) ?>
                    </div>
                </div>
                <div style="position: relative; padding: 8px;" id="signature-pad-<?= esc_attr($role) ?>">
                    <canvas id="signature-<?= esc_attr($role) ?>" width="100%" height="200"
                        style="border: 1px solid gray; margin: auto !important; background-color: #ffff005c"></canvas>
                    <div id="sign-here-<?= esc_attr($role) ?>"
                        style="pointer-events: none;position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-weight: bold; padding: 10px; color: #4f4e4e7a; font-size: 20px;">
                        <span><?= esc_html__('SIGN HERE', 'edusystem') ?></span>
                    </div>
                </div>
                <button id="clear-<?= esc_attr($role) ?>" style="width: 100%;"><?= esc_html__('Clear', 'edusystem') ?></button>
                <button id="generate-signature-<?= esc_attr($role) ?>" style="width: 100%;"
                    onclick="autoSignature('signature-pad-<?= esc_attr($role) ?>', 'signature-text-<?= esc_attr($role) ?>', 'generate-signature-<?= esc_attr($role) ?>', 'clear-<?= esc_attr($role) ?>')"><?= esc_html__('Generate signature automatically', 'edusystem') ?></button>
                <div style="position: relative; padding: 8px; text-align: center; width: 70%; margin: 8px auto; border-bottom: 1px solid gray; font-family: Great Vibes, cursive; font-size: 28px; display: block; height: 120px; display: none"
                    id="signature-text-<?= esc_attr($role) ?>">
                    <div style="bottom: 0; position: absolute; text-align: center; width: 100%;">
                        <?= esc_html($short_name) ?>
                    </div>
                </div>
                <button id="clear-<?= esc_attr($role) ?>-signature"
                    style="width: 100%; display: none"><?= esc_html__('Cancel', 'edusystem') ?></button>
            </div>
        </div>
    <?php

    return (string) ob_get_clean();
}

function get_signature_section_fgu($student): string
{
    ob_start();
    ?>
        <div>
            <div style="padding: 8px; text-align: center"><strong><?= __('Signature of FGU Official:', 'edusystem') ?></strong></div>
            <img style="width: 160px; margin: 25px auto;" src="http://portal.floridaglobal.university/wp-content/uploads/2025/11/signature-admission-fgu.png" alt="">
        </div>
    <?php

    return ob_get_clean();
}
