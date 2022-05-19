<?php
/**
 * WooCommerce Triibo Assinaturas API class
 *
 * @package WooCommerce_Triibo_Assinaturas/Classes/API
 * @version 1.0
 */
defined( "ABSPATH" ) || exit;

class WC_Triibo_Assinaturas_API extends WP_REST_Controller
{
	/**
	 * Endpoint namespace.
	 *
	 * @var string
	 */
	protected $namespace = "triibo_assinaturas/v1";

	/**
	 * Stores the request.
	 *
	 * @var array
	 */
	protected $request = [];

	/**
	 * Gateway class.
	 *
	 * @var WC_Triibo_Assinaturas_Gateway
	 */
	protected $gateway;

	public function __construct( $gateway = null )
	{
		$this->gateway = $gateway;

		add_action("rest_api_init", [ $this, "register_routes" ] );
	}

	public function register_routes()
	{
		register_rest_route(
			$this->namespace,
			"/brand/(?P<cardNumber>[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}+)",
			[
				"methods"             => WP_REST_Server::READABLE, // GET
				"callback"            => [ $this, "get_brand" ],
				"permission_callback" => "__return_true",
				"args"                => [
					"cardNumber" => [
						"required" => true,
						"type"     => "string",
					],
					"user_id"       => [
						"required" => true,
						"type"     => "string",
					],
				],
			]
		);
	}

	/**
	 * Retorna URL do Marketplace Dev
	 *
	 * @return string
	 */
	public function get_url()
	{
		$env = ( $this->gateway->mkt_homol == "yes" ) ? "dev-" : "";

		return "https://" . $env . "marketplace.triibo.com.br";
	}

	/**
	 * Retorna URL da API Triibo Node
	 *
	 * @return void
	 */
	public function get_api_url()
	{
		$env = ( $this->gateway->homol == "yes" ) ? "-hml" : "";

		return "https://api" . $env . ".triibo.com.br";
	}

	private function exec($api, $params = null)
    {
        $curl   = curl_init();

		$url    = $this->get_api_url() . "/$api";
		$header = [
			"Origin: " . $this->get_url(),
			"Authorization: Bearer " . $this->token,
			"Content-type: application/json",
		];

		curl_setopt_array( $curl, [
			CURLOPT_URL            => $url,
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_POSTFIELDS     => ( $params ) ? json_encode( $params ) : null,
			CURLOPT_HTTPHEADER     => $header,
        ]);

        $resp = curl_exec( $curl );

        curl_close( $curl );

		return json_decode( $resp, TRUE );
    }

	/**
	 * Consulta gateway para gerar token para uso nas chamadas das APIs
	 *
	 * @return void
	 */
	private function generate_token( $uid = null )
	{
		$user = ( $this->gateway->homol == "yes" )
				? base64_encode( $this->gateway->homol_account . ":" . $this->gateway->homol_password )
				: base64_encode( $this->gateway->account       . ":" . $this->gateway->password       );

		$curl = curl_init();

		curl_setopt_array($curl, [
			CURLOPT_URL            => $this->get_api_url() . "/auth",
			CURLOPT_RETURNTRANSFER => TRUE,
			CURLOPT_ENCODING       => "",
			CURLOPT_MAXREDIRS      => 10,
			CURLOPT_TIMEOUT        => 60,
			CURLOPT_SSL_VERIFYPEER => FALSE,
			CURLOPT_FOLLOWLOCATION => TRUE,
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST  => "POST",
			CURLOPT_HTTPHEADER     => [
				"Origin: " . $this->get_url(),
				"Authorization: Basic $user",
				( $uid ) ? "uid: $uid" : "",
			],
		]);

		$resp = curl_exec( $curl );

		curl_close( $curl );

		$resp = json_decode( $resp, TRUE );

		if ( $resp["success"] )
		{
			$this->token      = $resp["token"];
			$this->date_token = new DateTime();
			return;
		}
		else
		{
			$this->generate_token( $uid );
			return;
		}
	}

