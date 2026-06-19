<div class="tabs-content">
	<div class="wrap">
		<div style="text-align:start;">
			<h1 class="wp-heading-line"><?= esc_html__('Certificates', 'wp-certificates'); ?></h1>
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
			<!-- <p class="search-box">
				<label class="screen-reader-text" for="search-box-id-search-input"><?= esc_html__('Search', 'wp-certificates') . ':'; ?></label>
				<input value="<?= isset($_GET['s']) ? $_GET['s'] : '' ?>" type="search" id="search-box-id-search-input" name="s" placeholder="<?= esc_html__('Search for Student', 'wp-certificates'); ?>" value="<?= (!empty($_POST['s'])) ? $_POST['s'] : ''; ?>">
				<input type="submit" id="search-submit" class="button" value="Search">
			</p> -->
			<input type="hidden" name="page" value="<?php echo $_REQUEST['page'] ?>" />
			<?php $list_certificates->display() ?>
		</form>
	</div>
</div>