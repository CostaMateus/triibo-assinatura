<?php
/**
 * Admin View: Notice - Currency not supported.
 *
 * @package WooCommerce_Triibo_Assinaturas/Admin/Notices
 */
defined( "ABSPATH" ) || exit;

?>

<div class="error inline">
	<p>
		<strong>
			<?php _e( "Triibo Desativado", WC_Triibo_Assinaturas::DOMAIN ); ?>
		</strong>:
		<?php printf( __( "A moeda <code>%s</code> não é suportada. Funciona apenas com Real Brasileiro.", WC_Triibo_Assinaturas::DOMAIN ), get_woocommerce_currency() ); ?>
	</p>
</div>
