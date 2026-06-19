<div style="margin: 20px auto">

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

  <div class="card" style="max-width: 90% !important;">
    <div class="card-header">
      <h3><?= esc_html__('Settings', 'aes'); ?></h3>
    </div>
    <div class="card-body-configuration">

      <form method="post"
        action="<?= admin_url('admin.php?page=add_admin_form_configuration_options_certificates_content&action=save_options'); ?>">
        <input type="hidden" name="type">
        <div>
          <div class="form-group" style="padding: 0px 10px 10px 10px;">
            <label
              for="email_coordination"><?= esc_html__('URL of the site where you will be redirected to show its validity.', 'aes'); ?>
              <span>(https://xxx.xxx.xxx/)</span></label> <br>
            <input class="full-input" name="validation_url" type="text" id="validation_url"
              value="<?= get_option('validation_url'); ?>" required>
          </div>
          <div class="form-group" style="padding: 0px 10px 10px 10px;">
            <label for="email_academic_management"><?= esc_html__('Image of QR URL', 'aes'); ?></label> <br>
            <input class="full-input" name="image_qr_url" type="text" id="image_qr_url"
              value="<?= get_option('image_qr_url'); ?>" required>
          </div>
          <div class="form-group" style="padding: 10px">
            <input type="checkbox" id="disable-idcard" name="disable_idcard" <?php echo get_option('disable_idcard') == 'on' ? 'checked' : '' ?>>
            <label for="disable-idcard"><?= __('Disable ID Card'); ?></label>
          </div>
        </div>
        <div class="form-group" id="save-configuration" style="text-align: center">
          <button type="submit" class="btn btn-primary"><?= esc_html__('Save settings', 'aes'); ?></button>
        </div>
      </form>
    </div>
  </div>
</div>