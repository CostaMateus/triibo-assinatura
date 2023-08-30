<?php
/**
 * Admin View: Notice - Token missing
 *
 * @author 		Mateus Costa <mateus@costamateus.com.br>
 * @package 	WooCommerce_Triibo_Assinaturas/Admin/Notices
 * @version 	1.0.0
 */
defined( "ABSPATH" ) || exit;

?>

<div class="error inline" >
	<p>
		<strong>
			<?php _e( $this->method_title . " - desativado", "triibo_assinaturas" ); ?>
		</strong>:
		<?php _e( "Você deve informar os dados de acesso da API. Atualize as credenciais {$this->get_global_config()}.", "triibo_assinaturas" ); ?>
	</p>
</div>
