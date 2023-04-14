<?php
/**
 * Triibo Assinaturas Rest class
 *
 * @author      Mateus Costa <mateus@costamateus.com.br>
 * @package 	Triibo_Assinaturas/Classes
 * @version 	1.0.0
 */
defined( 'ABSPATH' ) || exit;

class Triibo_Assinaturas_Rest
{
	/**
	 * Initialize the plugin public actions.
     *
	 * @since   1.0.0
	 */
    public function __construct()
	{
		require_once dirname( __FILE__ ) . "/controllers/v1/class-triibo-assinatura-orders-controller.php";

        add_action( "rest_api_init", [ $this, "register_rest_routes" ] );
    }

    /**
     * Register REST API routes.
     *
	 * @since   1.0.0
	 * @return  void
     */
    public function register_rest_routes()
    {
        foreach ( self::get_rest_namespaces() as $controllers )
        {
            foreach ( $controllers as $controller_class )
            {
                $controller = new $controller_class();
                $controller->register_routes();
            }
        }
    }

    /**
     * Get API namespaces - new namespaces should be registered here.
     *
	 * @since   1.0.0
     * @return  array List of Namespaces and Main controller classes.
     */
    private static function get_rest_namespaces()
    {
        return apply_filters(
            "triibo_assinaturas_rest_namespaces",
            [
                "triibo-assinatura/v1" => [
                    "orders" => "Triibo_Assinaturas_Orders_Controller",
                ]
            ]
        );
    }
}
