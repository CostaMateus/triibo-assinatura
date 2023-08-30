<?php
/**
 * Admin View: Notice - Currency not supported.
 *
 * @author 		Mateus Costa <mateus@costamateus.com.br>
 * @package 	WooCommerce_Triibo_Assinaturas/Admin/Notices
 * @version 	1.0.0
 */
defined( "ABSPATH" ) || exit;

?>

<div class="error inline">
	<p>
		<strong>
			<?php _e( "Triibo Desativado", "triibo_assinaturas" ); ?>
		</strong>:
		<?php
			$curr = get_woocommerce_currency();
			_e ( "A moeda <code>{$curr}</code> não é suportada. Funciona apenas com Real Brasileiro.", "triibo_assinaturas" );
		?>
	</p>
</div>
