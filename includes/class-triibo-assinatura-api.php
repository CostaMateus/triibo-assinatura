<?php
/**
 * Triibo Assinaturas Api class
 *
 * @author      Mateus Costa <mateus@costamateus.com.br>
 * @package 	Triibo_Assinaturas/Classes
 * @version 	3.4.0
 */
defined( "ABSPATH" ) || exit;

class Triibo_Assinaturas_Api extends WP_REST_Controller
{
	/**
	 * Endpoint namespace.
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	protected $namespace = WC_Triibo_Assinaturas::DOMAIN . "/v1";

	/**
	 * Stores the request.
	 *
	 * @since 	1.0.0
	 * @var 	array
	 */
	protected $request = [];

	/**
	 * Gateway class.
	 *
	 * @since 	1.0.0
	 * @var 	Triibo_Assinaturas_Gateway
	 */
	protected $gateway;

	/**
	 * API class.
	 *
	 * @since 	1.0.0
	 * @var 	Triibo_Api_Node
	 */
	protected $node;

	/**
	 * Construct
	 *
	 * @since 	1.0.0
	 */
	public function __construct( $gateway = null, $node = null )
	{
		$this->node    = $node;
		$this->gateway = $gateway;
	}

	/**
	 * Registra rotas api rest
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function register_routes()
	{
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
	 * @since 	3.0.2 	Return the token
	 * @since 	1.0.0
     * @param   string  $uid
     * @param   integer $user_id
	 * @return 	string
	 */
	public function generate_token( $uid, $user_id )
	{
		if ( $this->node->status() )
		{
			$resp = $this->node->auth( "uid: {$uid}" );

			if ( $resp[ "success" ] )
			{
				$token    = $resp[ "data"  ][ "token" ];
				$validity = ( new DateTime() )->format( "Y-m-d H:i:s" );

				update_user_meta( $user_id, "_triibo_auth_token",    $token    );
				update_user_meta( $user_id, "_triibo_auth_validity", $validity );

				return $token;
			}
			else
			{
				$this->log( "info", "generate_token : " . print_r( [
					"code"    => $resp[ "code"    ],
					"message" => $resp[ "message" ],
					"error"   => $resp[ "error"   ]
				], true ) );

				return $this->generate_token( $uid, $user_id );
			}
		}
	}

	/**
	 * Verifica validade do token, se vencido, gera um novo
	 *
	 * @since 	3.0.2 	Added uid validation with phone search
	 * @since 	1.0.0
     * @param   integer $user_id
	 * @return 	string
	 */
	public function validate_token( $user_id )
	{
		$phone    = get_user_meta( $user_id, "_triibo_phone",         true );
		$phone    = $phone ?: get_user_meta( $user_id, "triiboId_phone", true );

		if ( !$phone ) return null;

		$uid      = get_user_meta( $user_id, "_triibo_id",            true );
		$token    = get_user_meta( $user_id, "_triibo_auth_token",    true );
		$validity = get_user_meta( $user_id, "_triibo_auth_validity", true );

		if ( !$uid )
		{
			$success = false;
			$resp    = $this->node->auth( "" );

			if ( $resp[ "success" ] )
			{
				$_token = $resp[ "data"  ][ "token" ];
				$resp   = $this->node->find_uid( $_token, $phone );
				$this->log( "info", "find_uid : search user by phone '{$phone}'" );

				if ( $resp[ "success" ] )
				{
					$uid = $resp[ "data" ][ "usersUid" ][ $phone ];
					$this->log( "info", "find_uid : user found '{$uid}'" );

					if ( $uid )
					{
						$success = true;
						$token   = null;
						update_user_meta( $user_id, "_triibo_id", $uid );
					}
				}
			}

			if ( !$success ) return null;
		}

		if ( !$token )
		{
			$this->log( "info", "validate_token : user without token {$user_id}" );
			return $this->generate_token( $uid, $user_id );
		}
		else
		{
			$dtval = DateTime::createFromFormat( "Y-m-d H:i:s", $validity );
			$now   = new DateTime();
			$diff  = $now->diff( $dtval );

			if ( $diff->d > 0 || $diff->h > 10 )
			{
				$this->log( "info", "validate_token : experied token {$user_id}" );
				return $this->generate_token( $uid, $user_id );
			}

			return $token;
		}
	}

