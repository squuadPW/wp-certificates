<?php $wpc_eds = wpc_eds_documents_enabled(); // diseño Edusof: marco único (sin centrar ni 90 %) ?>
<div class="wrap"<?= $wpc_eds ? '' : ' style="margin: 20px auto"' ?>>
  <h1 class="wp-heading-inline"><?= esc_html__('Configuration', 'wp-certificates'); ?></h1>
  <hr class="wp-header-end">

  <?php if (isset($_COOKIE['message']) && !empty($_COOKIE['message'])): ?>
    <div class="notice notice-success is-dismissible">
      <p><?= esc_html(wp_unslash($_COOKIE['message'])); ?></p>
    </div>
    <?php setcookie('message', '', time(), '/'); ?>
  <?php endif; ?>

  <?php if (isset($_COOKIE['message-error']) && !empty($_COOKIE['message-error'])): ?>
    <div class="notice notice-error is-dismissible">
      <p><?= esc_html(wp_unslash($_COOKIE['message-error'])); ?></p>
    </div>
    <?php setcookie('message-error', '', time(), '/'); ?>
  <?php endif; ?>

  <div class="card"<?= $wpc_eds ? '' : ' style="max-width: 90% !important;"' ?>>
    <div class="card-header">
      <h3><?= esc_html__('Settings', 'wp-certificates'); ?></h3>
    </div>
    <div class="card-body-configuration">

      <form method="post"
        action="<?= admin_url('admin.php?page=add_admin_form_configuration_options_certificates_content&action=save_options'); ?>">
        <input type="hidden" name="type">
        <div>
          <div class="form-group" style="padding: 0px 10px 10px 10px;">
            <label
              for="email_coordination"><?= esc_html__('URL of the site where you will be redirected to show its validity.', 'wp-certificates'); ?>
              <span>(https://xxx.xxx.xxx/)</span></label> <br>
            <input class="full-input" name="validation_url" type="text" id="validation_url"
              value="<?= get_option('validation_url'); ?>" required>
          </div>
          <div class="form-group" style="padding: 0px 10px 10px 10px;">
            <label for="email_academic_management"><?= esc_html__('Image of QR URL', 'wp-certificates'); ?></label> <br>
            <input class="full-input" name="image_qr_url" type="text" id="image_qr_url"
              value="<?= get_option('image_qr_url'); ?>" required>
          </div>
          <div class="form-group" style="padding: 10px">
            <input type="checkbox" id="disable-idcard" name="disable_idcard" <?php echo get_option('disable_idcard') == 'on' ? 'checked' : '' ?>>
            <label for="disable-idcard"><?= __('Disable ID Card', 'wp-certificates'); ?></label>
          </div>
        </div>
        <div class="form-group" id="save-configuration" style="text-align: center">
          <button type="submit" class="btn btn-primary"><?= esc_html__('Save settings', 'wp-certificates'); ?></button>
        </div>
      </form>
    </div>
  </div>

  <?php // Documento de identidad de quien firma (ADR 0007 de Edusof): solo con el permiso squuad_cert_manage_id_documents
  if (function_exists('squuad_cert_id_document_settings_section')) {
    squuad_cert_id_document_settings_section();
  } ?>

  <?php // Firma de documentos (ADR 0011 de Edusof): «Subir imagen» en la ventana de firma y nota legal del certificado
  if (function_exists('squuad_cert_signing_settings_section')) {
    squuad_cert_signing_settings_section();
  } ?>

  <?php // Motor de PDF (ADR 0013 de Edusof): navegador o servidor de PDF; solo el administrador de WordPress lo cambia
  if (function_exists('squuad_cert_pdf_engine_settings_section')) {
    squuad_cert_pdf_engine_settings_section();
  } ?>
</div>