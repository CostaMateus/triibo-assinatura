<?php
/**
 * Plugin Name:          #Triibo_Assinatura
 * Description:          Triibo pagamento recorrente para WooCommerce. Dependências: Triibo API Services e WooCommerce Subscription.
 * Author:               Mateus Costa
 * Author URI:           https://costamateus.com.br/
 * Version:              2.0.0
 * Text Domain:          triibo_assinaturas
 * RequiresPHP:          7.4
 * RequiresWP:           5.9
 * WC requires at least: 5.8
 * WC tested up to:      6.3
 *
 * @package WooCommerce_Triibo_Assinaturas
 */
defined( "ABSPATH" ) || exit;

if ( ! in_array( "woocommerce/woocommerce.php", apply_filters( "active_plugins", get_option( "active_plugins" ) ) ) ) return;

class WC_Triibo_Assinaturas
{
	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	const VERSION = "2.0.0";

	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	private function __construct()
    {
		add_action( "init", array( $this, "load_init_actions" ) );

		if ( class_exists( "Triibo_Api_Services" ) )
		{
			if ( class_exists( "WC_Subscriptions_Order" ) )
			{
				add_filter( "woocommerce_payment_gateways", [ $this, "register_gateway" ], 99, 1 );

				include_once dirname( __FILE__ ) . "/includes/assinaturas-api.php";
				include_once dirname( __FILE__ ) . "/includes/assinaturas-gateway.php";

				add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ $this, "plugin_action_links" ] );

				if ( is_admin() ) add_action( "admin_notices", [ $this, "ecfb_missing_notice" ] );

				add_action( "admin_menu",                    [ $this, "add_submenu_page"     ], 11 );
				add_action( "triibo_api_service_add_button", [ $this, "add_btn"              ], 11 );
			}
			else
			{
				add_action( "admin_notices", [ $this, "woocommerce_missing_notice" ] );
			}
		}
		else
		{
			add_action( "admin_notices", [ $this, "triibo_api_services_missing_notice" ] );
		}
	}

	/**
	 * Load the plugin init actions.
	 */
	public function load_init_actions()
	{
		load_plugin_textdomain( "triibo_assinaturas" );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
    {
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;
		return self::$instance;
	}

	/**
	 * Register the gateway for use
	 */
	public function register_gateway( $methods )
    {
		$methods[] = "WC_Triibo_Assinaturas_Gateway";
		return $methods;
	}

	/**
	 * WooCommerce Extra Checkout Fields for Brazil notice.
	 */
	public static function ecfb_missing_notice()
	{
		if ( !class_exists( "Extra_Checkout_Fields_For_Brazil" ) )
			include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-ecfb.php";
	}

	/**
	 * WooCommerce missing notice.
	 */
	public static function woocommerce_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-woocommerce.php";
	}

	/**
	 * Triibo-Api-Service missing notice.
	 */
	public static function triibo_api_services_missing_notice()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/html-notice-missing-triibo_api_services.php";
	}

	/**
	 * Action links.
	 *
	 * @param 	array $links
	 * @return 	array
	 */
	public static function plugin_action_links( $links )
    {
        $url            = esc_url( admin_url( "admin.php?page=wc-settings&tab=checkout&section=triibo_assinaturas" ) );
        $text           = __( "Configurações", "triibo_assinaturas" );
		$plugin_links   = [];
		$plugin_links[] = "<a href='{$url}' >{$text}</a>";

		return array_merge( $plugin_links, $links );
	}

	public function add_submenu_page()
	{
		add_submenu_page(
			Triibo_Api_Services::get_name(),
			"Triibo Assinaturas",
			"Assinaturas",
			"manage_options",
			"triibo_assinaturas-settings",
			[ $this, "display_admin_menu_settings" ]
		);
	}

	public function display_admin_menu_settings()
	{
		include_once dirname( __FILE__ ) . "/includes/admin/views/triibo_assinaturas-settings.php";
	}

	public function add_btn()
	{
        $url  = esc_url( admin_url( "admin.php?page=wc-settings&tab=checkout&section=triibo_assinaturas" ) );
		$text = __( "Configurações", "triibo_assinaturas" );
		$link = "<a href='{$url}' >{$text}</a>";

		echo "<p>Triibo Assinaturas | {$link}</p>";
	}

}

add_action( "plugins_loaded", array( "WC_Triibo_Assinaturas", "get_instance" ), 222 );