	/**
	 * Verifica se cartão já foi tokenizado
	 *
	 * @since 	1.0.0
	 * @param 	string $number
	 * @param 	string $brand
	 * @param 	string $token
	 * @return 	array
	 */
	private function check_card_token( $number, $brand, $token )
	{
		$params   = [
			"payload" => [
				"gateway"  => $this->gateway->get_service(),
				"cardInfo" => [
					"number" => $number,
					"brand"  => $brand,
				]
			]
		];

		$response = $this->node->check_token( $token, $params );

		$this->log( "info", "check_card_token : " . print_r( [ $params, $response ], true ) );

		return $response;
	}

	/**
	 * Tokeniza um cartão
	 *
	 * @since 	1.0.0
	 * @param 	array $data
	 * @return 	array
	 */
	private function create_card_token( $data, $token )
	{
		$params = [
			"payload" => [
				"gateway"  => $this->gateway->get_service(),
				"cardInfo" => [
					"holder"         => $data[ "holder"         ],
					"cardNumber"     => $data[ "cardNumber"     ],
					"expirationDate" => $data[ "expirationDate" ],
					"securityCode"   => $data[ "securityCode"   ],
					"brand"          => $data[ "brand"          ]
				],
			]
		];

		$response = $this->node->create_token( $token, $params );

		$this->log( "info", "create_card_token : " . print_r( $response, true ) );

		return $response;
	}

	/**
	 * Exclui um cartão tokenizado
	 *
	 * @since 	1.0.0
	 * @param 	string $tokenIndex
	 * @param 	string $token
	 * @return 	array
	 */
	private function delete_card_token( $tokenIndex, $token )
	{
		$params = [
			"payload" => [
				"gateway"    => $this->gateway->get_service(),
				"tokenIndex" => $tokenIndex
			]
		];

		$response = $this->node->delete_token( $token, $params );

		$this->log( "info", "delete_card_token : " . print_r( [ $params, $response ], true ) );

		return $response;
	}

	/**
	 * Valida dados do cartão.
	 * Retorna token do cartão, se não tiver, tokeniza.
	 *
	 * @since 	1.0.0
	 * @param 	array $posted
	 * @return 	array
	 */
	private function status_card( $posted )
	{
		$number   = substr( $posted[ "card_number" ], -4 );
		$response = $this->check_card_token( $number, $posted[ "brand" ], $posted[ "token" ] );

		// CARTAO NAO TOKENIZADO
		if ( !$response[ "success" ] )
		{
			$data     = [
				"holder"         => $posted[ "holder"      ],
				"cardNumber"     => $posted[ "card_number" ],
				"expirationDate" => $posted[ "month"       ] . "/" . $posted[ "year" ],
				"securityCode"   => $posted[ "cvv"         ],
				"brand"          => $posted[ "brand"       ],
			];

			// CRIANDO TOKEN DO CARTAO
			$response = $this->create_card_token( $data, $posted[ "token" ] );

			// FALHA NA CRIACAO DO TOKEN DO CARTAO
			if ( !$response[ "success" ] )
			{
				$error = "Falha ao consultar token.";
				$this->log( "error", "(cod EP2) create_card_token : " . print_r( [ $error, $response ], true ), true );

				return [
					"success" => false,
					"error"   => "$error (cod EP2)"
				];
			}

			$created    = true;
			$tokenIndex = $response[ "data" ][ "tokenInfo" ][ "index" ];
		}
		// CARTAO JA TOKENIZADO
		else
		{
			$created    = false;
			$tokenIndex = $response[ "data" ][ "tokenInfo" ][ "index" ];
		}

		return [
			"success"    => true,
			"cretead"    => $created,
			"tokenIndex" => $tokenIndex,
		];
	}

	/**
	 * Processa pagamento
	 *
	 * @since 	1.0.0
	 * @param 	array $data
	 * @return 	array
	 */
	private function do_payment( $data, $token, $test )
	{
		/**
		 * Added treatment of description string
		 * @version 3.0.1
		 */
		$descrip  = $this->treatDescription( $data[ "description" ] );

		$params   = [
			"payload" => [
				"gateway" => $this->gateway->get_service(),
				"order"   => [
					"orderId"     => $data[ "orderId"     ],
					"totalAmount" => $data[ "totalAmount" ], // em centavos
					"description" => $descrip,
					"tokenIndex"  => $data[ "tokenIndex"  ],
					"name"        => $data[ "name"        ],
					"cellPhone"   => $data[ "phone"       ],
				],
			]
		];

		if ( $data[ "document" ] )
			$params[ "payload" ][ "order" ][ "document" ] = $data[ "document" ];

		$response = $this->node->payment( $token, $params );

		$this->log( "info", "do_payment {$test}: " . print_r( [ $params, $response ], true ) );

		return $response;
	}

