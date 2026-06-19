<div id="modal-card" class="modal" style="display: block">
  <div class="modal-content" style="overflow: auto; padding: 0 !important">
    <div class="modal-header p-5">
      <h3 style="font-weight: 600"><?= esc_html__('Nacionality') ?></h3>
      <!-- <span class="modal-close"><span class="dashicons dashicons-no-alt"></span></span> -->
    </div>
    <div class="modal-body">
      <div>
        <label for="nacionality"><?= esc_html__('Nacionality', 'aes'); ?><span class="required">*</span></label>
        <select class="form-control" name="nacionality" autocomplete="off" required>
          <option value="" class="text-uppercase"><?= esc_html__('Select an option') ?></option>
          <?php foreach ($demonyms as $demonym) { ?>
            <option value="<?= $demonym ?>" class="text-uppercase"><?= $demonym ?></option>
          <?php } ?>
        </select>
      </div>

      <!-- DATOS DEL GRADO -->
      <div style="text-align:center; margin-top: 3rem">
        <button type="button" class="submit button-success" id="send-request"><?= esc_html__('Save', 'aes'); ?></button>
      </div>
    </div>
  </div>
</div>