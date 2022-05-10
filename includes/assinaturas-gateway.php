<?php
/**
 * WooCommerce Triibo Assinaturas Gateway class
 *
 * @package WooCommerce_Triibo_Assinaturas/Classes/Gateway
 * @version 1.0
 */
defined( "ABSPATH" ) || exit;

class WC_Triibo_Assinaturas_Gateway extends WC_Payment_Gateway_CC
{
	/**
	 * Instance of this class.
	 *
	 * @var object
	 */
	protected static $instance = null;

	/**
	 * Return an instance of this class.
	 *
	 * @return object A single instance of this class.
	 */
	public static function get_instance()
	{
		// If the single instance hasn't been set, set it now.
		if ( null === self::$instance )
		{
			self::$instance = new self;
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct()
	{
		self::$instance = $this;

		if ( class_exists( "WC_Subscriptions_Order" ) )
		{
			$this->id                 = "triibo_assinaturas";
			$this->icon               = apply_filters( "woocommerce_triibo_assinaturas_icon", plugins_url( "assets/images/triibo-25-cor-completo.png", plugin_dir_path( __FILE__ ) ) );

			$this->method_title       = __( "Triibo Recorrente",                                         "triibo_assinaturas" );
			$this->method_description = __( "Pagamentos com cartão de crédito usando a Triibo Gateway.", "triibo_assinaturas" );
			$this->order_button_text  = __( "Prossiga para o pagamento",                                 "triibo_assinaturas" );

			// Subscriptions
			$this->supports = apply_filters("triibo_assinaturas_supports_array", [
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
			$this->title             = $this->get_option( "title"            );
			$this->description       = $this->get_option( "description"      );

			$this->mkt_homol         = $this->get_option( "mkt_homol", "yes" );
			$this->homol             = $this->get_option( "homol",     "yes" );

			$this->homol_account     = $this->get_option( "homol_account"    );
			$this->homol_password    = $this->get_option( "homol_password"   );

			$this->account           = $this->get_option( "account"          );
			$this->password          = $this->get_option( "password"         );

			$this->service           = $this->get_option( "service"          );
			$this->debug             = $this->get_option( "debug"            );

			$this->cc_hide_cpf_field = apply_filters('triibo_assinaturas_hide_cpf_field',       false);

			// Active logs.
			if ( $this->debug == "yes" )
			{
				$this->log = ( function_exists( "wc_get_logger" ) ) ? wc_get_logger() : new WC_Logger();
			}

			$this->api = new WC_Triibo_Assinaturas_API( $this );

			$this->init_actions();
		}
	}

	/**
	 * Admin page.
	 */
	public function admin_options()
	{
		wp_enqueue_script( "triibo_assinaturas_admin", plugins_url( "assets/js/admin/admin.js", plugin_dir_path( __FILE__ ) ), [ "jquery" ], WC_Triibo_Assinaturas::VERSION, true );

		include dirname( __FILE__ ) . "/admin/views/html-admin-page.php";
	}

	public function init_actions()
	{
		add_action( "wp_enqueue_scripts",                                       [ $this, "checkout_scripts"      ] );
		add_action( "woocommerce_update_options_payment_gateways_" . $this->id, [ $this, "process_admin_options" ] );
		add_filter( "woocommerce_credit_card_form_fields",                      [ $this, "filter_cc_fields"      ], 10, 2 );

		// add_action( "woocommerce_scheduled_subscription_payment_"              . $this->id, [ $this, "scheduled_subscription_payment"                    ], 10, 2 );
		// add_action( "woocommerce_subscription_failing_payment_method_updated_" . $this->id, [ $this, "update_failing_payment_method"                     ], 10, 2 );
		// add_filter( "woocommerce_subscription_payment_meta",                                [ $this, "add_subscription_payment_meta"                     ], 10, 2 );
		// add_filter( "woocommerce_subscription_validate_payment_meta",                       [ $this, "validate_subscription_payment_meta"                ], 10, 2 );
		// add_action( "woocommerce_subscription_status_updated",                              [ $this, "subscription_status_changed"                       ], 10, 3 );
		// add_filter( "woocommerce_can_subscription_be_updated_to_new-payment-method",        [ $this, "can_subscription_be_updated_to_new_payment_method" ], 20, 2 );
		// add_filter( "woocommerce_subscriptions_update_payment_via_pay_shortcode",           [ $this, "can_update_all_subscriptions"                      ], 20, 3 );
	}

	/**
	 * Returns a bool that indicates if currency is amongst the supported ones.
	 *
	 * @return bool
	 */
	public function using_supported_currency()
	{
		return "BRL" === get_woocommerce_currency();
	}

	/**
	 * Get account.
	 *
	 * @return string
	 */
	public function get_account()
	{
		return ( $this->homol === "yes" ) ? $this->homol_account : $this->account;
	}

	/**
	 * Get password.
	 *
	 * @return string
	 */
	public function get_password()
	{
		return ( $this->homol === "yes" ) ? $this->homol_password : $this->password;
	}

	/**
	 * Returns a value indicating the the Gateway is available or not. It's called
	 * automatically by WooCommerce before allowing customers to use the gateway
	 * for payment.
	 *
	 * @return bool
	 */
	public function is_available()
	{
		// Test if is valid for use.
		$available = "yes" === $this->get_option( "enabled" ) &&
					 ""    !== $this->get_account()           &&
					 ""    !== $this->get_password()          &&
					 $this->using_supported_currency()        &&
					 (
						wcs_cart_contains_renewal()                         ||
						WC_Subscriptions_Cart::cart_contains_subscription() ||
						WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment
					 );

		if ( ! class_exists( "Extra_Checkout_Fields_For_Brazil" ) ) $available = false;

		return $available;
	}

	/**
	 * Get log.
	 *
	 * @return string
	 */
	protected function get_log_view()
	{
		if ( defined( "WC_VERSION" ) && version_compare( WC_VERSION, "2.2", ">=" ) )
		{
			$date      = date( "Y-m-d" );
			$esc_attr  = esc_attr( $this->id );
			$sanitize  = sanitize_file_name( wp_hash( $this->id ) );
			$admin_url = "admin.php?page=wc-status&tab=logs&log_file=$esc_attr-$date-$sanitize.log";
			$link      = esc_url( admin_url( $admin_url ) );
			$text      = __( "System Status &gt; Logs", "triibo_assinaturas" );

			return "<a href='$link' >$text</a>";
		}

		$esc_attr = esc_attr( $this->id );
		$sanitize = sanitize_file_name( wp_hash( $this->id ) );

		return "<code>woocommerce/logs/$esc_attr-$sanitize.txt</code>";
	}

	/**
	 * Initialise Gateway Settings Form Fields.
	 */
	public function init_form_fields()
	{
		$this->form_fields = [

			// ativar / desativar meio de pagamento
			"enabled"         => [
				"type"        => "checkbox",
				"title"       => __( "Ativar / Desativar",       "triibo_assinaturas" ),
				"label"       => __( "Ativar Triibo Recorrente", "triibo_assinaturas" ),
				"default"     => "no",
			],

			"title"           => [
				"type"        => "text",
				"title"       => __( "Título",                                                        "triibo_assinaturas" ),
				"description" => __( "Controla o título que o usuário vê durante o checkout.",        "triibo_assinaturas" ),
				"default"     => __( "Triibo Recorrente",                                             "triibo_assinaturas" ),
			],
			"description"     => [
				"type"        => "textarea",
				"title"       => __( "Descrição",                                                     "triibo_assinaturas" ),
				"description" => __( "Controla a descrição que o usuário vê durante o checkout.",     "triibo_assinaturas" ),
				"default"     => __( "Pague com a Triibo",                                            "triibo_assinaturas" ),
			],

			// integração
			"integration"     => [
				"title"       => __( "Integração", "triibo_assinaturas" ),
				"type"        => "title",
				"description" => "",
			],
			"homol"           => [
				"type"        => "checkbox",
				"title"       => __( "API HOMOL",                                                     "triibo_assinaturas" ),
				"label"       => __( "Ativar API Triibo de homologação",                              "triibo_assinaturas" ),
				"default"     => "yes",
			],
			"mkt_homol"         => [
				"type"        => "checkbox",
				"title"       => __( "Marketplace HOMOL",                                             "triibo_assinaturas" ),
				"label"       => __( "Ativar ambiente Marketplace de homologação",                    "triibo_assinaturas" ),
				"default"     => "yes",
			],

			"service"         => [
				"type"        => "select",
				"title"       => __( "Serviço de pagamento",                                          "triibo_assinaturas" ),
				"description" => __( "Escolha qual será o serviço de pagamento padrão.",              "triibo_assinaturas" ),
				"default"     => "cielo",
				"class"       => "wc-enhanced-select",
				"options"     => [
					"cielo"     => __( "Cielo",     "triibo_assinaturas" ),
					"pagseguro" => __( "PagSeguro", "triibo_assinaturas" ),
					// "value"     => __( "Name",      "triibo_assinaturas" ),
				],
			],

			"homol_account"   => [
				"type"        => "text",
				"title"       => __( "Conta HOMOL",                                                   "triibo_assinaturas" ),
				"description" => __( "Entre com a conta de autenticação do Gateway Triibo de HOMOL.", "triibo_assinaturas" ),
				"desc_tip"    => true,
				"default"     => "",
			],
			"homol_password"  => [
				"type"        => "text",
				"title"       => __( "Senha HOMOL",                                                   "triibo_assinaturas" ),
				"description" => __( "Entre com a senha de autenticação do Gateway Triibo de HOMOL.", "triibo_assinaturas" ),
				"desc_tip"    => true,
				"default"     => "",
			],

			"account"         => [
				"type"        => "text",
				"title"       => __( "Conta",                                                         "triibo_assinaturas" ),
				"description" => __( "Entre com a conta de autenticação do Gateway Triibo de PROD.",  "triibo_assinaturas" ),
				"desc_tip"    => true,
				"default"     => "",
			],
			"password"        => [
				"type"        => "text",
				"title"       => __( "Senha",                                                         "triibo_assinaturas" ),
				"description" => __( "Entre com a senha de autenticação do Gateway Triibo de PROD.",  "triibo_assinaturas" ),
				"desc_tip"    => true,
				"default"     => "",
			],

			"debug"           => [
				"type"        => "checkbox",
				"title"       => __( "Debug Log",                                                     "triibo_assinaturas" ),
				"label"       => __( "Ativar logger",                                                 "triibo_assinaturas" ),
				"description" => sprintf( __( "Registrar log de eventos, %s", "triibo_assinaturas" ), $this->get_log_view() ),
				"default"     => "yes",
			]
		];
	}

	/**
	 * Checkout scripts.
	 */
	public function checkout_scripts()
	{
		$load_scripts = false;

		if ( is_checkout() )         $load_scripts = true;

		if ( $this->is_available() ) $load_scripts = true;

		if ( false === $load_scripts ) return;

		wp_enqueue_style(  "triibo_assinaturas_checkout", plugins_url( "assets/css/frontend/transparent-checkout.css", plugin_dir_path( __FILE__ ) ), [],                                                                          WC_Triibo_Assinaturas::VERSION       );
		wp_enqueue_script( "triibo_assinaturas_checkout", plugins_url( "assets/js/frontend/transparent-checkout.js",   plugin_dir_path( __FILE__ ) ), [ "json2", "jquery", "woocommerce-extra-checkout-fields-for-brazil-front" ], WC_Triibo_Assinaturas::VERSION, true );

		$url = ( $this->homol === "yes" ) ? "https://dev-marketplace.triibo.com.br/wp-json/triibo_assinaturas/v1" : "https://marketplace.triibo.com.br/wp-json/triibo_assinaturas/v1";

		wp_localize_script(
			"triibo_assinaturas_checkout",
			"wc_triibo_assinaturas_params",
			[
				"base_url" => $url,
				"messages" => [
					"session_error"      => __( "Ocorreu um error. Por favor, atualize a página.",                       "triibo_assinaturas" ),

					"unsupported_card"   => __( "Essa bandeira não é suportada pelo Triibo Recorrente atualmente.",      "triibo_assinaturas" ),

					"invalid_card"       => __( "O número do cartão de crédito é inválido.",                             "triibo_assinaturas" ),
					"invalid_expiry"     => __( "A data de expiração é inválida, por favor, utilize o formato MM / AA.", "triibo_assinaturas" ),
					"invalid_cvv"        => __( "O código do cartão é inválido.",                                        "triibo_assinaturas" ),

					"invalid_holder"     => __( "Informe o nome do titular do cartão.",                                  "triibo_assinaturas" ),
					"invalid_h_cpf"      => __( "Informe o CPF do titular do cartão.",                                   "triibo_assinaturas" ),

					"expired_date"       => __( "Por favor, verifique a data de expiração e utilize o formato MM / AA.", "triibo_assinaturas" ),

					"empty_installments" => __( "Selecione o número de parcelas.",                                       "triibo_assinaturas" ),
					"interest_free"      => __( "sem juros",                                                             "triibo_assinaturas" ),

					"general_error"      => __( "Não foi possível processar os dados do seu cartão de crédito, por favor, tente novamente ou entre em contato para receber ajuda.", "triibo_assinaturas" ),
				]
			]
		);
	}

	/**
	 * Filter Credit card form fields
	 */
	function filter_cc_fields( $fields, $id )
	{
		if ( $id == $this->id )
		{
			$id = esc_attr( $this->id );

			$is_update_payment_method = is_wc_endpoint_url("order-pay");

			$fields["card-expiry-field"] =
			"<p class='form-row form-row-first'>
				<label for='" . $id . "-card-expiry' >"      . esc_html__( "Validade (MM/YY)", "triibo_assinaturas" ) . "&nbsp;<span class='required' >*</span></label>
				<input id='"  . $id . "-card-expiry' class='input-text wc-credit-card-form-card-expiry' inputmode='numeric' autocomplete='cc-exp' autocorrect='no' autocapitalize='no' spellcheck='no' type='tel' placeholder='" . esc_attr__( "MM / YY", "triibo_assinaturas" ) . "' " . $this->field_name( "card-expiry" ) . " />
			</p>";

			$fields["card-name"] =
			"<p class='form-row form-row-wide'>
				<label for='" . $id . "-card-holder-name' >" . esc_html__( "Nome do titular", "triibo_assinaturas" ) . "&nbsp;<span class='required' >*</span></label>
				<input id='"  . $id . "-card-holder-name' class='input-text wc-credit-card-form_card_holder_name' type='text' placeholder='" . esc_attr__( "Informe o nome do titular", "triibo_assinaturas" ) . "' " . $this->field_name( "card-holder-name" ) . " />
			</p>";

			if ( false === $this->cc_hide_cpf_field       || $is_update_payment_method )
			{
				$fields["card-cpf"] =
				"<p class='form-row form-row-wide'>
					<label for='" . $id . "-card-holder-cpf' >" . esc_html__( "CPF do titular", "triibo_assinaturas" ) . "&nbsp;<span class='required'>*</span></label>
					<input id='"  . $id . "-card-holder-cpf' class='input-text wc-credit-card-form_card_holder_cpf' type='tel' placeholder='" . esc_attr__( "000.000.000-00", "triibo_assinaturas" ) . "' " . $this->field_name( "card-holder-cpf" ) . "/>
				</p>";
			}
		}

		return $fields;
	}

	/**
	 * Process the payment
	 * Function called by WooCommerce to process the payment
	 *
	 * @param  int $order_id
	 * @return array
	 */
	public function process_payment( $order_id )
	{
		// Processing subscription
		if ( function_exists( "wcs_order_contains_subscription" ) )
		{
			return $this->process_subscription( $order_id );
		}
	}

	public function process_subscription( $order_id )
	{
        $user      = wp_get_current_user();

		$triibo_id = get_user_meta( $user->ID, "triiboId_phone", true );

		$order     = wc_get_order( $order_id );

		$cardInfo  = [
			"holder"      => WC()->checkout()->get_value( "triibo_assinaturas-holder"      ),
			"cpf"         => WC()->checkout()->get_value( "triibo_assinaturas-h_cpf"       ),
			"phone"       => $triibo_id,

			"card_number" => WC()->checkout()->get_value( "triibo_assinaturas-card_number" ),
			"cvc"         => WC()->checkout()->get_value( "triibo_assinaturas-cvc"         ),
			"month"       => WC()->checkout()->get_value( "triibo_assinaturas-exp_month"   ),
			"year"        => WC()->checkout()->get_value( "triibo_assinaturas-exp_year"    ),
			"brand"       => WC()->checkout()->get_value( "triibo_assinaturas-brand"       ),
		];

		$response = $this->api->do_subscription_request( $order, $cardInfo );

		if ( ! $response["success"] )
		{
			wc_add_notice( $response["error"], "error" );

			return array(
				"result"   => "fail",
				"redirect" => ""
			);
		}
		else
		{
			$order->update_status("on-hold", __( "Aguardando pagamento", "triibo_assinaturas" ));

			$this->finalize_order( $order, $response["data"] );

			// Remove cart.
			WC()->cart->empty_cart();

			return [
				"result"   => "success",
				"redirect" => $this->get_return_url( $order ),
			];
		}
	}

	/**
	 * Update order status.
	 *
	 * @param WC_Order $order Order data
	 * @param array $data Response data
	 */
	public function finalize_order( $order, $data )
	{
		if ( $data["id"] ) $this->save_triibo_assinatura( $order->get_id(), $data["id"] );

		$order->payment_complete();

		return;
	}

	/**
	 * Saves the pre-approval code on the main subscription.
	 *
	 * @param int $order_id
	 * @param string $assinatura Pre-approval code from Pagseguro
	 * @return null
	 */
	protected function save_triibo_assinatura( $order_id, $assinatura )
	{
		update_post_meta( $order_id, "_triibo_assinatura_transaction_id", $assinatura );

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
			update_post_meta( $subscription->get_id(), "_triibo_assinatura_transaction_id", $assinatura );
		}

		return;
	}

	// /**
	//  * process_subscription_payment function for initial and recurrent payments.
	//  *
	//  * @access public
	//  * @param mixed $order
	//  * @param int $amount (default: 0)
	//  */
	// public function process_subscription_payment( $order, $amount = 0 ) {

	// 	if ( $amount <= 0 ) {

	// 		if ( "yes" === $this->debug ) {
	// 			$this->log->add( $this->id, "process_subscription_payment(): Sorry, the minimum allowed order total is 0.01 to use this payment method. Amount: " . $amount );
	// 		}

	// 		return new WP_Error( "pagseguro_error", __( "Sorry, the minimum allowed order total is 0.01 to use this payment method.", "triibo_assinaturas" ) );
	// 	}

	// 	$assinatura = get_post_meta( $order->get_id(), "_pagseguro_assinatura", true);
	// 	// Or fail :(
	// 	if ( ! $assinatura || "" == $assinatura ) {

	// 		if ( "yes" === $this->debug ) {
	// 			$this->log->add( $this->id, "process_subscription_payment(): Subscription on Pagseguro not found" );
	// 		}

	// 		return new WP_Error( "pagseguro_error", __( "Subscription on Pagseguro not found", "triibo_assinaturas" ) );
	// 	}

	// 	$response = $this->api->make_subscription_payment( $assinatura, $order );

	// 	// $response["transactionCode"] = "TESTING";

	// 	if( isset( $response["transactionCode"]) ) {

	// 		return $this->save_transaction_code( $order, $response["transactionCode"] );

	// 	}

	// 	if ( "yes" === $this->debug ) {
	// 		$this->log->add( $this->id, "process_subscription_payment(): Error trying to make payment for order: " . $order->get_id() );
	// 	}

	// 	return new WP_Error( "pagseguro_error", __( "Error trying to make payment for order", "triibo_assinaturas" ) );

	// }


	// /**
	//  * Process initial payment, after receiving notification of validation transaction
	//  *
	//  * @param string $order WC_Order
	//  */
	// public function process_initial_payment( WC_Order $order ) {

	// 	if(  wcs_order_contains_subscription( $order, array( "parent", "renewal" ) ) && "yes" != $order->get_meta("_pr_processing") ) {

	// 		$order->update_meta_data("_pr_processing", "yes");
	// 		$order->save();

	// 		$response = $this->process_subscription_payment( $order, $order->get_total() );

	// 		if ( "yes" === $this->debug ) {
	// 			$this->log->add( $this->id, "Processing payment on pending order: " . $order->get_id() . ". Response:" . print_r($response, true) );
	// 		}
	// 	}

	// 	return;
	// }

	// /**
	//  * scheduled_subscription_payment function.
	//  *
	//  * @param $amount_to_charge float The amount to charge.
	//  * @param $renewal_order WC_Order A WC_Order object created to record the renewal payment.
	//  * @access public
	//  * @return void
	//  */
	// public function scheduled_subscription_payment( $amount_to_charge, $renewal_order ) {

	// 	if ( "yes" === $this->debug ) {
	// 		$this->log->add( $this->id, "called scheduled_subscription_payment(). Order: " . $renewal_order->get_id() );
	// 	}

	// 	$response = $this->process_subscription_payment( $renewal_order, $amount_to_charge );

	// 	if ( is_wp_error( $response ) ) {
	// 		$renewal_order->update_status( "failed", sprintf( __( "Pagseguro Transaction Failed (%s)", "triibo_assinaturas" ), $response->get_error_message() ) );
	// 	}

	// 	return;
	// }

	// /*
	//  * Change status of assinatura no pagseguro
	//  *
	// */
	// function subscription_status_changed( $order, $new_status, $old_status ) {

	// 	if( $new_status != "cancelled")
	// 		return;

	// 	$order_id = method_exists( $order, "get_id" ) ? $order->get_id() : $order->id;

	// 	$assinatura = get_post_meta( $order_id, "_pagseguro_assinatura", true);

	// 	if( $assinatura != "") {

	// 		// $response = $this->api->pagseguro_update_subscription_status( $assinatura, $order_id );
	// 		$response = $this->api->pagseguro_cancel_subscription( $assinatura, $order_id );

	// 		if( $response == true ) {

	// 			$order->add_order_note( sprintf( __( "PagSeguro: Subscription Cancelled (%s).", "triibo_assinaturas" ), $assinatura ) );

	// 		} else {

	// 			$order->add_order_note( sprintf( __( "PagSeguro: Problem Cancelling Subscription (%s).", "triibo_assinaturas" ), $assinatura ) );

	// 		}
	// 	}
	// }


	// /**
	//  * Update order status.
	//  *
	//  * @param array $posted PagSeguro post data.
	//  */
	// public function update_order_status( $posted ) {
	// 	if ( isset( $posted->reference ) ) {
	// 		$id    = (int) str_replace( $this->invoice_prefix, "", $posted->reference );
	// 		$order = wc_get_order( $id );

	// 		// Check if order exists.
	// 		if ( ! $order ) {
	// 			return;
	// 		}

	// 		if( "" == get_post_meta($order->get_id(), "_pagseguro_assinatura", true ) ) {
	// 			return;
	// 		}

	// 		// Checks whether the invoice number matches the order.
	// 		// If true processes the payment.
	// 		if ( $order->get_id() === $id ) {

	// 			if ( "yes" === $this->debug ) {
	// 				$this->log->add( $this->id, "PagSeguro payment status for order " . $order->get_id() . " is: " . intval( $posted->status ) );
	// 			}

	// 			$transaction_id = get_post_meta($order->get_id(), "_pagseguro_transaction_id", true);
	// 			$posted_transaction_id = str_replace("-", "", $posted->code );

	// 			if( $posted_transaction_id == $transaction_id ) {

	// 				switch ( intval( $posted->status ) ) {
	// 					case 1:
	// 						$order->update_status( "on-hold" );
	// 						$order->add_order_note( __( "PagSeguro: The buyer initiated the transaction. Waiting for payment confirmation.", "triibo_assinaturas" ) );
	// 						break;
	// 					case 2:
	// 						$order->update_status( "on-hold" );
	// 						$order->add_order_note( __( "PagSeguro: Payment under review.", "triibo_assinaturas" )  );
	// 						// Reduce stock for billets.
	// 						if ( function_exists( "wc_reduce_stock_levels" ) ) {
	// 							wc_reduce_stock_levels( $order->get_id() );
	// 						}

	// 						break;
	// 					case 3:
	// 						// Sometimes PagSeguro should change an order from cancelled to paid, so we need to handle it.
	// 						if ( method_exists( $order, "get_status" ) && "cancelled" === $order->get_status() ) {
	// 							$order->update_status( "processing", __( "PagSeguro: Payment approved.", "triibo_assinaturas" ) );
	// 							wc_reduce_stock_levels( $order->get_id() );
	// 						} else {
	// 							$order->add_order_note( __( "PagSeguro: Payment approved.", "triibo_assinaturas" ) );

	// 							// Changing the order for processing and reduces the stock.
	// 							$order->payment_complete( sanitize_text_field( (string) $posted->code ) );
	// 						}

	// 						break;
	// 					case 4:
	// 						$order->add_order_note( __( "PagSeguro: Payment completed and credited to your account.", "triibo_assinaturas" ) );

	// 						break;
	// 					case 5:
	// 						$order->update_status( "on-hold" );
	// 						$order->add_order_note( __( "PagSeguro: Payment came into dispute.", "triibo_assinaturas" ) );
	// 						$this->send_email(
	// 							/* translators: %s: order number */
	// 							sprintf( __( "Payment for order %s came into dispute", "triibo_assinaturas" ), $order->get_id() ),
	// 							__( "Payment in dispute", "triibo_assinaturas" ),
	// 							/* translators: %s: order number */
	// 							sprintf( __( "Order %s has been marked as on-hold, because the payment came into dispute in PagSeguro.", "triibo_assinaturas" ), $order->get_id() )
	// 						);

	// 						break;
	// 					case 6:
	// 						$order->update_status( "refunded", __( "PagSeguro: Payment refunded.", "triibo_assinaturas" ) );
	// 						$this->send_email(
	// 							/* translators: %s: order number */
	// 							sprintf( __( "Payment for order %s refunded", "triibo_assinaturas" ), $order->get_id() ),
	// 							__( "Payment refunded", "triibo_assinaturas" ),
	// 							/* translators: %s: order number */
	// 							sprintf( __( "Order %s has been marked as refunded by PagSeguro.", "triibo_assinaturas" ), $order->get_id() )
	// 						);

	// 						if ( function_exists( "wc_increase_stock_levels" ) ) {
	// 							wc_increase_stock_levels( $order->get_id() );
	// 						}

	// 						break;
	// 					case 7:

	// 						if( wcs_order_contains_renewal( $order ) ) {

	// 							// Maybe actvate this to test retry payments.
	// 							add_filter("wcs_is_scheduled_payment_attempt", "__return_true" );

	// 							$order->update_status( "failed", __( "PagSeguro: Payment cancelled.", "triibo_assinaturas" ) );


	// 							if ( function_exists( "wc_increase_stock_levels" ) ) {
	// 								wc_increase_stock_levels( $order->get_id() );
	// 							}

	// 						} else {

	// 							$order->update_status( "cancelled", __( "PagSeguro: Payment canceled.", "triibo_assinaturas" ) );

	// 							if ( function_exists( "wc_increase_stock_levels" ) ) {
	// 								wc_increase_stock_levels( $order->get_id() );
	// 							}

	// 						}

	// 						break;

	// 					default:
	// 						break;
	// 				}
	// 			} else {

	// 				// If got to this point, it"s probably a validation transaction
	// 				if( (int) $posted->type == 1 && (int) $posted->status == 6 ) {

	// 					$order->add_order_note( __( "PagSeguro IPN: Credit Card Validated.", "triibo_assinaturas" ) );

	// 					$this->process_initial_payment( $order );

	// 				} else if( (int) $posted->type == 7 ) {

	// 					$order->update_status( "cancelled", __( "PagSeguro: Payment canceled.", "triibo_assinaturas" ) );

	// 				} else {
	// 					if ( "yes" === $this->debug ) {
	// 						$this->log->add( $this->id, "$posted_transaction_id (".$posted_transaction_id.") and $transaction_id (".$transaction_id.") no not match." );
	// 					}
	// 				}
	// 			}
	// 		} else {
	// 			if ( "yes" === $this->debug ) {
	// 				$this->log->add( $this->id, "Error: Order Key does not match with PagSeguro reference." );
	// 			}
	// 		}
	// 	}
	// 	return;
	// }

	// /**
	//  * Update pre-approval status.
	//  *
	//  * @param array $posted PagSeguro post data.
	//  */
	// public function update_preapproval_status( $posted ) {

	// 	if ( isset( $posted->reference ) && isset( $posted->code ) ) {
	// 		$id    = (int) str_replace( $this->invoice_prefix, "", $posted->reference );
	// 		$order = wc_get_order( $id );

	// 		// Check if order exists.
	// 		if ( ! $order ) {
	// 			return;
	// 		}

	// 		$order_id = method_exists( $order, "get_id" ) ? $order->get_id() : $order->id;

	// 		// Checks whether the invoice number matches the order.
	// 		// If true processes the payment.
	// 		if ( $order_id === $id ) {

	// 			if ( wcs_order_contains_subscription( $order_id ) ) {
	// 				$subscriptions = wcs_get_subscriptions_for_order( $order_id );
	// 			} else if ( wcs_order_contains_renewal( $order_id ) ) {
	// 				$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
	// 			} else {
	// 				$subscriptions = array();
	// 			}

	// 			if( empty( $subscriptions ) )
	// 				return;

	// 			$subscription = array_pop( $subscriptions );

	// 			$assinatura = get_post_meta( $subscription->get_id(), "_pagseguro_assinatura", true);

	// 			if( $assinatura == $posted->code ) {

	// 				if ( "yes" === $this->debug ) {
	// 					$this->log->add( $this->id, "PagSeguro preaproval status for subscription " . $subscription->get_id() . " is: " . $posted->status );
	// 				}

	// 				if( isset( $posted->tracker ) ) {
	// 					$tracker = (string) $posted->tracker;
	// 					update_post_meta( $subscription->get_id(), "_pagseguro_tracker", sanitize_text_field( $tracker) );
	// 				}

	// 				switch ( $posted->status ) {
	// 					case "INITIATED":
	// 						//O comprador iniciou o processo de pagamento, mas abandonou o checkout e não concluiu a compra.
	// 						$subscription->add_order_note( __( "PagSeguro: The buyer initiated the transaction, but so far the PagSeguro not received any payment information.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "PENDING":
	// 						//O processo de pagamento foi concluído e transação está em análise ou aguardando a confirmação da operadora.
	// 						$subscription->add_order_note( __( "PagSeguro: Payment under review.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "ACTIVE":
	// 						// A criação da recorrência, transação validadora ou transação recorrente foi aprovada.
	// 						// And we don"t update the status, because we want to make sure the the status is updated bases on the orders.
	// 						$subscription->add_order_note( __( "PagSeguro: The preApproval, authorization or recurring transaction was approved.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "PAYMENT_METHOD_CHANGE":
	// 						// Uma transação retornou como "Cartão Expirado, Cancelado ou Bloqueado" e o cartão da recorrência precisa ser substituído pelo comprador.
	// 						$subscription->add_order_note( __( "PagSeguro: Credit card needs to be updated by buyer.", "triibo_assinaturas" ) );
	// 						$subscription->add_order_note( __( "Your credit card needs to be updated", "triibo_assinaturas" ), 1 );

	// 						break;
	// 					case "SUSPENDED":
	// 						// A recorrência foi suspensa pelo vendedor.
	// 						$subscription->update_status( "on-hold", __( "PagSeguro: The preapproval was suspended.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "CANCELLED":
	// 						// A criação da recorrência foi cancelada pelo PagSeguro
	// 						$subscription->update_status( "cancelled", __( "PagSeguro: Preapproval was cancelled by Pagseguro.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "CANCELLED_BY_RECEIVER":
	// 						// A recorrência foi cancelada a pedido do vendedor.
	// 						$subscription->update_status( "cancelled", __( "PagSeguro: Preapproval was cancelled by shop owner.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "CANCELLED_BY_SENDER":
	// 						// A recorrência foi cancelada a pedido do comprador.
	// 						$subscription->update_status( "cancelled", __( "PagSeguro: Preapproval was cancelled by buyer.", "triibo_assinaturas" ) );

	// 						break;
	// 					case "EXPIRED":
	// 						// A recorrência expirou por atingir a data limite da vigência ou por ter atingido o valor máximo de cobrança definido na cobrança do plano.
	// 						$subscription->update_status( "cancelled", __( "PagSeguro: Preapproval expired.", "triibo_assinaturas" ) );

	// 						break;
	// 					default:
	// 						break;
	// 				}
	// 			} else {
	// 				if ( "yes" === $this->debug ) {
	// 					$this->log->add( $this->id, "Error: Preapproval code does not match with PagSeguro code." );
	// 				}
	// 			}
	// 		} else {
	// 			if ( "yes" === $this->debug ) {
	// 				$this->log->add( $this->id, "Error: Order Key does not match with PagSeguro reference." );
	// 			}
	// 		}
	// 	}
	// 	return;
	// }


	// /**
	//  * Send email notification.
	//  *
	//  * @param string $subject Email subject.
	//  * @param string $title   Email title.
	//  * @param string $message Email message.
	//  */
	// protected function send_email( $subject, $title, $message ) {
	// 	$mailer = WC()->mailer();

	// 	$mailer->send( get_option( "admin_email" ), $subject, $mailer->wrap_message( $title, $message ) );
	// }

	// /**
	//  * Get the Pagseguro plan for pre-approval of a customer
	//  *
	//  * @param string $period WooCommerce Subscriptions period
	//  * @param int $interval WooCommerce Subscriptions interval
	//  * @return string $plan Pagseguro Plan code for pre-approval of a customer
	//  */
	// public function get_pagseguro_plan( $period = "month", $interval = 1 ) {

	// 	$plan = "";

	// 	if ( "yes" === $this->homol ) {

	// 		switch($period) {
	// 			case "month":
	// 				if( 2 == $interval ) {
	// 					$plan = $this->homol_bimonthly_plan;
	// 				} else if( 3 == $interval ) {
	// 					$plan = $this->homol_trimonthly_plan;
	// 				} else if( 6 == $interval ) {
	// 					$plan = $this->homol_semiannually_plan;
	// 				} else {
	// 					$plan = $this->homol_monthly_plan;
	// 				}
	// 				break;
	// 			case "week":
	// 				$plan = $this->homol_weekly_plan;
	// 				break;
	// 			case "year":
	// 				$plan = $this->homol_yearly_plan;
	// 				break;
	// 		}

	// 	} else {

	// 		switch($period) {
	// 			case "month":
	// 				if( 2 == $interval ) {
	// 					$plan = $this->bimonthly_plan;
	// 				} else if( 3 == $interval ) {
	// 					$plan = $this->trimonthly_plan;
	// 				} else if( 6 == $interval ) {
	// 					$plan = $this->semiannually_plan;
	// 				} else {
	// 					$plan = $this->monthly_plan;
	// 				}
	// 				break;
	// 			case "week":
	// 				$plan = $this->weekly_plan;
	// 				break;
	// 			case "year":
	// 				$plan = $this->yearly_plan;
	// 				break;
	// 		}

	// 	}

	// 	if( "" == $plan ) {

	// 		$reponse = $this->api->create_manual_plan( $period, $interval );


	// 		if(isset( $reponse["code"] ) ) {

	// 			$option = "";

	// 			if ( "yes" === $this->homol ) {

	// 				switch($period) {
	// 					case "month":
	// 						if( 2 == $interval ) {
	// 							$option = "homol_bimonthly_plan";
	// 						} else if( 3 == $interval  ) {
	// 							$option = "homol_trimonthly_plan";
	// 						} else if( 6 == $interval  ) {
	// 							$option = "homol_semiannually_plan";
	// 						} else {
	// 							$option = "homol_monthly_plan";
	// 						}
	// 						break;
	// 					case "week":
	// 						$option = "homol_weekly_plan";
	// 						break;
	// 					case "year":
	// 						$option = "homol_yearly_plan";
	// 						break;
	// 				}

	// 			} else {

	// 				switch($period) {
	// 					case "month":
	// 						if( 2 == $interval ) {
	// 							$option = "bimonthly_plan";
	// 						} else if( 3 == $interval  ) {
	// 							$option = "trimonthly_plan";
	// 						} else if( 6 == $interval  ) {
	// 							$option = "semiannually_plan";
	// 						} else {
	// 							$option = "monthly_plan";
	// 						}
	// 						break;
	// 					case "week":
	// 						$option = "weekly_plan";
	// 						break;
	// 					case "year":
	// 						$option = "yearly_plan";
	// 						break;
	// 				}

	// 			}

	// 			if( "" != $option ) {
	// 				if( version_compare( WC_VERSION, "3.4.0", ">=") ) {
	// 					$this->update_option( $option, $reponse["code"] );
	// 				} else {
	// 					$this->settings[ $option ] = $reponse["code"];
	// 					update_option( $this->get_option_key(), apply_filters( "woocommerce_settings_api_sanitized_fields_" . $this->id, $this->settings ), "yes" );
	// 				}
	// 			}

	// 			return $reponse["code"];

	// 		}

	// 	}

	// 	return $plan;

	// }

	// /**
	//  * Get the corresponding period identifier in Pagseguro.
	//  *
	//  * @param string $period WooCommerce Subscriptions period
	//  * @param int $interval WooCommerce Subscriptions interval
	//  * @return string
	//  */
	// public function get_pagseguro_period( $period = "month", $interval = 1 ) {

	// 	$interval = intval( $interval );

	// 	switch( $period ) {
	// 		case "month" :
	// 			if( 2 == $interval ) {
	// 				$period = "bimonthly";
	// 			} else if( 3 == $interval  ) {
	// 				$period = "trimonthly";
	// 			} else if( 6 == $interval  ) {
	// 				$period = "semiannually";
	// 			} else {
	// 				$period = "monthly";
	// 			}
	// 			break;
	// 		case "year" :
	// 			$period = "yearly";
	// 			break;
	// 		case "week" :
	// 			$period = "weekly";
	// 			break;
	// 		default:
	// 			$period = "monthly";

	// 	}

	// 	return strtoupper( $period );
	// }

	// /**
	//  * Get label for Pagseguro period identifier
	//  *
	//  * @param string $pagseguro_period Pagseguro pediod identifier
	//  * @return string
	//  */
	// public function get_pagseguro_period_label( $pagseguro_period = "MONTLHY" ) {
	// 	// Register for translation purposes
	// 	$array = array(
	// 		"WEEKLY" 		=> __("WEEKLY", "triibo_assinaturas" ),
	// 		"MONTHLY" 		=> __("MONTHLY", "triibo_assinaturas" ),
	// 		"BIMONTHLY" 	=> __("BIMONTHLY", "triibo_assinaturas" ),
	// 		"TRIMONTHLY" 	=> __("TRIMONTHLY", "triibo_assinaturas" ),
	// 		"SEMIANNUALLY" 	=> __("SEMIANNUALLY", "triibo_assinaturas" ),
	// 		"YEARLY" 		=> __("YEARLY", "triibo_assinaturas" )
	// 	);

	// 	if( isset( $array[ $pagseguro_period ] ) ) {
	// 		$pagseguro_period = $array[ $pagseguro_period ];
	// 	}

	// 	return $pagseguro_period;
	// }

	// /**
	//  * Include the payment meta data required to process automatic recurring payments so that store managers can
	//  * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions v2.0+.
	//  *
	//  * @param array $payment_meta associative array of meta data required for automatic payments
	//  * @param WC_Subscription $subscription An instance of a subscription object
	//  * @return array
	//  */
	// public function add_subscription_payment_meta( $payment_meta, $subscription ) {
 	// 	$payment_meta[ $this->id ] = array(
	// 		"post_meta" => array(
	// 			"_pagseguro_assinatura" => array(
	// 				"value" => get_post_meta( $subscription->get_id(), "_pagseguro_assinatura", true ),
	// 				"label" => "Código do Cliente - PagSeguro Assinatura ",
	// 			),
	// 		),
	// 	);
 	// 	return $payment_meta;
	// }

	// /**
	//  * Validate the payment meta data required to process automatic recurring payments so that store managers can.
	//  * manually set up automatic recurring payments for a customer via the Edit Subscription screen in Subscriptions 2.0+.
	//  *
	//  * @param  string $payment_method_id The ID of the payment method to validate
	//  * @param  array  $payment_meta associative array of meta data required for automatic payments
	//  * @throws Exception
	//  */
	// public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
	// 	if ( $this->id === $payment_method_id ) {
	// 		if ( ! isset( $payment_meta["post_meta"]["_pagseguro_assinatura"]["value"] ) || empty( $payment_meta["post_meta"]["_pagseguro_assinatura"]["value"] ) ) {
	// 			throw new Exception( "A "_pagseguro_assinatura" value is required." );
	// 		}
	// 	}
	// }

	// /**
	//  * Saves sender hash for later use.
	//  *
	//  * @param int $order_id
	//  * @param string $hash Sender hash generated with Javascript on front end.
	//  * @return null
	//  */
	// protected function save_sender_hash( $order_id, $hash ) {

	// 	update_post_meta( $order_id, "_sender_hash", sanitize_text_field( $hash ) );

	// 	if ( wcs_order_contains_subscription( $order_id ) ) {
	// 		$subscriptions = wcs_get_subscriptions_for_order( $order_id );
	// 	} else if ( wcs_order_contains_renewal( $order_id ) ) {
	// 		$subscriptions = wcs_get_subscriptions_for_renewal_order( $order_id );
	// 	} else {
	// 		$subscriptions = array();
	// 	}

	// 	foreach( $subscriptions as $subscription ) {
	// 		update_post_meta( $subscription->get_id(), "_sender_hash", $hash );
	// 	}
	// 	return;

	// }


	// /**
	//  * Update the _pagseguro_assinatura for a subscription after using Pagseguro Recorrente to complete a payment to make up for.
	//  * an automatic renewal payment which previously failed.
	//  *
	//  * @param WC_Subscription $subscription The subscription for which the failing payment method relates.
	//  * @param WC_Order        $renewal_order The order which recorded the successful payment (to make up for the failed automatic payment).
	//  */
	// public function update_failing_payment_method( $subscription, $renewal_order ) {
	// 	update_post_meta( $subscription->get_id(), "_pagseguro_assinatura", get_post_meta( $renewal_order->get_id(), "_pagseguro_assinatura", true ) );
	// 	update_post_meta( $subscription->get_id(), "_pagseguro_tracker", get_post_meta( $renewal_order->get_id(), "_pagseguro_tracker", true ) );
	// }


	/**
	 * Payment Fields Hook.
	 */
	public function payment_fields()
	{
		if ( ( class_exists( "WC_Subscriptions_Cart" ) && WC_Subscriptions_Cart::cart_contains_subscription() )
			|| wcs_cart_contains_resubscribe()
			|| wcs_cart_contains_renewal()
			|| WC_Subscriptions_Change_Payment_Gateway::$is_request_to_change_payment )
		{
			$description = ( "yes" == $this->homol ) ? $this->get_description() . __( " - HOMOL ativado.", "triibo_assinaturas" ) : $this->get_description();

			if ( $description ) echo wpautop( wptexturize( trim( $description ) ) );

			parent::payment_fields();
		}
	}

	// /**
	//  *
	//  * For the recurring payment method to be changeable, the subscription must be active, have future (automatic) payments
	//  * and use a payment gateway which allows the subscription to be cancelled.
	//  *
	//  * @param bool            $subscription_can_be_changed Flag of whether the subscription can be changed.
	//  * @param WC_Subscription $subscription The subscription to check.
	//  * @return bool Flag indicating whether the subscription payment method can be updated.
	//  */
	// function can_subscription_be_updated_to_new_payment_method($subscription_can_be_changed, $subscription) {

	// 	if( $this->id == $subscription->get_payment_method() ) {

	// 		if( $subscription->has_status( array( "active", "on-hold" ) ) ) {
	// 			return true;
	// 		}
	// 	}

	// 	return $subscription_can_be_changed;
	// }

	// /**
	//  *
	//  * Return false because pagseguro does not support multiple subscriptions
	//  *
	//  * @param bool            $subscription_can_be_changed Flag of whether the subscription can be changed.
	//  * @param string $gateway_id The gateway id.
	//  * @param WC_Subscription $subscription The subscription to check.
	//  * @return bool Flag indicating whether the other subscriptions can be updated.
	//  */
	// function can_update_all_subscriptions( $can_update, $gateway_id, $subscription ) {

	// 	if( $this->id == $gateway_id ) {
	// 		return false;
	// 	}

	// 	return $can_update;
	// }

}
