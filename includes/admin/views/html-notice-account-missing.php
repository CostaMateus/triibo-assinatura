<?php
/**
 * Admin View: Notice - Email missing
 *
 * @package WooCommerce_Triibo_Assinaturas/Admin/Notices
 */
defined( "ABSPATH" ) || exit;

?>

<div class="error inline">
	<p>
		<strong>
			<?php _e( $this->method_title . " - desativado", "triibo_assinaturas" ); ?>
		</strong>:
		<?php
			$text  = "Você deve informar os dados de acesso da API.";
			$text .= "Atualize as credenciais " . $this->get_global_config() . ".";
			_e( $text, "triibo_assinaturas" );
		?>
	</p>
</div>
