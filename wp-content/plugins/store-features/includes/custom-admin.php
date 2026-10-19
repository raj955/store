<?php
/**
 * WooCommerce → Custom designs: every order with a customer's own design in one place.
 *
 * Shows the design preview, the customer, what they ordered and where the order stands with
 * Qikink ("Waiting for you" until someone checks the design and sends it). The menu item shows how
 * many orders are waiting. Each row has Check, Print file and Send to Qikink.
 *
 * @package store-features
 */

defined( 'ABSPATH' ) || exit;

/**
 * Orders with a custom design.
 *
 * @param string $view waiting|sent|all.
 * @param int    $page Page number.
 * @return array{orders: WC_Order[], total: int, pages: int}
 */
function store_features_custom_admin_orders( $view = 'waiting', $page = 1 ) {
	$args = array(
		'limit'      => 20,
		'paged'      => max( 1, $page ),
		'paginate'   => true,
		'orderby'    => 'date',
		'order'      => 'DESC',
		'type'       => 'shop_order',
		'status'     => array_keys( wc_get_order_statuses() ),
		'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			array(
				'key'   => '_sf_has_custom',
				'value' => 'yes',
			),
		),
	);
	if ( 'waiting' === $view ) {
		$args['status']       = array( 'wc-processing', 'wc-on-hold' );
		$args['meta_query'][] = array(
			'key'     => '_sf_qikink_status',
			'value'   => array( 'review', 'error' ),
			'compare' => 'IN',
		);
	} elseif ( 'sent' === $view ) {
		$args['meta_query'][] = array(
			'key'   => '_sf_qikink_status',
			'value' => 'sent',
		);
	}
	$result = wc_get_orders( $args );
	return array(
		'orders' => $result->orders,
		'total'  => (int) $result->total,
		'pages'  => (int) $result->max_num_pages,
	);
}

/**
 * How many custom orders wait to be checked (cached for a minute).
 *
 * @return int
 */
function store_features_custom_waiting_count() {
	$count = get_transient( 'sf_custom_waiting' );
	if ( false === $count ) {
		$count = store_features_custom_admin_orders( 'waiting' )['total'];
		set_transient( 'sf_custom_waiting', $count, MINUTE_IN_SECONDS );
	}
	return (int) $count;
}

/**
 * Forget the cached count whenever an order changes.
 */
function store_features_custom_waiting_reset() {
	delete_transient( 'sf_custom_waiting' );
}
add_action( 'woocommerce_update_order', 'store_features_custom_waiting_reset' );
add_action( 'woocommerce_new_order', 'store_features_custom_waiting_reset' );

/**
 * Menu item, with the number of orders waiting.
 */
function store_features_custom_admin_menu() {
	$count = store_features_custom_waiting_count();
	$badge = $count ? ' <span class="awaiting-mod count-' . $count . '"><span class="pending-count">' . number_format_i18n( $count ) . '</span></span>' : '';
	add_submenu_page(
		'woocommerce',
		__( 'Custom designs', 'store-features' ),
		__( 'Custom designs', 'store-features' ) . $badge,
		'edit_shop_orders',
		'sf-custom-designs',
		'store_features_custom_admin_page'
	);
}
add_action( 'admin_menu', 'store_features_custom_admin_menu', 60 );

/**
 * "Send to Qikink" from the list.
 */
function store_features_custom_admin_send() {
	$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
	check_admin_referer( 'sf_custom_send_' . $order_id );
	if ( ! current_user_can( 'edit_shop_orders' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'store-features' ) );
	}
	$result = store_features_qikink_send( $order_id, true );
	store_features_custom_waiting_reset();
	$back = add_query_arg(
		array(
			'page'    => 'sf-custom-designs',
			'sf_sent' => is_wp_error( $result ) ? 'error' : 'ok',
			'sf_id'   => $order_id,
		),
		admin_url( 'admin.php' )
	);
	wp_safe_redirect( $back );
	exit;
}
add_action( 'admin_post_sf_custom_send', 'store_features_custom_admin_send' );

/**
 * The page.
 */
