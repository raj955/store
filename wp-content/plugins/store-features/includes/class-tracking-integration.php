<?php
/**
 * WooCommerce → Settings → Integration → Tracking.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * IDs for Google Analytics 4, Microsoft Clarity and the Meta Pixel.
 */
class Store_Features_Tracking_Integration extends WC_Integration {

	/**
	 * Set up the settings screen.
	 */
	public function __construct() {
		$this->id                 = 'store_features_tracking';
		$this->method_title       = __( 'Tracking', 'store-features' );
		$this->method_description = __( 'See who visits, what they look at, where they get stuck and what they buy. Each service turns on as soon as its ID is filled in. Logged-in shop staff are never tracked.', 'store-features' );

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Fields.
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'ga4_id'     => array(
				'title'       => __( 'Google Analytics 4 Measurement ID', 'store-features' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'G-XXXXXXXXXX',
				'description' => __( 'Visitors, where they come from, product views, add to cart, checkout and sales. Google Analytics → Admin → Data streams → Web.', 'store-features' ),
			),
			'clarity_id' => array(
				'title'       => __( 'Microsoft Clarity project ID', 'store-features' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => 'abcd1234ef',
				'description' => __( 'Free heatmaps and recordings of real visits, to see where people get confused. Clarity → Settings → Overview → Project ID.', 'store-features' ),
			),
			'pixel_id'   => array(
				'title'       => __( 'Meta Pixel ID', 'store-features' ),
				'type'        => 'text',
				'default'     => '',
				'placeholder' => '123456789012345',
				'description' => __( 'For Instagram and Facebook ads. Meta Events Manager → Data sources → your pixel.', 'store-features' ),
			),
		);
	}
}