	/**
	 * Renova token causo expirado
	 *
	 * @return void
	 */
	private function renew_token( $uid = null )
	{
		if ( $uid )
		{
			$this->generate_token( $uid );
			return;
		}

		if ( ! $this->date_token )
		{
			$this->generate_token();
			return;
		}

		$now  = new DateTime();
		$diff = $now->diff( $this->date_token );

		if ( $diff->h > 10 ) $this->generate_token();

		return;
	}

	/**
	 * Valida qual a bandeira dado o número do cartão
	 *
	 * @param array $data
	 * @return array
	 */
	public function get_brand( $data )
	{
		$this->renew_token();

		$params   = [
			"payload" => [
				"gateway"    => $this->gateway->service,
				"cardNumber" => $data["cardNumber"],
			]
		];

		$response = $this->exec("payments/brand", $params);

		// $params["payload"]["cardNumber"] = "****-****-****-" . substr( $data["cardNumber"], -4 );
		// $this->log( "INFO - get_brand : " . json_encode( [ $params, $response ] ) );

		return $response;
	}

	/**
	 * Get uid Triibo
	 *
	 * @param string $phone
	 * @return array
	 */
	private function get_uid_triibo( $phone )
	{
		$this->renew_token();

		$phone       = "+" . preg_replace( "/\D/", "", $phone );
		$params      = [ "phones" => [ $phone ] ];

		$resp        = $this->exec( "users/findUIDsByPhones", $params );

		$resp["uid"] = $resp["usersUid"][$phone];

		unset( $resp["usersUid"] );

		return $resp;
	}

	/**
	 * Tokeniza um cartão
	 *
	 * @param array $data
	 * @return array
	 */
	private function create_card_token( $data )
	{
		$this->renew_token( $data["uid"] );

		$params = [
			"payload" => [
				"gateway"  => $this->gateway->service,
				"cardInfo" => [
					"holder"         => $data["holder"],
					"cardNumber"     => $data["cardNumber"],
					"expirationDate" => $data["expirationDate"],
					"securityCode"   => $data["securityCode"],
					"brand"          => $data["brand"]
				],
			]
		];

		return $this->exec( "payments/createToken", $params );
	}

	/**
	 * Processa pagamento
	 *
	 * @param array $data
	 * @return array
	 */
	private function do_payment( $data )
	{
		$this->renew_token( $data["uid"] );

		$params = [
			"payload" => [
				"gateway" => $this->gateway->service,
				"order"   => [
					"orderId"     => $data["orderId"],
					"totalAmount" => $data["totalAmount"], // em centavos
					"description" => $data["description"],
					"tokenIndex"  => $data["tokenIndex"],
					"name"        => $data["name"],
					"document"    => $data["document"],
					"cellPhone"   => $data["phone"],
				],
			]
		];

		return $this->exec( "payments/doPayment", $params );
	}

