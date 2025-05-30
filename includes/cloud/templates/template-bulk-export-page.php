<!DOCTYPE html>
<html>
	<head>
		<meta charset='UTF-8'>
		<meta http-equiv="refresh" content="0; url=<?= esc_url_raw( $new_page ); ?>" />	
		<?php /* translators: 1. service name */ ?>
		<title><?= printf( __('%s export', 'wpo_wcpdf_pro'), $service_name ); ?></title>
		<link rel="stylesheet" href="<?= $plugin_url; ?>/assets/css/cloud-storage-styles.css">
	</head>
	<body>
		<div class='wcpdf-pro-cloud-storage-export'>
			<?= $message; ?>
			<img src="<?= $plugin_url; ?>/assets/images/ajax-loader.gif" id="loader">
		</div>
	</body>
</html>