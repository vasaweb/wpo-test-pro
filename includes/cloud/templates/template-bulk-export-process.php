<!DOCTYPE html>
<html>
	<head>
		<meta charset='UTF-8'>
		<?php /* translators: 1. service name */ ?>
		<title><?= printf( __('%s export finished', 'wpo_wcpdf_pro'), $service_name ); ?></title>
		<link rel="stylesheet" href="<?= $plugin_url; ?>/assets/css/cloud-storage-styles.css">
	</head>
	<body>
		<div class='wcpdf-pro-cloud-storage-export'>
			<?= $message; ?>
			<?php if ( ! isset( $errors ) ) : ?>
				<img src="<?= $plugin_url; ?>/assets/images/check.png" id="check">
			<?php endif; ?>
		</div>
	</body>
</html>