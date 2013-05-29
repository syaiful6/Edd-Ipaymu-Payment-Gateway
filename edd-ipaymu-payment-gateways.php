<?php
/*
Plugin Name: EDD iPaymu Payment Gateway
Plugin URI: http://www.pengunjungblog.com/plugins/edd-ipaymu/
Description: Accept payments through iPaymu for your Digital Store powered Easy Digital Downloads, a payment gateway for Indonesia.
Version: 1.0.0
Author: Syaiful Bahri
Author URI: http://www.pengunjungblog.com
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
/*  Copyright 2013  Syaiful Bahri  ( email : syaiful@pengunjungblog.com )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

//check the existensi of our class and Easy_Digital_Downloads,
if( ! class_exists( 'EDD_Ipaymu' ) ) :

class EDD_Ipaymu {
  
	/**
	 * @var instance The one true EDD_Ipaymu class
	 * singleton
	 */
	private static $instance;
	
	// Our file of this plugin
	public $file;
	
	// Our plugin path
	public $plugin_path;
	
	// Our plugin url
	public $plugin_url;
	
	// the version of this plugin
	public $version;
	
	/**
	 * Main EDD_Ipaymu Instance
	 * singleton implementation
	 */
	public static function instance() {
		if ( ! isset( self::$instance ) ) {
			self::$instance = new EDD_Ipaymu(__FILE__);
		}
		return self::$instance;
	}
	/**
	 * Constructor
	 * @since  1.0.0
	 * @return  void
	 */
	private function __construct( $file ) {
		
		//set up our data
		$this->version = '1.0.0';
		$this->file = $file;
		$this->plugin_url = trailingslashit( plugins_url( '', $plugin = $file ) );
		$this->plugin_path = trailingslashit( dirname( $file ) );
		
		//hooks for admin
		if( is_admin() ) 
			add_filter( 'edd_settings_gateways', array( &$this, 'add_settings_gateways' ) );
		
		// hooks
		add_filter( 'edd_currencies', array( &$this, 'rupiah_currencies' )); //add Indonesian currency, Rp or IDR
		add_filter( 'edd_accepted_payment_icons', array( &$this, 'ipaymu_payment_icon' ) );
		add_filter( 'edd_payment_gateways', array( &$this, 'register_gateway' ) );
		add_action( 'edd_ipaymu_cc_form', array( &$this, 'gateway_cc_form' ) );
        add_action( 'edd_gateway_ipaymu', array( &$this, 'process_payment' ) );
		add_action( 'init', array( &$this, 'validate_report_back' ) ); // trying to get notify fron ipaymu
		add_action( 'edd_ipaymu_check', array( &$this, 'process_ipaymu_notify' ) );
		
	} //end __construct
	
	/*	in order to disable credit card form that registered by Easy Digital Downloads by default. I return to blank value
	*	on other words, we just registered that. we don't need that for our gateway..
	*/
	public static function gateway_cc_form() {
        return;
	} //end gateway_cc_form()
	
	/*
	*	add our icon on checkout page
	*	
	*	@return void
	*	@access public
	*	
	*/
	
	public function ipaymu_payment_icon( $icons ) {
		$icons[$this->plugin_url . 'assets/images/ipaymu_icon.png'] = 'Ipaymu';
		return $icons;
	} //end ipaymu_payment_icon()
	
	/* 
	*	add our currency, sadly Indonesian Rupiah ( IDR ) not support on the core of Easy Digital Downloads
	*	but the plugin allow to filter that function
	*
	*	I don't know why other people called our currency is IDR, but we familiar with Rp as our currency
	*	because of that, I set both here...
	*/
	function rupiah_currencies( $currencies ) {
		
		$currencies['Rp'] = __('Indonesian Rupiah ( Rp )', 'edd_ipaymu');
		$currencies['IDR'] = __( 'Indonesian Rupiah Formal ( IDR )', 'edd_ipaymu' );
 
		return $currencies;
		
	} //end rupiah_currencies()
	
	// Remote get and retrieve respon body..
    private function remote_get( $url, $headers = array() ) {
			
		$response = wp_remote_get( $url,
			array(
				'redirection' => 1,
				'httpversion' => '1.1',
				'user-agent'  => 'EDD Ipaymu' . $this->version . '; WordPress (' . home_url( '/' ) . ')',
				'timeout'     => 15,
				'headers'     => $headers
			)
		);

		if ( !is_wp_error( $response ) && $response['response']['code'] == 200 ) {
			return $response['body'];
		} else {
			return false;
		}
	} //end remote_get()
	
	// Lets register our gateway, we can use $gateways object because this run during hooks
	public function register_gateway( $gateways ) {
	
		$gateways['ipaymu'] = array( 'admin_label' => __( 'Ipaymu', 'edd_ipaymu' ), 'checkout_label' => __('Ipaymu', 'edd_ipaymu'));
		
		return $gateways;
	}
	
	/*
	* Easy Digital Downloads have settings method that allow other developer filter that to add additional settings
	* So because this plugin is an extension/add on for EDD, we don't need create settings from scratch or create
	* tradional setting on Wordpress, beautifull..
	*
	* @access public
	* @return void
	* @since 0.0.1
	*/
	public function add_settings_gateways( $settings ) {
		
		$edd_ipaymu_settings = array(
		
		array(
			'id' => '_edd_ipaymu_gateway_settings',
			'name' => '<strong>' . __('Ipaymu Gateway Settings', 'edd_ipaymu') . '</strong>',
			'desc' => __('Configure the gateway settings', 'edd_ipaymu'),
			'type' => 'header'
		),
		array(
			'id' => 'edd_ipaymu_api_key',
			'name' => __('API Key', 'pw_edd'),
			'desc' => __('Enter your Ipaymu API key, if you don\'t have please get <a href=https://my.ipaymu.com?rid=Syaiful6 target=_blank>here</a>.', 'edd_ipaymu'),
			'type' => 'text',
			'size' => 'regular'
		), 
	);
 
		return array_merge( $settings, $edd_ipaymu_settings );
	} //end add_settings_gateways()
	
	public function process_payment( $purchase_data ) {
		
		global $edd_options;
		
		// Check there is a gateway name
		if ( ! isset( $purchase_data['post_data']['edd-gateway'] ) )
    	return;
		
		$errors = edd_get_errors();
		
		if( !$errors ) {
		
			$payment_data = array( 
				'price' => $purchase_data['price'], 
				'date' => $purchase_data['date'], 
				'user_email' => $purchase_data['user_email'],
				'purchase_key' => $purchase_data['purchase_key'],
				'currency' => $edd_options['currency'],
				'downloads' => $purchase_data['downloads'],
				'user_info' => $purchase_data['user_info'],
				'cart_details' => $purchase_data['cart_details'],
				'status' => 'pending'
			);

			// record the pending payment
			$payment = edd_insert_payment( $payment_data );
			
			if ( ! $payment ) {
				// Record the error
				edd_record_gateway_error( __( 'Payment Error', 'edd_ipaymu' ), sprintf( __( 'Payment creation failed before sending buyer to Ipaymu. Payment data: %s', 'edd_ipaymu' ), json_encode( $payment_data ) ), $payment );
				// Problems? send back
				edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );
			
			} else {
			
				$return_url = add_query_arg( 'payment-confirmation', 'ipaymu', get_permalink( $edd_options['success_page'] ) );
				$listener_url = trailingslashit( home_url() ).'?ipaymu=notify';
				$cancel_url = add_query_arg( 'payment-cancel', 'ipaymu', edd_get_failed_transaction_uri() );
				
				$summary = edd_get_purchase_summary( $purchase_data, false );
				$quantity = edd_get_cart_quantity();
				if ( is_ssl() || ! $ssl_check ) {
					$protocal = 'https://';
				} else {
					$protocal = 'http://';
				}
				
				if( edd_is_test_mode() ) {
					$url_to_send = $protocal . 'my.ipaymu.com/payment.htm/?';
				} else {
					$url_to_send = $protocal . 'my.ipaymu.com/payment.htm/?';
				}
			
				$ipaymu_args = array(
					'key'      => $edd_options['edd_ipaymu_api_key'], // API Key Merchant / Penjual
					'action'   => 'payment',
					'product'  => stripslashes_deep( html_entity_decode( wp_strip_all_tags( $summary ), ENT_COMPAT, 'UTF-8' ) ),
					'price'    => round( $purchase_data['price'] - $purchase_data['tax'], 2 ), //
					'quantity' => $quantity,
					'comments' => $payment, // Optional for Ipaymu, but this is the payment ID, we need it to verify payment
					'ureturn'  => $return_url,
					'unotify'  => $listener_url,
					'ucancel'  => $cancel_url, //if cancel back to check out
					'format'   => 'json' // Format: xml / json. Default: xml
				);
			
				//var_dump( add_query_arg( $ipaymu_args, $url_to_send ) ); exit;
				$url_to_send .= http_build_query( $ipaymu_args );
				//remote get and retrieve a session id from ipaymu
                $response = $this->remote_get( $url_to_send );
				
				if ( $response == null || $response == 'null' )
					return false;
					
				$ipaymu = json_decode( $response );
				//get the session from ipaymu, we are ready to send the buyer
				$session_id = $ipaymu->sessionID;
				
				//save to database first, we will need it
				update_post_meta( $payment, '_ipaymu_session_id', $session_id );

				//get rid of cart contents
				edd_empty_cart();
				
				//build an query againt, but we just need session id
				$params = array( 'sessionID'	=> $session_id,  );	
				$redirecting_to = add_query_arg( $params, 'https://my.ipaymu.com/payment.htm/' );
				//redirect to ipaymu
				wp_redirect( $redirecting_to );
				exit;	
			
			} //end statement payment 
			
			
		} //end statement error
		else {
			$fail = true; // errors were detected
		} //end statement
		
		if( $fail !== false ) {
			// if errors are present, send the user back to the purchase page so they can be corrected
			edd_send_back_to_checkout('?payment-mode=' . $purchase_data['post_data']['edd-gateway']);
		} //end statement
		
	} //end process_payment()
	
	public function validate_report_back() {
		global $edd_options;

		// Regular Ipaymu notify
		if ( isset( $_GET['ipaymu'] ) && $_GET['ipaymu'] == 'notify' ) {
			do_action( 'edd_ipaymu_check' );
		} //end statement
	} //end validate_report_back()
	
	/**
	 * Extract the site's host domain for referer notify from ipaymu.
	 *
	 * @since 1.0
	 * @param string $url URL to extract
	 * @return host of url that given on param, or false if failed extract url
	 * @access private
	 */
	private static function check_referer_notify( $url ) {
	
		if ( ! ( is_string( $url ) && $url ) )
			return false;
			
		if ( ! function_exists('parse_url') )
			return false;
			
		// PHP 5.3.3 or newer can throw a warning
		try {
			if ( version_compare( PHP_VERSION, '5.1.2', '>=') ) {
				$ref = parse_url ( $url, PHP_URL_HOST );
			} else {
				$parse_ref = parse_url( $url );
				if ( $parse_ref !== false && isset( $parse_ref['host'] ) )
					$ref	= $parse_ref['host'];
			}
		} catch (Exception $e){}
		
		// Check $ref is not empty or null, is so return that.
		if( empty( $ref ) || $ref = null )
			return false;
		else 
			return $ref;
	}
	
	/* 
	* process_ipaymu_notify() function
	* process notify that send by ipaymu after we send the buyer on payment
	*
	* if buyer complete the payment, we need update the payment to complete too
	* @since 1.0
	*/
	public function process_ipaymu_notify() {
	
		global $edd_options;

		// Check the request method is POST
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] != 'POST' ) {
			return;
		} //end statement
		
			
		if ( isset( $_GET['status'] ) && isset( $_GET['trx_id'] ) && isset( $_GET['sid'] ) && isset( $_GET['product'] ) && isset( $_GET['quantity'] ) && isset( $_GET['total'] ) && isset( $_GET['comments'] ) ) {
									
			// setup each of the variables from iPaymu
			$payment_id = $_GET['comments'] ? $_GET['comments'] : null;
			$ipaymu_status = strtolower( $_GET['status'] ) ? strtolower( $_GET['status'] ) : null;
			$ipaymu_session = $_GET['sid'] ? $_GET['sid'] : null;
			$ipaymu_product = $_GET['product'] ? $_GET['product'] : null;
			$ipaymu_quantity = $_GET['quantity'] ? $_GET['quantity'] : null;
			$ipaymu_amount = $_GET['total'] ? $_GET['total'] : null;
			
			// retrieve the meta info for this payment
			$payment_meta 		= get_post_meta( $payment_id, '_edd_payment_meta', true );
			$payment_session	= get_post_meta( $payment_id, '_ipaymu_session_id', true );
			$payment_amount 	= edd_format_amount( $payment_meta['amount'] );
			
			
			// check url referrer..
			if( ! function_exists( 'wp_get_referer' ) )
				include_once( ABSPATH . 'wp-includes/functions.php' );
			$referer = wp_get_referer();
			if( empty( $referer ) )
				return;
			
			$ref = $this->check_referer_notify( $referer );			
			if ( $ref != 'my.ipaymu.com' )
				return; // referrer from ipaymu? if not return that.
			
			if( get_post_status( $payment_id ) == 'complete' )
				return; // Only complete payments once
			
			if ( edd_get_payment_gateway( $payment_id ) != 'ipaymu' )
				return; // this isn't from ipaymu	
			
			if( $ipaymu_amount != $payment_amount )
				return; // the prices don't match
			
			//	check the session id, this session is an unique key. generating by ipaymu
			// if this don't match, this is not from ipaymu or other payment
			if( $ipaymu_session != $payment_session ) 
				return;
								
			/* everything has been verified, update the payment to "complete"
			*  berhasil is Indonesian language that mean success.
			*/
			if( $ipaymu_status = 'berhasil' )
				edd_update_payment_status( $payment_id, 'publish' );
			
		}//end statement
		
	} //end process_ipaymu_notify()
	
	
} // end EDD_Ipaymu Class
endif; // end check

