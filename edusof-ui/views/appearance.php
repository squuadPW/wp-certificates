<?php
/**
 * Edusof UI: pantalla Ajustes › Apariencia (la incluye Edusof_UI::render_page()).
 *
 * Con el diseño apagado, la pantalla se dibuja dentro de .eds-scope (variables y componentes sin las clases del
 * <body>), para que el administrador pueda elegir tema y modo antes de encenderlo.
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

$edusof_ui_t        = static fn(string $text): string => translate($text, Edusof_UI::text_domain()); // phpcs:ignore WordPress.WP.I18n
$edusof_ui_settings = Edusof_UI::settings();
$edusof_ui_enabled  = 1 === $edusof_ui_settings['enabled'];
$edusof_ui_view     = $edusof_ui_enabled ? Edusof_UI::mode() : 'light'; // modo de las miniaturas
$edusof_ui_scope    = $edusof_ui_enabled ? '' : 'eds-scope eds-theme-' . $edusof_ui_settings['theme'] . ' eds-mode-light';
// Sin EduSystem la pantalla está bajo Certificación (WP Certificates) y los textos no lo nombran
$edusof_ui_edusystem = defined('EDUSYSTEM_VERSION');
?>
<div class="wrap eds-wrap">
    <div class="<?php echo esc_attr($edusof_ui_scope); ?>"><div class="eds-page eds-appearance">
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="eds-appearance__form">
            <input type="hidden" name="action" value="edusof_ui_save_appearance" />
            <?php wp_nonce_field('edusof_ui_save_appearance'); ?>

            <div class="eds-page-header">
                <div class="eds-breadcrumb"><?php echo esc_html($edusof_ui_t($edusof_ui_edusystem ? 'Settings' : 'Certification')); ?> / <?php echo esc_html($edusof_ui_t('Appearance')); ?></div>
                <h1 class="eds-title"><?php echo esc_html($edusof_ui_t('Appearance')); ?></h1>
                <p class="eds-subtitle"><?php echo esc_html($edusof_ui_edusystem ? $edusof_ui_t('The appearance is the same in EduSystem and WP Certificates.') : $edusof_ui_t('The appearance applies to all the screens of WP Certificates.')); ?></p>
                <div class="eds-actions">
                    <button type="submit" class="eds-btn eds-btn--primary"><?php echo esc_html($edusof_ui_t('Save')); ?></button>
                </div>
            </div>
            <hr class="wp-header-end" />

            <?php if (isset($_GET['updated'])) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
                <div class="eds-notice eds-notice--ok" role="status"><p><?php echo esc_html($edusof_ui_t('Appearance saved.')); ?></p></div>
            <?php endif; ?>

            <section class="eds-card eds-appearance__switch">
                <div class="eds-appearance__switch-text">
                    <h2 id="eds-ui-enabled-label"><?php echo esc_html($edusof_ui_t('Edusof design')); ?></h2>
                    <p id="eds-ui-enabled-help"><?php echo esc_html($edusof_ui_edusystem ? $edusof_ui_t('Turns on the new look of the EduSystem and WP Certificates panel. When it is off, everything looks as before.') : $edusof_ui_t('Turns on the new look of the WP Certificates panel. When it is off, everything looks as before.')); ?></p>
                </div>
                <label class="eds-switch">
                    <input type="checkbox" role="switch" name="edusof_ui_enabled" value="1" aria-labelledby="eds-ui-enabled-label" aria-describedby="eds-ui-enabled-help" <?php checked($edusof_ui_enabled); ?> />
                    <span class="eds-switch__track" aria-hidden="true"><span class="eds-switch__thumb"></span></span>
                </label>
            </section>

            <?php if (!$edusof_ui_enabled) : ?>
                <div class="eds-notice eds-notice--info"><p><?php echo esc_html($edusof_ui_t('The Edusof design is off: the panel looks as before. You can choose the theme and mode now; they are applied when you turn it on.')); ?></p></div>
            <?php endif; ?>

            <section class="eds-card">
                <div class="eds-appearance__head">
                    <div>
                        <h2><?php echo esc_html($edusof_ui_t('Institution theme')); ?></h2>
                        <p class="eds-appearance__help"><?php echo esc_html($edusof_ui_t('Chosen by the administrator for all staff. The colors are already tested to be easy to read.')); ?></p>
                    </div>
                    <span class="eds-badge eds-badge--ok"><?php echo esc_html($edusof_ui_t('Contrast checked (AA)')); ?></span>
                </div>
                <fieldset class="eds-choices eds-choices--themes">
                    <legend class="eds-sr-only"><?php echo esc_html($edusof_ui_t('Theme')); ?></legend>
                    <?php foreach (Edusof_UI::theme_labels() as $edusof_ui_key => $edusof_ui_label) : ?>
                        <label class="eds-choice">
                            <input type="radio" name="edusof_ui_theme" value="<?php echo esc_attr($edusof_ui_key); ?>" <?php checked($edusof_ui_settings['theme'], $edusof_ui_key); ?> />
                            <span class="eds-choice__body">
                                <span class="eds-mini eds-scope eds-theme-<?php echo esc_attr($edusof_ui_key); ?> eds-mode-<?php echo esc_attr($edusof_ui_view); ?>" aria-hidden="true">
                                    <span class="eds-mini__side"><i class="eds-mini__acc"></i><i></i><i></i></span>
                                    <span class="eds-mini__main"><i class="eds-mini__title"></i><i class="eds-mini__soft"></i><i class="eds-mini__btn"></i></span>
                                </span>
                                <span class="eds-choice__name"><span class="eds-mini__dot eds-scope eds-theme-<?php echo esc_attr($edusof_ui_key); ?> eds-mode-<?php echo esc_attr($edusof_ui_view); ?>" aria-hidden="true"></span><?php echo esc_html($edusof_ui_label); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </section>

            <section class="eds-card">
                <div class="eds-appearance__head">
                    <div>
                        <h2><?php echo esc_html($edusof_ui_t('Light or dark mode')); ?></h2>
                        <p class="eds-appearance__help"><?php echo esc_html($edusof_ui_t('Site default. Each person can change it in their profile.')); ?></p>
                    </div>
                </div>
                <fieldset class="eds-choices eds-choices--modes">
                    <legend class="eds-sr-only"><?php echo esc_html($edusof_ui_t('Mode')); ?></legend>
                    <?php foreach (Edusof_UI::mode_labels() as $edusof_ui_key => $edusof_ui_label) : ?>
                        <label class="eds-choice">
                            <input type="radio" name="edusof_ui_mode" value="<?php echo esc_attr($edusof_ui_key); ?>" <?php checked($edusof_ui_settings['mode'], $edusof_ui_key); ?> />
                            <span class="eds-choice__body">
                                <span class="eds-mode-preview eds-mode-preview--<?php echo esc_attr($edusof_ui_key); ?>" aria-hidden="true"></span>
                                <span class="eds-choice__name"><?php echo esc_html($edusof_ui_label[0]); ?></span>
                                <span class="eds-choice__desc"><?php echo esc_html($edusof_ui_label[1]); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            </section>

            <div class="eds-appearance__note">
                <?php echo esc_html($edusof_ui_t('There is no free color picker: that way no change leaves text that cannot be read. If an institution needs its exact color, it is added as a new theme after checking the contrast.')); ?>
            </div>
        </form>
    </div></div>
</div>
