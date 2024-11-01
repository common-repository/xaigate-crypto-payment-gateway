<?php
/*
 * Plugin Name: XaiGate Crypto Payment Gateway
 * Description: Accept crypto payments for your online store with XaiGate's automated solution.
 * Author URI:  https://www.xaigate.com/
 * Author: XaiGate
 * Copyright: 2023 XaiGate.com
 * Version: 2.1.4
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: xaigate-crypto-payment-gateway-for-woocommerce
*/

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly 
add_action('plugins_loaded', 'xaigate_init_woo', 0);

function xaigate_init_woo() {

	if (!class_exists('WC_Payment_Gateway')) return;

	class Xaigate_WC_Gateway extends WC_Payment_Gateway {
		
		function __construct() {
			$this->id = 'xaigate';
			$this->method_title = "XaiGate Payment System";
			$this->method_description = "Adds the ability to accept payments via the XaiGate payment system to WooCommerce";

			$this->init_form_fields();
			$this->init_settings();

			$this->title = $this->get_option('title');
			$this->description = $this->get_option('description');

			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=') ) {
				add_action('woocommerce_update_options_payment_gateways_' . $this->id, array(&$this, 'process_admin_options'));
			} else {
				add_action('woocommerce_update_options_payment_gateways', array(&$this, 'process_admin_options'));
			}

			add_action('woocommerce_api_wc_gateway_xaigate', array(&$this, 'callback'));
		}

		function init_form_fields() {
			$this->form_fields = array(
				'enabled' => array(
					'title' => __('Enable/Disable', 'woocommerce'),
					'type' => 'checkbox',
					'default' => 'yes',
				),
				'title' => array(
					'title' => "Name",
					'type' => 'text',
					'description' => "The name that the user sees during the payment",
					'default' => "Pay with USDT, BTC, LTC, ETH, XMR, XRP, BCH and other cryptocurrencies. Powered by XaiGate",
					'desc_tip' => true,
				),
				'description' => array(
					'title' => "Description",
					'type' => 'text',
					'description' => "The description that the user sees during the payment",
					'default' => "Cryptocurrencies via XaiGate (more than 500 supported)",
					'desc_tip' => true,
				),
				'apikey' => array(
					'title' => 'API KEY',
					'type' => 'text',
					'description' => 
					sprintf(
						__( 'You can manage your API keys within the XaiGate Payment Gateway Settings page, available here: <a target="_blank" href="https://wallet.xaigate.com/merchant/credential">https://wallet.xaigate.com/merchant/credential</a>'),
					)
				),
				'shop_name' => array(
					'title' => __('SHOP Name','xaigate'),
					'type' => 'text',
				),
			);
		}
	
		function process_payment($order_id) {
			global $woocommerce;

			$order = new WC_Order($order_id);

			$amount = $order->get_total();
			$amount = str_replace(',', '.', $amount);
			$amount = number_format($amount, 2, '.', '');

			$email=$order->get_billing_email();

			$shop_name = $this->get_option("shop_name");
			$apikey = $this->get_option("apikey");

			$currency = get_woocommerce_currency();

			$description = array();
			foreach ( $order->get_items() as $item ) {
				$description[] = $item['qty'] . ' × ' . $item['name'];
			}

			$data_request = [
				'shopName'	=> $shop_name,
				'amount'	=> $amount,
				'currency'	=> $currency,
				'orderId'	=> $order_id,
				'email'		=> $email,
				'apiKey'	=> $apikey,
				'notifyUrl'     => trailingslashit( get_bloginfo( 'wpurl' ) ) . 'wc-api/wc_gateway_xaigate/'.$order->get_id(),
                'successUrl'    => add_query_arg( 'wc_gateway_xaigate', $order->get_id(), add_query_arg( 'key', $order->get_order_key(), $this->get_return_url( $order ) ) ),
				'description'	=> implode( ', ', $description )
			];
		
			$headers = array(
        		'Content-Type' => 'application/json; charset=utf-8',
			);

			$args = array(
				'body'        => wp_json_encode((object)$data_request),
				'timeout'     => '60',
				'httpversion' => '1.0',
				'headers'     => $headers
			);
			$response = wp_remote_post('https://wallet-api.xaigate.com/api/v1/invoice/create', $args);
			$json_data = wp_remote_retrieve_body($response);
			$json_data = json_decode($json_data, true);
			$url = $json_data['payUrl'];
			$status= $json_data['status'];
			if($status=='Pending'){
				$woocommerce->cart->empty_cart();
			}
			
			return array('result' => 'success', 'redirect' => $url);
		}

		public function callback() {
			if ( ! isset( $_POST['orderId'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash ( $_POST['orderId'] ) ) , 'orderId' ) );
			$order_id = filter_input(INPUT_POST, 'orderId', FILTER_SANITIZE_NUMBER_INT);

			if($order_id) {
				$order = new WC_Order($order_id);
				$amount = $order->get_total();
				$amount = str_replace(',', '.', $amount);
				$amount = number_format($amount, 2, '.', '');
		
				$order->update_status( 'completed');
				$order->payment_complete();
			}
			
			exit;
		}
	}
}

function xaigate_add_woo($methods) {
	$methods[] = 'Xaigate_WC_Gateway'; 
	return $methods;
}

add_filter('woocommerce_payment_gateways', 'xaigate_add_woo');