	/**
	 *
	 *
	 * @since 	1.0.0
	 * @return 	array
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

	/**
	 * Process the subscription request from checkout
	 *
	 * @since 	1.0.0
	 * @param  	WC_Order $order  Order data.
	 * @param  	array    $posted Posted data.
	 * @return 	array
	 */
	public function do_subscription_request( $order, $posted )
	{
		$is_payment_change             = WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment;
		$order_contains_failed_renewal = false;

		// Payment method changes act on the subscription not the original order
		if ( $is_payment_change )
		{
			$subscription = wcs_get_subscription( $order->get_id() );
			$status_card  = $this->status_card( $posted );

			if ( !$status_card[ "success" ] )
			{
				$this->log( "critical", "(cod EP4) update_payment_method : SUBS " . $subscription->get_id(), true );

				return [
					"success" => false,
					"error"   => "Falha ao alterar método de pagamento. Tente novamente. (cod EP4)"
				];
			}

			$paymentToken = $subscription->get_meta( "_triibo_assinatura_payment_token", true );

			$subscription->update_meta_data( "_triibo_assinatura_payment_token", $status_card[ "tokenIndex" ] );

			$this->log( "info", "update_payment_method : SUBS " . $subscription->get_id() . " | old ID " . $paymentToken . " | new ID " . $status_card[ "tokenIndex" ] );

			return [
				"success"        => true,
				"payment_change" => true,
			];
		}
		else
		{
			// Otherwise the order is the $order
			if ( $cart_item = wcs_cart_contains_failed_renewal_order_payment() ||
				 false !== WC_Subscriptions_Renewal_Order::get_failed_order_replaced_by( $order->get_id() ) )
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

		if ( $order_contains_failed_renewal || !empty( $subscription ) )
		{
			$status_card = $this->status_card( $posted );

			if ( !$status_card[ "success" ] ) return $status_card;

			// PROCESSAMENTO DO PAGAMENTO
			if ( $order->get_total() > 0 )
			{
				$item     = current( $order->get_items() );
				$desc     = $item->get_name();

				$total    = intval( strval( $order->get_total() * 100 ) ); // em centavos
				$name     = $order->get_user()->get( "display_name" ) ?: $posted[ "holder" ];
				$doc      = $order->get_meta( "_billing_cpf", true  ) ?: $posted[ "cpf"    ];
				$phone    = "+" . preg_replace( "/\D/", "", $posted[ "phone" ] );

				$data     = [
					"orderId"     => $order->get_id(),
					"totalAmount" => $total, // em centavos
					"description" => $desc,
					"tokenIndex"  => $status_card[ "tokenIndex" ],
					"name"        => $name,
					"document"    => $doc,
					"phone"       => $phone,
				];

				// EXECUTANDO PAGAMENTO
				$response = $this->do_payment( $data, $posted[ "token" ], "new" );
				$data     = $status_card[ "tokenIndex" ];

				$response[ "data" ][ "paymentInfo" ][ "token" ] = $data;
			}
			else
			{
				// TESTE GRATUITO
				$response = [
					"success" => true,
					"data"    => [
						"paymentInfo" => [
							"id"      => null,
							"status"  => 2,
							"message" => "Free Trial"
						]
					]
				];
			}

			$pay_id  = $response[ "data" ][ "paymentInfo" ][ "id"      ];
			$message = $response[ "data" ][ "paymentInfo" ][ "message" ];
			$note    = "Payment ID: {$pay_id}\n";

			/**
			 * Add the cielo TID code to the order note
			 *
			 * @since 	3.2.1
			 */
			if ( $this->gateway->get_service() == "cielo" )
			{
				if ( isset( $response[ "data" ][ "paymentInfo" ][ "tid" ] ) )
				{
					$pay_tid  = $response[ "data" ][ "paymentInfo" ][ "tid" ];
					$note    .= "TID: {$pay_tid}\n";
				}
			}

			$note .= "Gateway: {$this->gateway->get_service()}\n";
			$note .= "Message: {$message}\n";

			// FALHA NO PAGAMENTO
			if ( !$response[ "success" ] )
			{
				$error = "Ocorreu um erro ao processar seu pagamento, tente novamente.";
				$this->log( "critical", "(cod EP3) do_payment : " . print_r( [ $error, $response, $data ], true ), true );

				// SE TOKEN DO CARTAO FOI CRIADO NESSE MOMENTO, EXCLUI
				if ( $status_card[ "created" ] )
				{
					$response = $this->delete_card_token( $status_card[ "tokenIndex" ], $posted[ "token" ] );
					$this->log( "error", "(cod EP3) delete_card_token : " . print_r( [ $response ], true ) );
				}

				$note .= "Code: cod EP3";
				$order->add_order_note( $note );
				$order->save();

				return [
					"success" => false,
					"error"   => "$error (cod EP3)"
				];
			}
			else if ( in_array( $response[ "data" ][ "paymentInfo" ][ "status" ], [ 3, 10, 11, 13 ] ) )
			{
				// failure
				// 3  : 'Denied',           // Pagamento negado por Autorizador.
				// 10 : 'Voided',           // Pagamento cancelado.
				// 11 : 'Refunded',         // Pagamento cancelado após 23h59 do dia de autorização.
				// 13 : 'Aborted',          // Pagamento cancelado por falha no processamento ou por ação do Antifraude.

				$error = "Cobrança recusada pela operadora do cartão.";
				$this->log( "critical", "(cod EP6) do_payment : " . print_r( [ $error, $response, $data ], true ), true );

				// SE TOKEN DO CARTAO FOI CRIADO NESSE MOMENTO, EXCLUI
				if ( $status_card[ "created" ] )
				{
					$response = $this->delete_card_token( $status_card[ "tokenIndex" ], $posted[ "token" ] );
					$this->log( "error", "(cod EP6) delete_card_token : " . print_r( [ $response ], true ) );
				}

				$note .= "Code: cod EP6";
				$order->add_order_note( $note );
				$order->save();

				return [
					"success" => false,
					"error"   => "$error (cod EP6)"
				];
			}

			$subscription->set_requires_manual_renewal( false );
			$subscription->save();

			return [
				"success" => true,
				"message" => "Pagamento processado com sucesso.",
				"data"    => $response[ "data" ][ "paymentInfo" ]
			];
		}

		$this->log( "error", "(cod EP99) - Could not process subscription on do_subscription_request()", true );

		$note  = "Payment ID: \n";
		$note .= "Gateway: {$this->gateway->get_service()}\n";
		$note .= "Message: Could not process subscription on do_subscription_request()\n";
		$note .= "Code: cod EP99";
		$order->add_order_note( $note );
		$order->save();

		// Return error message.
		return [
			"success" => false,
			"error"   => "<strong>Triibo Recorrente</strong>: " . __( "Ocorreu um erro ao processar seu pagamento, tente novamente. Ou entre em contato conosco para obter assistência. (cod EP99)", "triibo_assinaturas" ),
		];
	}

	/**
	 * Process payment on a subscription renewal
	 *
	 * @since 	1.0.0
	 * @param 	WC_Order 	$order 		Order data.
	 * @param 	float    	$amount 	The amount to charge.
	 * @return 	string
	 */
	public function do_subscription_renewal_payment( $order, $amount )
	{
		$user_id      = $order->get_user_id();
		$token        = $this->validate_token( $user_id );

		if ( !$token )
		{
			$error = "Ocorreu um erro ao processar seu pagamento, tente novamente. (cod EP8)";
			$this->log( "critical", "(cod EP8) validate_token in do_payment : invalid token", true );

			$order->add_order_note( $note );
			$order->save();

			return [
				"success"    => false,
				"error"      => "$error (cod EP8)",
				"full_error" => null,
			];
		}

		$item         = current( $order->get_items() );
		$desc         = $item->get_name();

		$total        = intval( strval( $amount * 100 ) ); // em centavos
		$name         = $order->get_user()->get( "display_name" );
		$doc          = $order->get_meta( "_billing_cpf", true );
		$phone        = get_user_meta( $user_id, "_triibo_phone", true );
		$phone        = $phone ?: get_user_meta( $user_id, "triiboId_phone", true );

		if ( wcs_order_contains_subscription( $order->get_id() ) )
			$subscriptions = wcs_get_subscriptions_for_order( $order->get_id() );
		elseif ( wcs_order_contains_renewal( $order->get_id() ) )
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order->get_id() );

		$subscription = array_pop( $subscriptions );

		$paymentToken = $subscription->get_meta( "_triibo_assinatura_payment_token", true );

		$data         = [
			"orderId"     => $order->get_id(),
			"totalAmount" => $total, // em centavos
			"description" => $desc,
			"tokenIndex"  => $paymentToken,
			"name"        => $name,
			"document"    => $doc,
			"phone"       => $phone,
		];

		// EXECUTANDO PAGAMENTO
		$response = $this->do_payment( $data, $token, "renewal" );
		$response[ "data" ][ "paymentInfo" ][ "token" ] = $paymentToken;

		$note  = "Payment ID: {$response[ "data" ][ "paymentInfo" ][ "id" ]}\n";
		$note .= "Gateway: {$this->gateway->get_service()}\n";
		$note .= "Message: {$response[ "data" ][ "paymentInfo" ][ "message" ]}\n";

		// FALHA NO PAGAMENTO
		if ( !$response[ "success" ] )
		{
			if ( $response[ "code" ] == 401 && $response[ "data" ][ "error" ] == "Invalid token." )
			{
				$error = "Token inválido, retentativa.";
				$this->log( "critical", "(cod EP5) do_payment renewal : " . print_r( [ $error, $response, $data ], true ), true );

				$uid   = get_user_meta( $user_id, "_triibo_id", true );

				$this->generate_token( $uid, $user_id );

				return $this->do_subscription_renewal_payment( $order, $amount );
			}

			$error = "Ocorreu um erro ao processar seu pagamento, tente novamente.";
			$this->log( "critical", "(cod EP5) do_payment renewal : " . print_r( [ $error, $response, $data ], true ), true );

			$note .= "Code: cod EP5 2";
			$order->add_order_note( $note );
			$order->save();

			return [
				"success"    => false,
				"error"      => "$error (cod EP5)",
				"full_error" => $response,
			];
		}
		else if ( in_array( $response[ "data" ][ "paymentInfo" ][ "status" ], [ 3, 10, 11, 13 ] ) )
		{
			// failure
			// 3  : 'Denied',           // Pagamento negado por Autorizador.
			// 10 : 'Voided',           // Pagamento cancelado.
			// 11 : 'Refunded',         // Pagamento cancelado após 23h59 do dia de autorização.
			// 13 : 'Aborted',          // Pagamento cancelado por falha no processamento ou por ação do Antifraude.

			$error = "Cobrança recusada pela operadora do cartão.";
			$this->log( "critical", "(cod EP7) do_payment renewal : " . print_r( [ $error, $response, $data ], true ), true );

			$note .= "Code: cod EP7";
			$order->add_order_note( $note );
			$order->save();

			return [
				"success"    => false,
				"error"      => "$error (cod EP7)",
				"full_error" => $response,
			];
		}

		$subscription->set_requires_manual_renewal( false );
		$subscription->save();

		return [
			"success" => true,
			"message" => "Pagamento processado com sucesso.",
			"data"    => $response[ "data" ][ "paymentInfo" ]
		];
	}

