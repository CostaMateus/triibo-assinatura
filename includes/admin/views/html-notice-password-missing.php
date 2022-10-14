<?php
/**
 * Admin View: Notice - Token missing
 *
 * @package WooCommerce_Triibo_Assinaturas/Admin/Notices
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