function store_features_custom_admin_page() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view switches.
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'waiting';
	$view = in_array( $view, array( 'waiting', 'sent', 'all' ), true ) ? $view : 'waiting';
	$page = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
	$sent = isset( $_GET['sf_sent'] ) ? sanitize_key( wp_unslash( $_GET['sf_sent'] ) ) : '';
	$sent_id = isset( $_GET['sf_id'] ) ? absint( $_GET['sf_id'] ) : 0;
	// phpcs:enable

	$data  = store_features_custom_admin_orders( $view, $page );
	$base  = admin_url( 'admin.php?page=sf-custom-designs' );
	$views = array(
		'waiting' => __( 'Waiting for you', 'store-features' ),
		'sent'    => __( 'Sent to Qikink', 'store-features' ),
		'all'     => __( 'All', 'store-features' ),
	);
	?>
	<div class="wrap sf-cd">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Custom designs', 'store-features' ); ?></h1>
		<p class="sf-cd__intro"><?php esc_html_e( 'Orders where the customer made their own design. Check each design (spelling, quality, nothing offensive or copied), then send it to Qikink. They are never sent automatically.', 'store-features' ); ?></p>

		<?php if ( 'ok' === $sent ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( /* translators: %d: order number. */ __( 'Order #%d sent to Qikink.', 'store-features' ), $sent_id ) ); ?></p></div>
		<?php elseif ( 'error' === $sent ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( sprintf( /* translators: %d: order number. */ __( 'Order #%d was not sent. Open the order to see why.', 'store-features' ), $sent_id ) ); ?></p></div>
		<?php endif; ?>

		<ul class="subsubsub">
			<?php
			$links = array();
			foreach ( $views as $key => $label ) {
				$count   = 'waiting' === $key ? ' <span class="count">(' . number_format_i18n( store_features_custom_waiting_count() ) . ')</span>' : '';
				$links[] = sprintf( '<li><a href="%s"%s>%s%s</a>', esc_url( add_query_arg( 'view', $key, $base ) ), $view === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ), $count );
			}
			echo implode( ' | </li>', $links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</ul>

		<table class="wp-list-table widefat fixed striped sf-cd__table">
			<thead>
				<tr>
					<th class="sf-cd__col-design"><?php esc_html_e( 'Design', 'store-features' ); ?></th>
					<th><?php esc_html_e( 'Order', 'store-features' ); ?></th>
					<th><?php esc_html_e( 'What they ordered', 'store-features' ); ?></th>
					<th><?php esc_html_e( 'Qikink', 'store-features' ); ?></th>
					<th class="sf-cd__col-actions"><?php esc_html_e( 'Actions', 'store-features' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $data['orders'] ) : ?>
				<tr><td colspan="5"><?php echo 'waiting' === $view ? esc_html__( 'Nothing waiting. New custom orders appear here.', 'store-features' ) : esc_html__( 'No orders yet.', 'store-features' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $data['orders'] as $order ) : ?>
				<?php
				$designs = array();
				$lines   = array();
				foreach ( $order->get_items() as $item ) {
					$design = store_features_custom_item_design( $item );
					if ( ! $design ) {
						continue;
					}
					$designs[] = $design;
					$product   = $item->get_product();
					$variant   = $product && $product->is_type( 'variation' ) ? wc_get_formatted_variation( $product, true, false ) : '';
					$lines[]   = sprintf(
						'<strong>%1$s</strong> × %2$d<br><span class="sf-cd__muted">%3$s</span><br>%4$s',
						esc_html( $item->get_name() ),
						(int) $item->get_quantity(),
						esc_html( $variant ),
						esc_html( (string) $item->get_meta( __( 'Design', 'store-features' ) ) )
					);
				}
				$status = (string) $order->get_meta( '_sf_qikink_status' );
				$labels = array(
					'review' => array( __( 'Waiting for you', 'store-features' ), 'waiting' ),
					'error'  => array( __( 'Not sent (error)', 'store-features' ), 'error' ),
					'sent'   => array( __( 'Sent', 'store-features' ), 'sent' ),
				);
				$label  = $labels[ $status ] ?? array( wc_get_order_status_name( $order->get_status() ), 'other' );
				if ( $order->has_status( array( 'cancelled', 'refunded', 'failed' ) ) ) {
					$label = array( wc_get_order_status_name( $order->get_status() ), 'other' );
				}
				$edit   = $order->get_edit_order_url();
				$send   = wp_nonce_url( admin_url( 'admin-post.php?action=sf_custom_send&order=' . $order->get_id() ), 'sf_custom_send_' . $order->get_id() );
				$ready  = in_array( $status, array( 'review', 'error' ), true ) && $order->has_status( array( 'processing', 'on-hold' ) );
				?>
				<tr>
					<td class="sf-cd__col-design">
						<?php foreach ( $designs as $design ) : ?>
							<?php if ( $design['mockup_url'] ) : ?>
								<a href="<?php echo esc_url( $design['mockup_url'] ); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url( $design['mockup_url'] ); ?>" alt="" width="88" loading="lazy"></a>
							<?php else : ?>
								<span class="sf-cd__missing"><?php esc_html_e( 'Files missing', 'store-features' ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</td>
					<td>
						<a href="<?php echo esc_url( $edit ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a><br>
						<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><br>
						<span class="sf-cd__muted"><?php echo esc_html( trim( $order->get_shipping_city() . ', ' . $order->get_shipping_state(), ', ' ) ); ?></span><br>
						<span class="sf-cd__muted"><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j M, g:i a' ) : '' ); ?> · <?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></span>
					</td>
					<td><?php echo implode( '<hr>', $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td>
						<span class="sf-cd__badge sf-cd__badge--<?php echo esc_attr( $label[1] ); ?>"><?php echo esc_html( $label[0] ); ?></span>
						<?php if ( 'sent' === $status ) : ?>
							<br><span class="sf-cd__muted"><?php echo esc_html( 'ID ' . $order->get_meta( '_sf_qikink_order_id' ) . ' (' . $order->get_meta( '_sf_qikink_mode' ) . ')' ); ?></span>
						<?php elseif ( 'error' === $status ) : ?>
							<br><span class="sf-cd__muted"><?php echo esc_html( (string) $order->get_meta( '_sf_qikink_error' ) ); ?></span>
						<?php endif; ?>
					</td>
					<td class="sf-cd__col-actions">
						<a class="button" href="<?php echo esc_url( $edit ); ?>"><?php esc_html_e( 'Check', 'store-features' ); ?></a>
						<?php foreach ( $designs as $design ) : ?>
							<?php if ( $design['print_url'] ) : ?>
								<a class="button" href="<?php echo esc_url( $design['print_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Print file', 'store-features' ); ?></a>
							<?php endif; ?>
						<?php endforeach; ?>
						<?php if ( $ready ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( $send ); ?>"><?php esc_html_e( 'Send to Qikink', 'store-features' ); ?></a>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $data['pages'] > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array(
						'base'    => add_query_arg( array( 'view' => $view, 'paged' => '%#%' ), $base ),
						'format'  => '',
						'current' => $page,
						'total'   => $data['pages'],
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	</div>
	<style>
		.sf-cd__intro { max-width: 760px; font-size: 14px; }
		.sf-cd__table td { vertical-align: top; }
		.sf-cd__col-design { width: 110px; }
		.sf-cd__col-design img { display: block; margin: 0 0 6px; border: 1px solid #dcdcde; border-radius: 4px; }
		.sf-cd__col-actions { width: 220px; }
		.sf-cd__col-actions .button { margin: 0 4px 6px 0; }
		.sf-cd__muted { color: #646970; }
		.sf-cd__missing { color: #b32d2e; }
		.sf-cd__badge { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f0f0f1; font-weight: 600; }
		.sf-cd__badge--waiting { background: #fcf0d6; color: #7a4e00; }
		.sf-cd__badge--error { background: #fbe7e9; color: #8a1f2b; }
		.sf-cd__badge--sent { background: #e2f3e8; color: #1d5e36; }
		.sf-cd__table hr { margin: 8px 0; border: 0; border-top: 1px solid #dcdcde; }
		@media (max-width: 782px) { .sf-cd__table thead { display: none; } .sf-cd__table td { display: block; width: auto !important; } }
	</style>
	<?php
}