	/**
	 * Treat description
	 *
	 * @since 	3.0.1
	 * @param 	string $text
	 * @return 	string
	 */
	private function treatDescription( $text )
	{
		$divider = "-";

        // replace non letter or digits by divider
        $text = preg_replace( "~[^\pL\d]+~u", $divider, $text );

        // transliterate
        $text = iconv( "utf-8", "us-ascii//TRANSLIT", $text );

        // remove unwanted characters
        $text = preg_replace( "~[^-\w]+~", "", $text );

        // trim
        $text = trim( $text, $divider );

        // remove duplicate divider
        $text = preg_replace( "~-+~", $divider, $text );

		$text = str_replace( $divider, " ", $text );

		return $text;
	}

	/**
	 * Register logs.
	 *
	 * @since 	3.4.0 	Changing to the new woocommerce standard.
	 * 					Added param $level.
	 * @since 	1.0.0
	 * @param 	string 	$level
	 * @param 	string 	$message
	 * @param 	bool 	$is_error
	 * @return 	void
	 */
	private function log( string $level, string $message, bool $is_error = false )
	{
		$id      = $this->gateway->get_id();
		$debug   = $this->gateway->get_debug();

		$logger  = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();

		$context = [ "source" => $id ];

		if ( $is_error || $debug === "yes" )
			$logger->$level( $message, $context );
	}
}
