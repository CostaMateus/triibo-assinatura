<?php
/**
 * Triibo Assinaturas Gateway class
 *
 * @author      Mateus Costa <mateus@costamateus.com.br>
 * @package 	Triibo_Assinaturas/Classes
 * @version 	1.0.0
 */
defined( "ABSPATH" ) || exit;

class Triibo_Assinaturas_Gateway extends WC_Payment_Gateway_CC
{
	/**
	 * Instance of this class.
	 *
	 * @since 	1.0.0
	 * @var 	object
	 */
	protected static $instance = null;

	/**
	 * Payment gateway (ex Cielo).
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	protected $service         = null;

	/**
	 * Cpf field
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	private $cc_hide_cpf_field = null;

	/**
	 * Instance of Api Node
	 *
	 * @since 	1.0.0
	 * @var 	Triibo_Api_Node
	 */
	protected $node            = null;

	/**
	 * Instance of Assinaturas_API
	 *
	 * @since 	1.0.0
	 * @var 	Triibo_Assinaturas_Api
	 */
	protected $api             = null;

	/**
	 * Debug
	 *
	 * @since 	1.0.0
	 * @var 	string
	 */
	protected $debug           = null;

	/**
	 * Logger
	 *
	 * @since 	1.0.0
	 * @var 	wc_get_logger|WC_Logger|null
	 */
	protected $log             = null;

	/**
	 * Return an instance of this class
	 *
	 * @since 	1.0.0
	 * @return 	object A single instance of this class.
	 */
	public static function get_instance()
	{
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance ) self::$instance = new self;

