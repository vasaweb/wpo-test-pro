<?php

defined( 'ABSPATH' ) || exit;

if ( ! WPO_WCPDF_Pro()->dependencies->is_rest_api_supported() ) {
	$notice_type_class = 'error';
	$notice_message    = sprintf(
		/* translators: WordPress version */
		esc_html__( 'The REST API requires WordPress %1$s or higher.', 'wpo_wcpdf_pro' ),
		'<strong>' . WPO_WCPDF_Pro()->dependencies->rest_api_wp_min_version . '</strong>'
	);
} else {
	$is_rest_enabled   = isset( WPO_WCPDF_Pro()->settings->settings['enable_rest_api'] );
	$notice_type_class = $is_rest_enabled ? 'info' : 'warning';
	$action            = $is_rest_enabled ? 'Disable' : 'Enable';
	$notice_message = sprintf(
		/* translators: 1. Enable/Disable word */
		esc_html__( '%1$s the REST API in the %2$sPro%3$s tab of the settings.', 'wpo_wcpdf_pro' ),
		$action,
		'<a href="' . esc_url( admin_url( 'admin.php?page=wpo_wcpdf_options_page&tab=pro' ) ) . '">',
		'</a>'
	);
}

?>
<div class="notice notice-<?php echo $notice_type_class; ?> inline"><p> <?php echo $notice_message; ?></p></div>
<div id="rest-api">
	<div class="wrapper">
		<?php do_action( 'wpo_wcpdf_pro_settings_before_rest_api', $this ); ?>
		<h2><?php esc_html_e( 'API Documentation', 'wpo_wcpdf_pro' ); ?>:</h2>
		<div>
			<h3><?php esc_html_e( 'Samples', 'wpo_wcpdf_pro' ); ?></h3>
			<table class="widefat">
				<thead>
					<tr>
						<td><?php esc_html_e( 'Compatible software', 'wpo_wcpdf_pro' ); ?></td>
						<td><?php esc_html_e( 'File', 'wpo_wcpdf_pro' ); ?></td>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>
							<code><a href="https://insomnia.rest/" target="_blank">Insomnia</a></code>,
							<code><a href="https://www.postman.com/" target="_blank">Postman</a></code>
						</td>
						<td>
							<a download href="<?php echo WPO_WCPDF_Pro()->plugin_url() . '/assets/rest-collections/wpo_wcpdf_rest-collection-v1.1.json'; ?>">rest-collection-v1.1.json</a>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<div id="api-endpoints">
			<div class="endpoint-data">
				<h3><?php esc_html_e( 'Retrieve an order', 'wpo_wcpdf_pro' ); ?></h3>
				<p>
					<?php
					/* translators: API field key */
					printf( esc_html__( 'This API lets you retrieve and view a specific order. All documents data is under %s field in the response', 'wpo_wcpdf_pro' ), '<code>documents</code>' );
					?>
				</p>
				<div class="request"><i class="label label-get">GET</i><p><?php echo esc_url( get_site_url() ); ?>/wp-json/wc/v3/orders/&lt;id&gt;</p></div>
				<h4><?php esc_html_e( 'JSON response example', 'wpo_wcpdf_pro' ); ?>:</></h4>
				<pre><code class="language-json">{
	...
	"documents": {
		"invoice": {
			"number": "1",
			"date": "2024-02-27T15:34:31",
			"date_timestamp": 1709048071
		},
		"proforma": {
			"number": 1,
			"date": "2024-02-23T13:25:50",
			"date_timestamp": 1708694750
		},
		"credit-note": [
			{
				"number": 2,
				"date": "2024-02-28T10:34:31",
				"date_timestamp": 1735893084
			},
			{
				"number": 3,
				"date": "2024-02-28T10:34:32",
				"date_timestamp": 1735892964
			}
		]
	},
}</code></pre>
			<p><strong><?php esc_html_e( 'Since an order can have multiple refunds, and therefore multiple credit notes, the Credit Note output will be an array of documents rather than a single document.' )  ?></strong></p>
			</div>
			<div class="endpoint-data">
				<h3><?php esc_html_e( 'Create or regenerate a document', 'wpo_wcpdf_pro' ); ?></h3>
				<p>
					<?php
					/* translators: API field value */
					printf( esc_html__( 'This API lets you create or regenerate a document for a specific order and view its details. If the document is already created and the regenerate query parameter is %s, it\'ll return the current document details.', 'wpo_wcpdf_pro' ), '<code>false</code>' );
					?>
				</p>
				<div class="request"><i class="label label-post">POST</i><p><?php echo esc_url( get_site_url() ); ?>/wp-json/wc/v3/orders/&lt;id&gt;/documents</p></div>
				<h4><?php esc_html_e( 'Query parameters', 'wpo_wcpdf_pro' ); ?></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Parameter', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wpo_wcpdf_pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>type</code></td>
							<td>string</td>
							<td>
								<i class="label label-info"><?php esc_html_e( 'Required', 'wpo_wcpdf_pro' ); ?></i>
								<p>
									<?php
									/* translators: API field value */
									printf( esc_html__( 'The document type. For example %s.', 'wpo_wcpdf_pro' ), '<code>invoice</code>' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<td><code>regenerate</code></td>
							<td>boolean</td>
							<td>
								<p>
									<?php
									/* translators: 1. value as true, 2. value as false */
									printf( esc_html__( 'If %1$s, it regenerates the document if it already exists. The default is %2$s.', 'wpo_wcpdf_pro' ), '<code>true</code>', '<code>false</code>' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<h4><?php esc_html_e( 'Body', 'wpo_wcpdf_pro' ); ?> <small>form-data</small></></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Parameter', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wpo_wcpdf_pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>number</code></td>
							<td>text</td>
							<td><p><?php esc_html_e( 'Sets the document number.', 'wpo_wcpdf_pro' ); ?></p></td>
						</tr>
						<tr>
							<td><code>date</code></td>
							<td>text</td>
							<td><p>
								<?php
								/* translators: 1: ISO 8601 date format, 2: Example date format */
								printf( esc_html__( 'Sets the document date. It should be in %1$s format: %2$s.', 'wpo_wcpdf_pro' ), 'ISO 8601', 'YYYY-MM-DDTHH:MM:SSZ' );
								?>
							</p></td>
						</tr>
						<tr>
							<td><code>note</code></td>
							<td>text</td>
							<td><p><?php esc_html_e( 'Sets the document note.', 'wpo_wcpdf_pro' ); ?></p></td>
						</tr>
					</tbody>
				</table>
				<h4><?php esc_html_e( 'JSON response example', 'wpo_wcpdf_pro' ); ?>:</h4>
				<pre><code class="language-json">{
	"number": "1078",
	"date": "2024-03-02T13:37:25",
	"date_timestamp": 1709386645
}</code></pre>
			</div>
			<div class="endpoint-data">
				<h3><?php esc_html_e( 'Download a document', 'wpo_wcpdf_pro' ); ?></h3>
				<p><?php esc_html_e( 'This API lets you download a PDF document for a specific order.', 'wpo_wcpdf_pro' ); ?></p>
				<div class="request"><i class="label label-get">GET</i><p><?php echo esc_url( get_site_url() ); ?>/wp-json/wc/v3/orders/&lt;id&gt;/documents</p></div>
				<h4><?php esc_html_e( 'Query parameters', 'wpo_wcpdf_pro' ); ?></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Parameter', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wpo_wcpdf_pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>type</code></td>
							<td>string</td>
							<td>
								<i class="label label-info"><?php esc_html_e( 'Required', 'wpo_wcpdf_pro' ); ?></i>
								<p>
									<?php
									/* translators: API field value */
									printf( esc_html__( 'The document type. For example %s.', 'wpo_wcpdf_pro' ), '<code>invoice</code>' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<td><code>generate</code></td>
							<td>boolean</td>
							<td>
								<p>
									<?php
									/* translators: 1. value as true, 2. value as false */
									printf( esc_html__( 'If %1$s, it generates the document if it doesn\'t already exist. The default is %2$s.', 'wpo_wcpdf_pro' ), '<code>true</code>', '<code>false</code>' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
			<div class="endpoint-data">
				<h3><?php esc_html_e( 'Delete a document', 'wpo_wcpdf_pro' ); ?></h3>
				<p><?php esc_html_e( 'This API lets you delete a document.', 'wpo_wcpdf_pro' ); ?></p>
				<div class="request"><i class="label label-delete">Delete</i><p><?php echo esc_url( get_site_url() ); ?>/wp-json/wc/v3/orders/&lt;id&gt;/documents</p></div>
				<h4><?php esc_html_e( 'Query parameters', 'wpo_wcpdf_pro' ); ?></h4>
				<table class="widefat">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Parameter', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'wpo_wcpdf_pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'wpo_wcpdf_pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><code>type</code></td>
							<td>string</td>
							<td>
								<i class="label label-info"><?php esc_html_e( 'Required', 'wpo_wcpdf_pro' ); ?></i>
								<p>
									<?php
									/* translators: API field value */
									printf( esc_html__( 'The document type. For example %s.', 'wpo_wcpdf_pro' ), '<code>invoice</code>' );
									?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>
				<h4><?php esc_html_e( 'JSON response example', 'wpo_wcpdf_pro' ); ?>:</h4>
				<pre><code class="language-json">{
	"success": "Document deleted."
}</code></pre>
			</div>
		</div>
		<?php do_action( 'wpo_wcpdf_pro_settings_after_rest_api', $this ); ?>
	</div>
</div>