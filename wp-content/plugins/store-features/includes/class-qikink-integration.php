<?php
/**
 * WooCommerce → Settings → Integration → Qikink.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings for sending orders to Qikink.
 */
class Store_Features_Qikink_Integration extends WC_Integration {

	/**
	 * Set up the settings screen.
	 */
	public function __construct() {
		$this->id                 = 'store_features_qikink';
		$this->method_title       = __( 'Qikink', 'store-features' );
		$this->method_description = __( 'Send orders to Qikink for printing and delivery. Get the Client ID and Secret from Qikink → Integrations → Open API. Use Sandbox to test: sandbox orders are never printed. Each order also has "Send to Qikink" and "Check Qikink request" under Order actions.', 'store-features' );

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'mode'          => array(
				'title'   => __( 'Connection', 'store-features' ),
				'type'    => 'select',
				'default' => 'off',
				'options' => array(
					'off'     => __( 'Off (nothing is sent)', 'store-features' ),
					'sandbox' => __( 'Sandbox (test, nothing is printed)', 'store-features' ),
					'live'    => __( 'Live (real orders)', 'store-features' ),
				),
			),
			'client_id'     => array(
				'title'   => __( 'Client ID', 'store-features' ),
				'type'    => 'text',
				'default' => '',
			),
			'client_secret' => array(
				'title'   => __( 'Client Secret', 'store-features' ),
				'type'    => 'password',
				'default' => '',
			),
			'auto_send'     => array(
				'title'       => __( 'Send orders automatically', 'store-features' ),
				'label'       => __( 'Send each order to Qikink as soon as it is paid, or placed with cash on delivery', 'store-features' ),
				'type'        => 'checkbox',
				'default'     => 'no',
				'description' => __( 'Leave this off while testing. You can always send an order by hand from the order screen.', 'store-features' ),
			),
			'print_type_id' => array(
				'title'       => __( 'Print type ID', 'store-features' ),
				'type'        => 'text',
				'default'     => '1',
				'description' => __( 'Qikink print method. 1 is DTG (direct to garment), 17 is DTF.', 'store-features' ),
			),
		);
	}
}