		return self::$instance;
	}

	/**
	 * Constructor
	 *
	 * @since 	1.0.0
	 */
	public function __construct()
	{
		self::$instance = $this;

		if ( class_exists( "WC_Subscriptions_Order" ) )
		{
			$this->id                 = WC_Triibo_Assinaturas::DOMAIN;
			$this->icon               = apply_filters( "woocommerce_" . $this->id . "_icon", plugins_url( "assets/images/triibo-25-cor-completo.png", plugin_dir_path( __FILE__ ) ) );

			$this->method_title       = __( "Triibo Recorrente",                                         $this->id );
			$this->method_description = __( "Pagamentos com cartão de crédito usando a Triibo Gateway.", $this->id );
			$this->order_button_text  = __( "Prossiga para o pagamento",                                 $this->id );

			// Subscriptions
			$this->supports           = apply_filters( $this->id . "_supports_array", [
				"subscriptions",
				"subscription_cancellation",
				"subscription_suspension",
				"subscription_reactivation",
				"subscription_amount_changes",
				"subscription_date_changes",
				"subscription_payment_method_change",
				"subscription_payment_method_change_admin",
				"subscription_payment_method_change_customer",
				"default_credit_card_form"
			] );

			// Load the form fields.
			$this->init_form_fields();

			// Load the settings.
			$this->init_settings();

			// Define user set variables.
			$this->title       = $this->get_option( "title"       );
			$this->description = $this->get_option( "description" );
			$this->service     = $this->get_option( "service"     );
			$this->debug       = $this->get_option( "debug"       );

			$this->cc_hide_cpf_field = apply_filters( $this->id . "_hide_cpf_field", false );

			// Active logs.
			if ( $this->debug == "yes" )
			{
				$this->log = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
			}

			$this->node = new Triibo_Api_Node();
			$this->api  = new Triibo_Assinaturas_Api( $this, $this->node );

			$this->init_actions();
		}
	}

	/**
	 * Get api
	 *
	 * @since 	1.0.0
	 * @return 	Triibo_Assinaturas_Api
	 */
	public function get_api()
	{
		return $this->api;
	}

	/**
	 * Get service
	 *
	 * @since 	1.0.0
	 * @return 	string
	 */
	public function get_service()
	{
		return $this->service;
	}

	/**
	 * Get id
	 *
	 * @since 	1.0.0
	 * @return 	string
	 */
	public function get_id()
	{
		return $this->id;
	}

	/**
	 * Get debug
	 *
	 * @since 	1.0.0
	 * @return 	string
	 */
	public function get_debug()
	{
		return $this->debug;
	}

	/**
	 * Get log
	 *
	 * @since 	1.0.0
	 * @return 	wc_get_logger|WC_Logger
	 */
	public function get_log()
	{
		return $this->log;
	}

	/**
	 * Admin page.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function admin_options()
	{
		wp_enqueue_script( $this->id . "_admin", plugins_url( "assets/js/admin/admin.js", plugin_dir_path( __FILE__ ) ), [ "jquery" ], WC_Triibo_Assinaturas::VERSION, true );

		include dirname( __FILE__ ) . "/admin/views/html-admin-page.php";
	}

	/**
	 *
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function init_actions()
	{
		add_action( "wp_enqueue_scripts",                                                   [ $this, "checkout_scripts"                                  ]        );
		add_action( "woocommerce_scheduled_subscription_payment_"              . $this->id, [ $this, "scheduled_subscription_payment"                    ], 10, 2 );
		add_action( "woocommerce_update_options_payment_gateways_"             . $this->id, [ $this, "process_admin_options"                             ]        );
		add_action( "woocommerce_subscription_failing_payment_method_updated_" . $this->id, [ $this, "update_failing_payment_method"                     ], 10, 2 );

		add_filter( "woocommerce_credit_card_form_fields",                                  [ $this, "filter_cc_fields"                                  ], 10, 2 );
		add_filter( "woocommerce_can_subscription_be_updated_to_new-payment-method",        [ $this, "can_subscription_be_updated_to_new_payment_method" ], 20, 2 );
		add_filter( "woocommerce_subscriptions_update_payment_via_pay_shortcode",           [ $this, "can_update_all_subscriptions"                      ], 20, 3 );
	}

	/**
	 * Returns a bool that indicates if currency is amongst the supported ones.
	 *
	 * @since 	1.0.0
	 * @return 	bool
	 */
	public function using_supported_currency()
	{
		return "BRL" === get_woocommerce_currency();
	}

	/**
	 * Returns a value indicating the the Gateway is available or not. It's called
	 * automatically by WooCommerce before allowing customers to use the gateway
	 * for payment.
	 *
	 * @since 	1.0.0
	 * @return 	bool
	 */
	public function is_available()
	{
		// Test if is valid for use.
		$available = "yes" === $this->get_option( "enabled" ) &&
					 $this->node->status()                    &&
					 $this->using_supported_currency()        &&
					 (
						wcs_cart_contains_renewal()                         ||
						WC_Subscriptions_Cart::cart_contains_subscription() ||
						WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
					 );

		if ( !class_exists( "Extra_Checkout_Fields_For_Brazil" ) ) $available = false;

		return $available;
	}

	/**
	 * Get log.
	 *
	 * @since 	1.0.0
	 * @return 	string
	 */
	protected function get_log_view()
	{
		$date      = date( "Y-m-d" );
		$esc_attr  = esc_attr( $this->id );
		$sanitize  = sanitize_file_name( wp_hash( $this->id ) );
		$admin_url = "admin.php?page=wc-status&tab=logs&log_file=$esc_attr-$date-$sanitize.log";
		$link      = esc_url( admin_url( $admin_url ) );
		$text      = __( "System Status &gt; Logs", $this->id );

		return "<a href='$link' target='_blank' >$text</a>";
	}

	/**
	 * Get log.
	 *
	 * @since 	1.0.0
	 * @return 	string
	 */
	protected function get_global_config()
	{
		$triibo = Triibo_Api_Services::get_name( true );
        $url    = esc_url( admin_url( "admin.php?page={$triibo}" ) );
		$text   = __( "clicando aqui", $this->id );

		return "<a href='{$url}' target='_blank' >{$text}</a>";
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function init_form_fields()
	{
		$this->form_fields = [

			// ativar / desativar meio de pagamento
			"enabled"         => [
				"type"        => "checkbox",
				"title"       => __( "Ativar / Desativar",                                        $this->id ),
				"label"       => __( "Ativar Triibo Recorrente",                                  $this->id ),
				"default"     => "no",
			],

			"title"           => [
				"type"        => "text",
				"title"       => __( "Título",                                                    $this->id ),
				"description" => __( "Controla o título que o usuário vê durante o checkout.",    $this->id ),
				"default"     => __( "Triibo Recorrente",                                         $this->id ),
			],
			"description"     => [
				"type"        => "textarea",
				"title"       => __( "Descrição",                                                 $this->id ),
				"description" => __( "Controla a descrição que o usuário vê durante o checkout.", $this->id ),
				"default"     => __( "Pague com a Triibo",                                        $this->id ),
			],

			"service"         => [
				"type"        => "select",
				"title"       => __( "Serviço de pagamento",                                      $this->id ),
				"description" => __( "Escolha qual será o serviço de pagamento padrão.",          $this->id ),
				"default"     => "cielo",
				"class"       => "wc-enhanced-select",
				"options"     => [
					"cielo"     => __( "Cielo", $this->id ),
					// "pagseguro" => __( "PagSeguro", $this->id ),
					// "value"     => __( "Name",      $this->id ),
				],
			],

			"debug"           => [
				"type"        => "checkbox",
				"title"       => __( "Debug Log",                                                 $this->id ),
				"label"       => __( "Ativar logger",                                             $this->id ),
				"description" => sprintf( __( "Registrar log de eventos, %s", $this->id ), $this->get_log_view() ),
				"default"     => "yes",
			],

			"configs" => [
				"type"        => "title",
				"title"       => __( "Chaves de API",                                             $this->id ),
				"description" => sprintf( __( "Acessar configurações globais %s.", $this->id ), $this->get_global_config() ),
			],
		];
	}

	/**
	 * Checkout scripts.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function checkout_scripts()
	{
		$load_scripts = false;

		if ( is_checkout() )         $load_scripts = true;

		if ( $this->is_available() ) $load_scripts = true;

		if ( false === $load_scripts ) return;

		wp_enqueue_style(  $this->id . "_checkout", plugins_url( "assets/css/frontend/transparent-checkout.css", plugin_dir_path( __FILE__ ) ), [],                                                                          WC_Triibo_Assinaturas::VERSION       );
		wp_enqueue_script( $this->id . "_checkout", plugins_url( "assets/js/frontend/transparent-checkout.js",   plugin_dir_path( __FILE__ ) ), [ "json2", "jquery", "woocommerce-extra-checkout-fields-for-brazil-front" ], WC_Triibo_Assinaturas::VERSION, true );

        $user = wp_get_current_user();

		$this->api->validate_token( $user->ID );

		$url = ( $this->node->env() )
				? "https://dev-marketplace.triibo.com.br/wp-json/" . $this->id . "/v1"
				: "https://marketplace.triibo.com.br/wp-json/" . $this->id . "/v1";

		wp_localize_script(
			$this->id . "_checkout",
			"wc_" . $this->id . "_params",
			[
				"user_id"  => $user->ID,
				"base_url" => $url,
				"messages" => [
					"session_error"      => __( "Ocorreu um error. Por favor, atualize a página.",                       $this->id ),

					"unsupported_card"   => __( "Essa bandeira não é suportada pelo Triibo Recorrente atualmente.",      $this->id ),

					"invalid_card"       => __( "O número do cartão de crédito é inválido.",                             $this->id ),
					"invalid_expiry"     => __( "A data de expiração é inválida, por favor, utilize o formato MM / AA.", $this->id ),
					"invalid_cvv"        => __( "O código do cartão é inválido.",                                        $this->id ),

					"invalid_holder"     => __( "Informe o nome do titular do cartão.",                                  $this->id ),
					"invalid_h_cpf"      => __( "Informe o CPF do titular do cartão.",                                   $this->id ),

					"expired_date"       => __( "Por favor, verifique a data de expiração e utilize o formato MM / AA.", $this->id ),

					"empty_installments" => __( "Selecione o número de parcelas.",                                       $this->id ),
					"interest_free"      => __( "sem juros",                                                             $this->id ),

					"general_error"      => __( "Não foi possível processar os dados do seu cartão de crédito, por favor, tente novamente ou entre em contato para receber ajuda.", $this->id ),
				]
			]
		);
	}

	/**
	 * Filter Credit card form fields
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	function filter_cc_fields( $fields, $id )
	{
		if ( $id == $this->id )
		{
			$id = esc_attr( $this->id );

			$fields[ "card-expiry-field" ] =
			"<p class='form-row form-row-first'>
				<label for='" . $id . "-card-expiry' >"      . esc_html__( "Validade (MM/YY)", $this->id ) . "&nbsp;<span class='required' >*</span></label>
				<input id='"  . $id . "-card-expiry' class='input-text wc-credit-card-form-card-expiry' inputmode='numeric' autocomplete='cc-exp' autocorrect='no' autocapitalize='no' spellcheck='no' type='tel' placeholder='" . esc_attr__( "MM / YY", $this->id ) . "' " . $this->field_name( "card-expiry" ) . " />
			</p>";

			$fields[ "card-name" ] =
			"<p class='form-row form-row-wide'>
				<label for='" . $id . "-card-holder-name' >" . esc_html__( "Nome do titular", $this->id ) . "&nbsp;<span class='required' >*</span></label>
				<input id='"  . $id . "-card-holder-name' class='input-text wc-credit-card-form_card_holder_name' type='text' placeholder='" . esc_attr__( "Informe o nome do titular", $this->id ) . "' " . $this->field_name( "card-holder-name" ) . " />
			</p>";

			if ( false === $this->cc_hide_cpf_field || is_wc_endpoint_url( "order-pay" ) )
			{
				$fields[ "card-cpf" ] =
				"<p class='form-row form-row-wide'>
					<label for='" . $id . "-card-holder-cpf' >" . esc_html__( "CPF do titular", $this->id ) . "&nbsp;<span class='required'>*</span></label>
					<input id='"  . $id . "-card-holder-cpf' class='input-text wc-credit-card-form_card_holder_cpf' type='tel' placeholder='" . esc_attr__( "000.000.000-00", $this->id ) . "' " . $this->field_name( "card-holder-cpf" ) . "/>
				</p>";
			}
		}

		return $fields;
	}

	/**
	 * Payment Fields Hook.
	 *
	 * @since 	1.0.0
	 * @return 	void
	 */
	public function payment_fields()
	{
		if ( ( class_exists( "WC_Subscriptions_Cart" ) && WC_Subscriptions_Cart::cart_contains_subscription() )
			|| wcs_cart_contains_resubscribe()
			|| wcs_cart_contains_renewal()
			|| WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment )
		{
			$description = ( $this->node->env() ) ? $this->get_description() . __( " - HOMOL ativado.", $this->id ) : $this->get_description();

			if ( $description ) echo wpautop( wptexturize( trim( $description ) ) );

			parent::payment_fields();
		}
	}

	/**
	 * Process the payment
	 * Function called by WooCommerce to process the payment
	 *
	 * @since 	1.0.0
	 * @param  	int $order_id
	 * @return 	array
	 */
	public function process_payment( $order_id )
	{
		// Processing subscription
		if ( function_exists( "wcs_order_contains_subscription" ) )
			return $this->process_subscription( $order_id );
	}

	/**
	 * Process the subscription payment
	 *
	 * @since 	1.0.0
	 * @param 	mixed 	$order_id
	 * @return 	void
	 */
	public function process_subscription( $order_id )
	{
        $user      = wp_get_current_user();
		$token     = $this->api->validate_token( $user->ID );
		$number    = WC()->checkout()->get_value( $this->id . "-card_number" );
		$response  = $this->get_brand( $token, $number );

		$msg       = "<b>" . $this->title . "</b>:";

		if ( !$response[ "success" ] )
		{
			wc_add_notice( "{$msg} Erro ao consultar bandeira do cartão!", "error" );

			return [
				"result"   => "fail",
				"redirect" => ""
			];
		}

		if ( !$response[ "data" ][ "brandInfo" ][ "supported" ] )
		{
			wc_add_notice( "{$msg} Bandeira do cartão não suportada!", "error" );

			return [
				"result"   => "fail",
				"redirect" => ""
			];
		}

		$brand     = $response[ "data" ][ "brandInfo" ][ "brand" ];
		$triibo_id = get_user_meta( $user->ID, "triiboId_phone", true );
		$order     = wc_get_order( $order_id );

		$cardInfo  = [
			"token"       => $token,

			"holder"      => WC()->checkout()->get_value( $this->id . "-holder"    ),
			"cpf"         => WC()->checkout()->get_value( $this->id . "-h_cpf"     ),
			"phone"       => $triibo_id,

			"card_number" => $number,
			"cvv"         => WC()->checkout()->get_value( $this->id . "-cvv"       ),
			"month"       => WC()->checkout()->get_value( $this->id . "-exp_month" ),
			"year"        => WC()->checkout()->get_value( $this->id . "-exp_year"  ),
			"brand"       => $brand,
		];

		$response  = $this->api->do_subscription_request( $order, $cardInfo );

		if ( !$response[ "success" ] )
		{
			wc_add_notice( "{$msg} " . $response[ "error" ], "error" );

			return [
				"result"   => "fail",
				"redirect" => ""
			];
		}

		if ( !isset( $response[ "payment_change" ] ) || !$response[ "payment_change" ] )
		{
			$this->finalize_order( $order, $response[ "data" ] );

			// Remove cart.
			WC()->cart->empty_cart();
		}

		return [
			"result"   => "success",
			"redirect" => $this->get_return_url( $order ),
		];
	}

	/**
	 * Validates the flag given the card number
	 *
	 * @since 	1.0.0
	 * @param 	array $data
	 * @return 	array
	 */
	public function get_brand( $token, $card_number )
	{
		$params   = [
			"payload" => [
				"gateway"    => $this->get_service(),
				"cardNumber" => $card_number,
			]
		];

		$response = $this->node->brand( $token, $params );

		// $params[ "payload" ][ "cardNumber" ] = "****-****-****-" . substr( $card_number, -4 );
		// $this->log( "INFO - get_brand : " . json_encode( [ $params, $response ] ) );

		return $response;
	}

	/**
	 * Update order status.
	 *
	 * @since 	1.0.0
	 * @param 	WC_Order $order Order data
	 * @param 	array $data Response data
	 * @return 	null
	 */
	public function finalize_order( $order, $data )
	{
		if ( $order )
		{
			if ( $data[ "id" ] )
			{
				$this->save_triibo_payment_data( $order->get_id(), $data );

				$note  = "Payment ID: {$data[ "id" ]}\n";
				$note .= "Gateway: " . $this->service;
				$order->add_order_note( $note );
			}

			$this->log( "INFO - finalize_order : ID " . $order->get_id() . " : " . json_encode( $data ) );

			/**
			 * waiting
			 * 0  : 'NotFinished',      // Aguardando atualização de status.
			 * 12 : 'Pending',          // Aguardandoretorno da instituição xfinanceira.
			 *
			 * success
			 * 1  : 'Authorized',       // Pagamento apto a ser capturado ou definido como pago.
			 * 2  : 'PaymentConfirmed', // Pagamento confirmado e finalizado.
			 *
			 * fail
			 * 	3 : 'Denied',           // Pagamento negado por Autorizador.
			 * 10 : 'Voided',           // Pagamento cancelado.
			 * 11 : 'Refunded',         // Pagamento cancelado após 23h59 do dia de autorização.
			 * 13 : 'Aborted',          // Pagamento cancelado por falha no processamento ou por ação do Antifraude.
			 */

			switch ( $data[ "status" ] )
			{
				case 1:
				case 2:
					$order->payment_complete();
					$order->update_status( "processing" );
				break;

				case 0:
				case 12:
					$order->update_status( "on-hold" );
				break;

				case 3:
				case 10:
				case 11:
				case 13:
					$order->update_status( "failed" );
				break;
			}
		}

		return;
	}

	/**
	 * Saves the payment_id on the main subscription.
	 *
	 * @since 	1.0.0
	 * @param 	int $order_id
	 * @param 	array $data Data from payment
	 * @return 	null
	 */
	protected function save_triibo_payment_data( $order_id, $data )
	{
		update_post_meta( $order_id, "_triibo_assinatura_payment_id",    $data[ "id"    ] );
		update_post_meta( $order_id, "_triibo_assinatura_payment_token", $data[ "token" ] );

		// Also store it on the subscriptions being purchased or paid for in the order
		if ( wcs_order_contains_subscription( $order_id ) )
		{
			$subscriptions = wcs_get_subscriptions_for_order( $order_id );
		}
		else if ( wcs_order_contains_renewal( $order_id ) )
		{
			$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
		}
		else
		{
			$subscriptions = [];
		}

		foreach( $subscriptions as $subscription )
		{
			update_post_meta( $subscription->get_id(), "_triibo_assinatura_payment_id",    $data[ "id"    ] );
			update_post_meta( $subscription->get_id(), "_triibo_assinatura_payment_token", $data[ "token" ] );
		}

		return;
	}

	/**
	 * Process the renewal payment.
	 *
	 * @since 	1.0.0
	 * @param 	float 	$renewal_total 	The amount to charge.
	 * @param 	WC_Order 	$renewal_order 	A WC_Order object created to record the renewal payment.
	 * @return 	null
	 */
	public function scheduled_subscription_payment( $renewal_total, $renewal_order )
	{
		$response = $this->api->do_subscription_renewal_payment( $renewal_order, $renewal_total );

		if ( !$response[ "success" ] )
		{
			$this->log( "ERROR - scheduled_subscription_payment : order_id " . $renewal_order->ID . " | " . json_encode( $response ), true );

			$renewal_order->update_status( "pending" );

			return;
		}

		$this->finalize_order( $renewal_order, $response[ "data" ] );

		return;
	}

	/**
	 * Update the _pagseguro_assinatura for a subscription after using Pagseguro Recorrente to complete a payment to make up for.
	 * an automatic renewal payment which previously failed.
	 *
	 * @since 	1.0.0
	 * @param 	WC_Subscription $subscription The subscription for which the failing payment method relates.
	 * @param 	WC_Order        $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	 * @return 	void
	 */
	public function update_failing_payment_method( $subscription, $renewal_order )
	{
		$id = get_post_meta( $renewal_order->get_id(), "_triibo_assinatura_payment_id",    true );
		$tk = get_post_meta( $renewal_order->get_id(), "_triibo_assinatura_payment_token", true );

		$sid = $subscription->get_id();

		$this->log( "ERROR - update_failing_payment_method : SUB - $sid | id - $id | tk - $tk", true );

		update_post_meta( $sid, "_triibo_assinatura_payment_id",    $id );
		update_post_meta( $sid, "_triibo_assinatura_payment_token", $tk );
	}

	/**
	 *
	 * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	 * and use a payment gateway which allows the subscription to be cancelled.
	 *
	 * @since 	1.0.0
	 * @param 	bool            $subscription_can_be_changed Flag of whether the subscription can be changed.
	 * @param 	WC_Subscription $subscription The subscription to check.
	 * @return 	bool Flag indicating whether the subscription payment method can be updated.
	 */
	function can_subscription_be_updated_to_new_payment_method( $subscription_can_be_changed, $subscription )
	{
		if ( $this->id == $subscription->get_payment_method() )
			if ( $subscription->has_status( [ "active", "on-hold" ] ) )
				return true;

		return $subscription_can_be_changed;
	}

	/**
	 *
	 * Return false because pagseguro does not support multiple subscriptions
	 *
	 * @since 	1.0.0
	 * @param 	bool            $subscription_can_be_changed Flag of whether the subscription can be changed.
	 * @param 	string $gateway_id The gateway id.
	 * @param 	WC_Subscription $subscription The subscription to check.
	 * @return 	bool Flag indicating whether the other subscriptions can be updated.
	 */
	function can_update_all_subscriptions( $can_update, $gateway_id, $subscription )
	{
		if ( $this->id == $gateway_id ) return false;

		return $can_update;
	}

	/**
	 * Register log
	 *
	 * @since 	1.0.0
	 * @param 	string $message
	 * @param 	bool $is_error
	 * @return 	void
	 */
	private function log( $message, $is_error = null )
	{
		if ( $this->debug == "yes" )
		{
			$this->log->add( $this->id, $message );
		}
		else if ( $is_error )
		{
			$this->log->add( $this->id, $message );
		}
	}
}
