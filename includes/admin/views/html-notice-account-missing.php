<?php
/**
 * Admin View: Notice - Email missing
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
			<?php _e( $this->method_title . " - desativado", WC_Triibo_Assinaturas::DOMAIN ); ?>
		</strong>:
		<?php
			$text  = "Você deve informar os dados de acesso da API.";
			$text .= "Atualize as credenciais " . $this->get_global_config() . ".";
			_e( $text, WC_Triibo_Assinaturas::DOMAIN );
		?>
	</p>
</div>
