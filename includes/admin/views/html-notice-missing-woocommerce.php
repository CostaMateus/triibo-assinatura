<?php
/**
 * Missing WooCommerce notice.
 *
 * @package WooCommerce_Triibo_Assinaturas/Admin/Notices
 */
defined( "ABSPATH" ) || exit;

$is_installed = false;

if ( function_exists( "get_plugins" ) )
{
	$all_plugins  = get_plugins();
	$key          = "woocommerce-subscriptions/woocommerce-subscriptions.php";
	$is_installed = array_key_exists($key, $all_plugins);
}
?>

<div class="error">
	<p>
		<strong>
			<?php esc_html_e( "#Triibo_Assinatura", "triibo_assinaturas" ); ?>
		</strong>
		<?php esc_html_e( "depende da última versão do WooCommerce Subscriptions para funcionar!", "triibo_assinaturas" ); ?>
	</p>

	<?php if ( $is_installed && current_user_can( "install_plugins" ) ) : ?>
		<p>
			<a href="<?php echo esc_url( wp_nonce_url( self_admin_url( "plugins.php?action=activate&plugin=woocommerce-subscriptions/woocommerce-subscriptions.php&plugin_status=active" ), "activate-plugin_woocommerce-subscriptions/woocommerce-subscriptions.php" ) ); ?>" class="button button-primary">
				<?php esc_html_e( "Ative WooCommerce Subscriptions", "triibo_assinaturas" ); ?>
			</a>
		</p>
	<?php else : ?>
		<p>
			<a href="https://woocommerce.com/products/woocommerce-subscriptions/?aff=10217&cid=1068958" target="_blank" class="button button-primary">
				<?php esc_html_e( "Purchase WooCommerce Subscriptions", "triibo_assinaturas" ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
