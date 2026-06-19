<div class="tabs-content">
	<div class="wrap">
		<div style="text-align:start;">
			<h1 class="wp-heading-line"><?= esc_html__('User signatures', 'wp-certificates'); ?></h1>
		</div>
		<div style="display:flex;width:100%;justify-content:end;margin-bottom:10px;">
			<a href="<?= admin_url('admin.php?page=add_admin_form_users_signatures_certificate_list_content&section_tab=user_signature_detail'); ?>"
				class="button button-outline-primary"><?= esc_html__('Add Signature', 'wp-certificates'); ?></a>
		</div>
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

		<form action="" id="post-filter" method="get">
			<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
			<?php $list_user_signatures->display() ?>
		</form>
	</div>
</div>