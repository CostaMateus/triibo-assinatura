<?php
/**
 * Admin options screen.
 *
 * @author 		Mateus Costa <mateus@costamateus.com.br>
 * @package 	WooCommerce_Triibo_Assinaturas/Admin/Settings
 * @version 	1.0.0
 */
defined( "ABSPATH" ) || exit;

?>

<h3><?php echo esc_html( $this->method_title ); ?></h3>

<?php
	if ( "yes" == $this->get_option( "enabled" ) )
	{
		if ( ! $this->using_supported_currency() &&
			 ! class_exists( "woocommerce_wpml" ) ) include dirname( __FILE__ ) . "/html-notice-currency-not-supported.php";

		if ( $this->node->empty( "user" ) ) include dirname( __FILE__ ) . "/html-notice-account-missing.php";
		if ( $this->node->empty( "pass" ) ) include dirname( __FILE__ ) . "/html-notice-password-missing.php";
	}
?>

<?php echo wpautop( $this->method_description ); ?>

<table class="form-table">
	<?php $this->generate_settings_html(); ?>
</table>
