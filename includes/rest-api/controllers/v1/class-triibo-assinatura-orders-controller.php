<?php
/**
 * Triibo Assinaturas Orders Controller
 *
 * @author      Mateus Costa <mateus@costamateus.com.br>
 * @package 	Triibo_Assinaturas/Classes
 * @version 	1.0.0
 */
defined( "ABSPATH" ) || exit;

class Triibo_Assinaturas_Orders_Controller extends WP_REST_Controller
{
	/**
	 * Endpoint namespace.
	 *
	 * @since   1.0.0
	 * @var 	string
	 */
	protected $namespace = "triibo-assinatura/v1";

	/**
	 * Route base.
	 *
	 * @since   1.0.0
	 * @var 	string
	 */
	protected $rest_base = "orders";

	/**
	 * Stores the request.
	 *
	 * @since   1.0.0
	 * @var 	array
	 */
	protected $request   = [];

	/**
	 * Construct
	 *
	 * @since   1.0.0
	 */
	public function __construct() {}

	/**
	 * Register the routes for users.
	 *
	 * @since   1.0.0
	 * @return 	void
	 */
	public function register_routes()
	{
		register_rest_route(
			$this->namespace,
			"/$this->rest_base/update-payment",
			[
				[
					"methods"             => WP_REST_Server::READABLE, // GET
					"callback"            => [ $this, "update_subscription" ],
					"permission_callback" => "__return_true",
					"args"                => $this->get_collection_params(),
				],
			]
		);
	}

	/**
	 *
	 *
	 * @since   3.0.0
	 * @param 	WP_REST_Request $request
	 * @return 	void
	 */
	public function update_subscription( $request )
	{
		$key = $request->get_header( "x-api-key" );

		if ( $key !== "54723162306C6F6A7052646D4C6170693030" || $key !== "0303960716C4D6462507A6F6C60326132745" )
			return new WP_REST_Response( [ "error" => "Unauthorized" ], 401 );

		$order_id = $request->get_param( "orderId"  );
		$status   = $request->get_param( "status"   );
		$approved = $request->get_param( "approved" );

		$order    = wc_get_order( $order_id );

		if ( $approved )
		{
			$data    = [
				"id"     => null,
				"status" => $status,
			];

			$gateway = Triibo_Assinaturas_Gateway::get_instance();
			$gateway->finalize_order( $order, $data );
		}
		else
		{
			$order->update_status( "pending" );
		}

		return;
	}
}
