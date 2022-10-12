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

	/**
	 * API class.
	 *
	 * @var Triibo_Api_Node
	 */
	protected $node;

	public function __construct( $gateway = null, $node = null )
	{
		$this->node    = $node;
		$this->gateway = $gateway;
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

		register_rest_route(
			$this->namespace,
			"/payment/(?P<order_id>[0-9]+)/update/(?P<approved>[a-zA-Z]+)",
			[
				"methods"             => WP_REST_Server::READABLE, // GET
				"callback"            => [ $this, "update_subscription" ],
				"permission_callback" => "__return_true",
				"args"                => [
					"order_id" => [
						"required" => true,
						"type"     => "string",
					],
					"approved" => [
						"required" => true,
						"type"     => "string",
					],
				],
			]
		);
	}

	/**
	 * Consulta gateway para gerar token para uso nas chamadas das APIs
	 *
	 * @return void
	 */
	public function generate_token( $uid, $user_id )
	{
		$resp = $this->api->auth( "uid: {$uid}" );

		if ( $resp[ "success" ] )
		{
			$token    = $resp[ "data"  ][ "token" ];
			$validity = ( new DateTime() )->format( "Y-m-d H:i:s" );

			update_user_meta( $user_id, "_triibo_auth_token",    $token    );
			update_user_meta( $user_id, "_triibo_auth_validity", $validity );
		}
		else
		{
			$this->log( "INFO - generate_token : " . json_encode( [
				"code"    => $resp[ "code"    ],
				"message" => $resp[ "message" ],
				"error"   => $resp[ "error"   ]
			] ) );

			$this->generate_token( $uid, $user_id );
		}
	}

	/**
	 * Verifica validade do token, se vencido, gera um novo
	 *
	 * @return string
	 */
	public function validate_token( $user_id )
	{
		$uid      = get_user_meta( $user_id, "_triibo_id",            true );
		$token    = get_user_meta( $user_id, "_triibo_auth_token",    true );
		$validity = get_user_meta( $user_id, "_triibo_auth_validity", true );

		if ( !$token )
		{
			$this->log( "INFO - validate_token : user without token $user_id" );
			$this->generate_token( $uid, $user_id );
		}
		else
		{
			$dtval = DateTime::createFromFormat( "Y-m-d H:i:s", $validity );
			$now   = new DateTime();
			$diff  = $now->diff( $dtval );

			if ( $diff->d > 0 || $diff->h > 10 )
			{
				$this->log( "INFO - validate_token : experied token $user_id" );
				$this->generate_token( $uid, $user_id );
			}
		}

		$token = get_user_meta( $user_id, "_triibo_auth_token", true );

		return $token;
	}




	// /**
	//  * Executa as chamadas
	//  *
	//  * @param string $api 		Rota da api a ser chamada
	//  * @param string $token     Token do usuario
	//  * @param array $params		Dados a serem enviados no body
	//  * @return array
	//  */
	// private function exec( $api, $token, $params = null )
    // {
	// 	$this->log( "INFO - executando api : $api" );

	// 	$curl   = curl_init();

	// 	// $url    = $this->get_api_url() . "/$api";
	// 	// $origin = $this->get_url();

	// 	$header = [
	// 		"Origin: $origin",
	// 		"Content-type: application/json",
	// 	];

	// 	if ( $api === "auth" )
	// 	{
	// 		$api_user = ( $this->gateway->homol == "yes" )
	// 				? base64_encode( $this->gateway->homol_account . ":" . $this->gateway->homol_password )
	// 				: base64_encode( $this->gateway->account       . ":" . $this->gateway->password       );

	// 		array_push( $header, "Authorization: Basic $api_user" );
	// 		array_push( $header, $token );
	// 	}
	// 	else
	// 	{
	// 		array_push( $header, "Authorization: Bearer $token" );
	// 	}

	// 	curl_setopt_array( $curl, [
	// 		CURLOPT_URL            => $url,
	// 		CURLOPT_RETURNTRANSFER => TRUE,
	// 		CURLOPT_ENCODING       => "",
	// 		CURLOPT_MAXREDIRS      => 10,
	// 		CURLOPT_TIMEOUT        => 60,
	// 		CURLOPT_FOLLOWLOCATION => TRUE,
	// 		CURLOPT_SSL_VERIFYPEER => FALSE,
	// 		CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
	// 		CURLOPT_CUSTOMREQUEST  => "POST",
	// 		CURLOPT_POSTFIELDS     => ( $params ) ? json_encode( $params ) : null,
	// 		CURLOPT_HTTPHEADER     => $header,
    //     ] );

    //     $resp = curl_exec( $curl );

    //     curl_close( $curl );

	// 	return json_decode( $resp, TRUE );
    // }

	/**
	 * Valida qual a bandeira dado o número do cartão
	 *
	 * @param array $data
	 * @return array
	 */
	public function get_brand( $data )
	{
		$token    = $this->validate_token( $data[ "user_id" ] );

		$params   = [
			"payload" => [
				"gateway"    => $this->gateway->service,
				"cardNumber" => $data[ "cardNumber" ],
			]
		];

		$response = $this->node->brand( $token, $params );

		$this->log( json_encode( $response ), true );

		// 4957 6447 0996 7095

		$params["payload"]["cardNumber"] = "****-****-****-" . substr( $data["cardNumber"], -4 );
		$this->log( "INFO - get_brand : " . json_encode( [ $params, $response ] ) );

		return $response;
	}

	/**
	 *
	 * @return array
	 */
	public function update_subscription( $data )
	{
		if (
			(  $this->node->env() && $data->get_header( "x-api-key" ) !== "54723162306C6F6A7052646D4C6170693030" )
			||
			( !$this->node->env() && $data->get_header( "x-api-key" ) !== "0303960716C4D6462507A6F6C60326132745" )
		)
		{
			return new WP_REST_Response( [ "error" => "Unauthorized" ], 401 );
		}

		$order_id = $data[ "order_id" ];

		$order    = wc_get_order( $order_id );

		if ( $data[ "approved" ] )
		{
			$data = [
				"id"     => null,
				"status" => 2,
			];

			$this->gateway->finalize_order( $order, $data );
		}
		else
		{
			$order->update_status( "pending" );
		}

		return;
	}

	// /**
	//  * Tokeniza um cartão
	//  *
	//  * @param array $data
	//  * @return array
	//  */
	// private function create_card_token( $data, $token )
	// {
	// 	$params = [
	// 		"payload" => [
	// 			"gateway"  => $this->gateway->service,
	// 			"cardInfo" => [
	// 				"holder"         => $data[ "holder"         ],
	// 				"cardNumber"     => $data[ "cardNumber"     ],
	// 				"expirationDate" => $data[ "expirationDate" ],
	// 				"securityCode"   => $data[ "securityCode"   ],
	// 				"brand"          => $data[ "brand"          ]
	// 			],
	// 		]
	// 	];

	// 	return $this->exec( "payments/createToken", $token, $params );
	// }

	// /**
	//  * Verifica se cartão já foi tokenizado
	//  *
	//  * @param string $number
	//  * @param string $brand
	//  * @param string $token
	//  * @return array
	//  */
	// private function check_card_token( $number, $brand, $token )
	// {
	// 	$params = [
	// 		"payload" => [
	// 			"gateway"  => $this->gateway->service,
	// 			"cardInfo" => [
	// 				"number" => $number,
	// 				"brand"  => $brand,
	// 			]
	// 		]
	// 	];

	// 	return $this->exec( "payments/checkToken", $token, $params );
	// }

	// /**
	//  * Exclui um cartão tokenizado
	//  *
	//  * @param string $tokenIndex
	//  * @param string $token
	//  * @return array
	//  */
	// private function delete_card_token( $tokenIndex, $token )
	// {
	// 	$params = [
	// 		"payload" => [
	// 			"gateway"    => $this->gateway->service,
	// 			"tokenIndex" => $tokenIndex
	// 		]
	// 	];

	// 	return $this->exec( "payments/deleteToken", $token, $params );
	// }

	// /**
	//  * Valida dados do cartão.
	//  * Retorna token do cartão, se não tiver, tokeniza.
	//  *
	//  * @param array $posted
	//  * @return array
	//  */
	// private function status_card( $posted )
	// {
	// 	$number   = substr( $posted[ "card_number" ], -4 );
	// 	$response = $this->check_card_token( $number, $posted[ "brand" ], $posted[ "token" ] );

	// 	// CARTAO NAO TOKENIZADO
	// 	if ( ! $response["success"] )
	// 	{
	// 		$data     = [
	// 			"holder"         => $posted[ "holder"      ],
	// 			"cardNumber"     => $posted[ "card_number" ],
	// 			"expirationDate" => $posted[ "month" ] . "/" . $posted[ "year" ],
	// 			"securityCode"   => $posted[ "cvc"   ],
	// 			"brand"          => $posted[ "brand" ],
	// 		];

	// 		// CRIANDO TOKEN DO CARTAO
	// 		$response = $this->create_card_token( $data, $posted[ "token" ] );

	// 		// FALHA NA CRIACAO DO TOKEN DO CARTAO
	// 		if ( ! $response[ "success" ] )
	// 		{
	// 			$error = "Falha ao consultar token.";
	// 			$this->log( "ERROR - create_card_token : " . json_encode( [ $error, $response ] ), true );

	// 			return [
	// 				"success" => false,
	// 				"error"   => "$error (cod EP2)"
	// 			];
	// 		}

	// 		$created    = true;
	// 		$tokenIndex = $response[ "tokenInfo" ][ "index" ];
	// 	}
	// 	// CARTAO JA TOKENIZADO
	// 	else
	// 	{
	// 		$created    = false;
	// 		$tokenIndex = $response[ "tokenInfo" ][ "index" ];
	// 	}

	// 	return [
	// 		"success"    => true,
	// 		"cretead"    => $created,
	// 		"tokenIndex" => $tokenIndex,
	// 	];
	// }

	// /**
	//  * Processa pagamento
	//  *
	//  * @param array $data
	//  * @return array
	//  */
	// private function do_payment( $data, $token )
	// {
	// 	// numero de teste
	// 	// 4929 7008 9247 1744
	// 	// 4024 0071 2681 6130
	// 	// 4024 0071 1511 4562

	// 	$params = [
	// 		"payload" => [
	// 			"gateway" => $this->gateway->service,
	// 			"order"   => [
	// 				"orderId"     => $data[ "orderId"     ],
	// 				"totalAmount" => $data[ "totalAmount" ], // em centavos
	// 				"description" => $data[ "description" ],
	// 				"tokenIndex"  => $data[ "tokenIndex"  ],
	// 				"name"        => $data[ "name"        ],
	// 				"document"    => $data[ "document"    ],
	// 				"cellPhone"   => $data[ "phone"       ],
	// 			],
	// 		]
	// 	];

	// 	return $this->exec( "payments/doPayment", $token, $params );
	// }

	// /**
	//  * Process the subscription request from checkout
	//  *
	//  * @param  WC_Order $order  Order data.
	//  * @param  array    $posted Posted data.
	//  *
	//  * @return array
	//  */
	// public function do_subscription_request( $order, $posted )
	// {
	// 	$is_payment_change             = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
	// 	$order_contains_failed_renewal = false;

	// 	// Payment method changes act on the subscription not the original order
	// 	if ( $is_payment_change )
	// 	{
	// 		$subscription = wcs_get_subscription( wcs_get_objects_property( $order, "id" ) );
	// 		$status_card  = $this->status_card( $posted );

	// 		if ( ! $status_card[ "success" ] )
	// 		{
	// 			$this->log( "ERROR - update_payment_method : SUBS " . $subscription->get_id(), true );

	// 			return [
	// 				"success" => false,
	// 				"error"   => "Falha ao alterar método de pagamento. Tente novamente. (cod EP4)"
	// 			];
	// 		}

	// 		$paymentToken = get_post_meta( $subscription->get_id(), "_triibo_assinatura_payment_token", true );

	// 		update_post_meta( $subscription->get_id(), "_triibo_assinatura_payment_token", $status_card[ "tokenIndex" ] );

	// 		$this->log( "INFO - update_payment_method : SUBS " . $subscription->get_id() . " | old ID " . $paymentToken . " | new ID " . $status_card[ "tokenIndex" ] );

	// 		return [
	// 			"success"        => true,
	// 			"payment_change" => true,
	// 		];
	// 	}
	// 	else
	// 	{
	// 		// Otherwise the order is the $order
	// 		if ( $cart_item = wcs_cart_contains_failed_renewal_order_payment() ||
	// 			 false !== WC_Subscriptions_Renewal_Order::get_failed_order_replaced_by( wcs_get_objects_property( $order, "id" ) ) )
	// 		{
	// 			$subscriptions                 = wcs_get_subscriptions_for_renewal_order( $order );
	// 			$order_contains_failed_renewal = true;
	// 		}
	// 		else
	// 		{
	// 			$subscriptions                 = wcs_get_subscriptions_for_order( $order, [ "order_type" => [ "switch", "parent", "renewal" ] ] );
	// 		}

	// 		// Only one subscription allowed per order with PayPal
	// 		$subscription = array_pop( $subscriptions );
	// 	}

	// 	if ( $order_contains_failed_renewal || ! empty( $subscription ) )
	// 	{
	// 		$status_card = $this->status_card( $posted );

	// 		if ( ! $status_card[ "success" ] ) return $status_card;

	// 		// PROCESSAMENTO DO PAGAMENTO
	// 		if ( $order->get_total() > 0 )
	// 		{
	// 			$item  = current( $order->get_items() );
	// 			$desc  = $item->get_name();

	// 			$total = intval( strval( $order->get_total() * 100 ) ); // em centavos
	// 			$name  = $order->get_user()->get( "display_name" ) ?: $posted[ "holder" ];
	// 			$doc   = $order->get_meta( "_billing_cpf", true )  ?: $posted[ "cpf" ];
	// 			$phone = "+" . preg_replace( "/\D/", "", $posted[ "phone" ] );

	// 			$data  = [
	// 				"orderId"     => $order->ID,
	// 				"totalAmount" => $total, // em centavos
	// 				"description" => $desc,
	// 				"tokenIndex"  => $status_card[ "tokenIndex" ],
	// 				"name"        => $name,
	// 				"document"    => $doc,
	// 				"phone"       => $phone,
	// 			];

	// 			// EXECUTANDO PAGAMENTO
	// 			$response = $this->do_payment( $data, $posted[ "token" ] );
	// 			$response[ "paymentInfo" ][ "token" ] = $status_card[ "tokenIndex" ];
	// 		}
	// 		else
	// 		{
	// 			// TESTE GRATUITO
	// 			$response = [
	// 				"success"     => true,
	// 				"paymentInfo" => [
	// 					"id"      => null,
	// 					"status"  => 2,
	// 					"message" => "Free Trial"
	// 				]
	// 			];
	// 		}

	// 		// FALHA NO PAGAMENTO
	// 		if ( ! $response[ "success" ] )
	// 		{
	// 			$error = "Ocorreu um erro ao processar seu pagamento, tente novamente.";
	// 			$this->log( "ERROR - do_payment : " . json_encode( [ $error, $response, $data ] ), true );

	// 			// SE TOKEN DO CARTAO FOI CRIADO NESSE MOMENTO, EXCLUI
	// 			if ( $status_card[ "created" ] )
	// 			{
	// 				$response = $this->delete_card_token( $status_card[ "tokenIndex" ], $posted[ "token" ] );
	// 				$this->log( "WARNING - delete_card_token : " . json_encode( [ $response ] ) );
	// 			}

	// 			return [
	// 				"success" => false,
	// 				"error"   => "$error (cod EP3)"
	// 			];
	// 		}
	// 		else if ( in_array( $response[ "paymentInfo" ][ "status" ], [ 3, 10, 11, 13 ] ) )
	// 		{
	// 			// failure
	// 			// 3  : 'Denied',           // Pagamento negado por Autorizador.
	// 			// 10 : 'Voided',           // Pagamento cancelado.
	// 			// 11 : 'Refunded',         // Pagamento cancelado após 23h59 do dia de autorização.
	// 			// 13 : 'Aborted',          // Pagamento cancelado por falha no processamento ou por ação do Antifraude.

	// 			$error = "Cobrança recusada pela operadora do cartão.";
	// 			$this->log( "ERROR - do_payment : " . json_encode( [ $error, $response, $data ] ), true );

	// 			// SE TOKEN DO CARTAO FOI CRIADO NESSE MOMENTO, EXCLUI
	// 			if ( $status_card[ "created" ] )
	// 			{
	// 				$response = $this->delete_card_token( $status_card[ "tokenIndex" ], $posted[ "token" ] );
	// 				$this->log( "WARNING - delete_card_token : " . json_encode( [ $response ] ) );
	// 			}

	// 			return [
	// 				"success" => false,
	// 				"error"   => "$error (cod EP6)"
	// 			];
	// 		}

	// 		$subscription->set_requires_manual_renewal( false );
	// 		$subscription->save();

	// 		return [
	// 			"success" => true,
	// 			"message" => "Pagamento processado com sucesso.",
	// 			"data"    => $response[ "paymentInfo" ]
	// 		];
	// 	}

	// 	$this->log( "WARNING - Could not process subscription on do_subscription_request()", true );

	// 	// Return error message.
	// 	return [
	// 		"success" => false,
	// 		"error"   => "<strong>Triibo Recorrente</strong>: " . __( "Ocorreu um erro ao processar seu pagamento, tente novamente. Ou entre em contato conosco para obter assistência. (cod EP99)", "triibo_assinaturas" ),
	// 	];
	// }

	// /**
	//  * Process payment on a subscription renewal
	//  *
	//  * @param WC_Order $order Order data.
	//  * @param float    amount The amount to charge.
	//  *
	//  * @return string
	//  */
	// public function do_subscription_renewal_payment( $order, $amount )
	// {
	// 	$user_id      = $order->get_user_id();
	// 	$token        = $this->validate_token( $user_id );

	// 	$item         = current( $order->get_items() );
	// 	$desc         = $item->get_name();

	// 	$total        = intval( strval( $amount * 100 ) ); // em centavos
	// 	$name         = $order->get_user()->get( "display_name" );
	// 	$doc          = $order->get_meta( "_billing_cpf", true );
	// 	$phone        = get_user_meta( $user_id, "triiboId_phone", true );

	// 	if ( wcs_order_contains_subscription( $order->ID ) )
	// 		$subscriptions = wcs_get_subscriptions_for_order( $order->ID );
	// 	elseif ( wcs_order_contains_renewal( $order->ID ) )
	// 		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order->ID );

	// 	$subscription = array_pop( $subscriptions );

	// 	$paymentToken = get_post_meta( $subscription->get_id(), "_triibo_assinatura_payment_token", true );

	// 	$data         = [
	// 		"orderId"     => $order->ID,
	// 		"totalAmount" => $total, // em centavos
	// 		"description" => $desc,
	// 		"tokenIndex"  => $paymentToken,
	// 		"name"        => $name,
	// 		"document"    => $doc,
	// 		"phone"       => $phone,
	// 	];

	// 	// EXECUTANDO PAGAMENTO
	// 	$response = $this->do_payment( $data, $token );
	// 	$response[ "paymentInfo" ][ "token" ] = $paymentToken;

	// 	// FALHA NO PAGAMENTO
	// 	if ( ! $response[ "success" ] )
	// 	{
	// 		$error = "Ocorreu um erro ao processar seu pagamento, tente novamente.";
	// 		$this->log( "ERROR - do_payment renewal : " . json_encode( [ $error, $response, $data ] ), true );

	// 		return [
	// 			"success"    => false,
	// 			"error"      => "$error (cod EP5)",
	// 			"full_error" => $response,
	// 		];
	// 	}
	// 	else if ( in_array( $response[ "paymentInfo" ][ "status" ], [ 3, 10, 11, 13 ] ) )
	// 	{
	// 		// failure
	// 		// 3  : 'Denied',           // Pagamento negado por Autorizador.
	// 		// 10 : 'Voided',           // Pagamento cancelado.
	// 		// 11 : 'Refunded',         // Pagamento cancelado após 23h59 do dia de autorização.
	// 		// 13 : 'Aborted',          // Pagamento cancelado por falha no processamento ou por ação do Antifraude.

	// 		$error = "Cobrança recusada pela operadora do cartão.";
	// 		$this->log( "ERROR - do_payment renewal : " . json_encode( [ $error, $response, $data ] ), true );

	// 		return [
	// 			"success"    => false,
	// 			"error"      => "$error (cod EP7)",
	// 			"full_error" => $response,
	// 		];
	// 	}

	// 	$subscription->set_requires_manual_renewal( false );
	// 	$subscription->save();

	// 	return [
	// 		"success" => true,
	// 		"message" => "Pagamento processado com sucesso.",
	// 		"data"    => $response[ "paymentInfo" ]
	// 	];
	// }

	private function log( $message, $is_error = false )
	{
		if ( $this->gateway->debug == "yes" )
		{
			$this->gateway->log->add( $this->gateway->id, $message );
		}
		else if ( $is_error )
		{
			$this->gateway->log->add( $this->gateway->id, $message );
		}
	}
}