/**
 * Throw an error if Easy Digital Download is not installed.
 *
 * @since 0.2
 */
function syaiful_ipaymu_missing_error_edd() {
	echo '<div class="error"><p>' . sprintf( __( 'Please %sinstall &amp; activate Easy Digital Downloads%s to allow this plugin to work.' ), '<a href="' . admin_url( 'plugin-install.php?tab=search&type=term&s=easy+digital+downloads&plugin-search-input=Search+Plugins' ) . '">', '</a>' ) . '</p></div>';
} // end syaiful_ipaymu_missing_error_edd()

// Throw an error if Wordpress version is below 3.4
function syaiful_missing_error_wordpress_version() {
	echo '<div class="error"><p>' . __( 'Please upgrade WordPress to the latest version to allow WordPress and this plugin to work properly.', 'edd_ipaymu' ) . '</p></div>';
} // end syaiful_missing_error_wordpress_version()

// the instance of our plugin,
function edd_ipaymu() {
	return EDD_Ipaymu::instance();
}

// Loader function for the plugin
function syaiful_edd_ipaymu_init() {
	global $wp_version;

	if ( !version_compare( $wp_version, '3.4', '>=' ) ) {
		add_action( 'all_admin_notices', 'syaiful_missing_error_wordpress_version' );
	} else if ( class_exists( 'Easy_Digital_Downloads') ) {
			edd_ipaymu(); //load our plugin
		} else {
			add_action( 'all_admin_notices', 'syaiful_ipaymu_missing_error_edd' );
		}
} // end syaiful_edd_ipaymu_init()

// tap... tap .... hi Wordpress, load our plugin please...
add_action( 'plugins_loaded', 'syaiful_edd_ipaymu_init', 20 ); //lower because waiting Easy Digital Downloads running..

 
