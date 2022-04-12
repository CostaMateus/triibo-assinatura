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


	protected $token      = null;
	protected $date_token = null;

	public function __construct( $gateway = null )
	{
		$this->gateway = $gateway;

		$this->generate_token();

		register_rest_route(
			$this->namespace,
			"/brand/(?P<cardNumber>[0-9]{4}-[0-9]{4}-[0-9]{4}-[0-9]{4}+)",
			[
				"methods"  => WP_REST_Server::READABLE, // GET
				"callback" => [ $this, "get_brand" ],
				"args"     => [
					"cardNumber" => [
						"required" => true,
						"type"     => "string",
					]
				],
			]
		);

		register_rest_route(
			$this->namespace,
			"/token",
			[
				"methods"  => WP_REST_Server::CREATABLE, // POST
				"callback" => [ $this, "create_token" ],
				"args"     => [
					"holder"         => [
						"required" => true,
						"type"     => "string",
					],
					"cardNumber"     => [
						"required" => true,
						"type"     => "string",
					],
					"expirationDate" => [
						"required" => true,
						"type"     => "string",
					],
					"securityCode"   => [
						"required" => true,
						"type"     => "string",
					],
					"brand"          => [
						"required" => true,
						"type"     => "string",
					],
				],
			]
		);

	}

	/**
	 * Retorna parte da URL de acordo com o ambiente
	 *
	 * @param boolean $mkt
	 * @return string
	 */
	protected function get_env( $mkt = false )
	{
		if ( $mkt )
			return ( $this->gateway->homol == "yes" ) ? "dev-" : "";
		else
			return ( $this->gateway->homol == "yes" ) ? "-hml" : "";
	}

	/**
	 * Retorna URL da api Triibo Marketplace
	 *
	 * @return string
	 */
	public function get_url()
	{
		return "https://" . $this->get_env( true ) . "marketplace.triibo.com.br";
	}

	/**
	 * Retorna URL da api Triibo Node
	 *
	 * @return void
	 */
	public function get_api_url()
	{
		return "https://api" . $this->get_env() . ".triibo.com.br";
	}

	private function exec($api, $params)
    {
        $curl   = curl_init();

		$url    = $this->get_api_url() . "/payments/" . $api;
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
			CURLOPT_POSTFIELDS     => json_encode( $params ),
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

		$resp = curl_exec($curl);

		curl_close($curl);

		$resp = json_decode( $resp, TRUE );

		if ( $resp["success"] )
		{
			$this->token      = $resp["token"];
			$this->date_token = new DateTime();
		}
		else
		{
			$this->generate_token();
		}
	}

	/**
	 * Renova token causo expirado
	 *
	 * @return void
	 */
	private function renew_token()
	{
		$now  = new DateTime();
		$diff = $now->diff($this->date_token);

		if ( $diff->h > 10) $this->generate_token();
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

		$params = [
			"payload" => [
				"gateway"    => $this->gateway->service,
				"cardNumber" => $data["cardNumber"],
			]
		];

		$resp   = $this->exec("brand", $params);

		$params["payload"]["cardNumber"] = "****-****-****-" . substr( $data["cardNumber"], -4 );

		$this->gateway->log->add( $this->gateway->id, "Checking brand" . json_encode( [ $params, $resp ] ) );

		return $resp;
	}

	/**
	 * Tokeniza um cartão
	 *
	 * @param WP_REST_Request $request
	 * @return array
	 */
	public function create_token( WP_REST_Request $request )
	{
		$holder       = $request->get_param( "holder" );
		$cardNumber   = $request->get_param( "cardNumber" );
		$securityCode = $request->get_param( "securityCode" );
		$brand        = $request->get_param( "brand" );

		return $request->get_params();
	}

}