	/**
	 * Process the subscription request from checkout
	 *
	 * @param  WC_Order $order  Order data.
	 * @param  array    $posted Posted data.
	 *
	 * @return array
	 */
	public function do_subscription_request( $order, $posted )
	{
		$is_payment_change             = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
		$order_contains_failed_renewal = false;

		// Payment method changes act on the subscription not the original order
		if ( $is_payment_change )
		{
			// $subscription = wcs_get_subscription( wcs_get_objects_property( $order, "id" ) );
			// $order        = $subscription->get_parent();

			// $current_user = wp_get_current_user();
			// $plan         = get_post_meta( $subscription->get_id(), "_pagseguro_assinatura", true );
			// $response     = $this->update_payment_method( $current_user, $plan, $posted, $subscription );

			// if ( isset( $response["success"] ) )
			// {
			// 	return [
			// 		"result"   => "success",
			// 		"redirect" => get_permalink( get_option("woocommerce_myaccount_page_id") )
			// 	];

			// }
			// else
			// {
			// 	wc_add_notice( $response["error"][0], "error" );

			// 	$url = wp_nonce_url( add_query_arg( [ "change_payment_method" => $subscription->get_id() ], $subscription->get_checkout_payment_url() ) );

			// 	wc_add_notice( sprintf( __("Payment method update failed. <a href="%s">Try Again</a>", "triibo_assinaturas"), $url ), "error" );
			// 	wp_redirect( $subscription->get_view_order_url() );
			// 	exit;
			// }

			return [
				"success" => false,
				"error"   => "4. is_payment_change"
			];
		}
		else
		{
			// Otherwise the order is the $order
			if ( $cart_item = wcs_cart_contains_failed_renewal_order_payment() ||
				 false !== WC_Subscriptions_Renewal_Order::get_failed_order_replaced_by( wcs_get_objects_property( $order, "id" ) ) )
			{
				$subscriptions                 = wcs_get_subscriptions_for_renewal_order( $order );
				$order_contains_failed_renewal = true;
			}
			else
			{
				$subscriptions                 = wcs_get_subscriptions_for_order( $order, [ "order_type" => [ "switch", "parent", "renewal" ] ] );
			}

			// Only one subscription allowed per order with PayPal
			$subscription = array_pop( $subscriptions );
		}

		if ( $order_contains_failed_renewal || ! empty( $subscription ) )
		{
			// Search for user triibo
			$response = $this->get_uid_triibo( $posted["phone"] );

			if ( ! $response["success"] || $response["uid"] === null )
			{
				$error = isset( $response["error"] ) ? "Falha ao consultar usuario Triibo." : "Usuario Triibo nao encontrado.";

				$this->log( "ERROR - get_uid_triibo : " . json_encode( [ $error, $response ] ) );

				return [
					"success" => false,
					"error"   => "1. $error"
				];
			}

			$uid      = $response["uid"];

			// Creating a card token for the user
			$data     = [
				"uid"            => $uid,
				"holder"         => $posted["holder"],
				"cardNumber"     => $posted["card_number"],
				"expirationDate" => $posted["month"] . "/" . $posted["year"],
				"securityCode"   => $posted["cvc"],
				"brand"          => $posted["brand"],
			];

			$response = $this->create_card_token( $data );

			if ( ! $response["success"] )
			{
				$error = "Falha ao consultar token.";

				$this->log( "ERROR - create_card_token : " . json_encode( [ $error, $response ] ) );

				return [
					"success" => false,
					"error"   => "2. $error"
				];
			}

			if ( $order->get_total() > 0 )
			{
				// Doing the payment request
				$item  = current( $order->get_items() );
				$desc  = $item->get_name();

				$total = intval( strval( $order->get_total() * 100 ) ); // em centavos
				$name  = $order->get_user()->get( "display_name" ) ?: $posted["holder"];
				$doc   = $order->get_meta( "_billing_cpf", true )  ?: $posted["cpf"];
				$phone = "+" . preg_replace( "/\D/", "", $posted["phone"] );

				$index = $response["tokenInfo"]["index"];

				$data  = [
					"uid"         => $uid,
					"orderId"     => $order->ID,
					"totalAmount" => $total, // em centavos
					"description" => $desc,
					"tokenIndex"  => $index,
					"name"        => $name,
					"document"    => $doc,
					"phone"       => $phone,
				];

				$response = $this->do_payment( $data );
			}
			else
			{
				$response = [
					"success"     => true,
					"paymentInfo" => [
						"id"      => null,
						"status"  => 1,
						"message" => "Free Trial"
					]
				];
			}

			if ( ! $response["success"] )
			{
				$error = "Ocorreu um erro ao processar seu pagamento, tente novamente.";

				$this->log( "ERROR - do_payment : " . json_encode( [ $error, $response, $data ] ) );

				return [
					"success" => false,
					"error"   => "3. $error"
				];
			}

			$this->log( "INFO - do_payment : " . json_encode( $response ) );

			return [
				"success" => true,
				"message" => "Pagamento processado com sucesso.",
				"data"    => $response["paymentInfo"]
			];
		}

		$this->log( "WARNING - Could not process subscription on do_subscription_request()" );

		// Return error message.
		return [
			"success" => false,
			"error"   => "<strong>Triibo Recorrente</strong>: " . __( "Ocorreu um erro ao processar seu pagamento, tente novamente. Ou entre em contato conosco para obter assistência.", "triibo_assinaturas" ),
		];
	}

	private function log( $message )
	{
		if ( $this->gateway->debug == "yes" ) $this->gateway->log->add( $this->gateway->id, $message );
	}
}
