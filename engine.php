<?php
/*
 *Actual Address Checker(AC) engine
 *Check the correctness of address during woocommerce checkout
 *Use woocommerce checkout hooks 
*/

class AC_Engine {

	/**
	 * @var array
	 */
	protected $regions = array( 'provinces' => array(), 'locals' => array() );
	/**
	 * @var string
	 */
	protected $suggest = '';
	
	/**
	 * @var string
	 */
	protected $error = 0;
	
	/**
	 * AC_Engine Constructor.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		
		//Hooks into woocommerce action
		$options = get_option( 'acproof-options' );
		$workplace = $options['workplace'];
		add_action( 'woocommerce_new_order', array( $this, 'ac_new_order' ) );
		if( $workplace == 1 ) {
			add_action( 'woocommerce_checkout_process', array( $this, 'ac_checkout' ) );
		}
		add_action( 'woocommerce_delete_order_item', array( $this, 'ac_delete_order' ) );	//If an order is removed from woocommerce ACProof is in sync
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'ac_manage_order_column' ) );	//A trick to show address proof status on woocommerce orders page
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'ac_save_order' ), 9 );	//Update acproof_orders table whenever updating order info
	}
	
	//Used for backend workplace mode
	//Check shipping address before inserted into database
	public function ac_new_order( $order_id ) {
	
		$options = get_option( 'acproof-options' );
		$status = $options['status'];
		$type = $options['type'];
		
		$order = new WC_Order( $order_id );
		if( !in_array( $order->status, $status ) )
			return;
		
		global $woocommerce;
		
		$checkout_fields = $woocommerce->checkout->posted;
		
		$args = array();
		$args['detail'] = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_address_2'] : $checkout_fields[$type . '_address_2'];
		$args['street'] = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_address_1'] : $checkout_fields[$type . '_address_1'];
		$args['city'] = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_city'] : $checkout_fields[$type . '_city'];
		$args['postcode'] = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_postcode'] : $checkout_fields[$type . '_postcode'];
		$country = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_country'] : $checkout_fields[$type . '_country'];
		$state = ( $checkout_fields['shiptobilling'] > 0 ) ? $checkout_fields['billing_state'] : $checkout_fields[$type . '_state'];
		$args['province'] = $this->_getState( $country, $state );
		$args['country'] = $this->_getCountry( $country );
		$args['countrycode'] = $country;
		
		$this->_ac_update_order( $order_id, $args, 'insert' );		
		return true;
	}
	
	//Check acproof_orders table to validate unchecked orders
	public function processUnchecked() {
		
		if( !is_admin() )
			return;
					
		global $wpdb;
		//Check if the order status is not set in acproof_orders table
		$query = "SELECT `order_id` FROM {$wpdb->prefix}acproof_orders WHERE `status` = '-'";
		$orders = $wpdb->get_results( $query );
		
		$options = get_option( 'acproof-options' );
		$type = $options['type'];
		
		foreach( $orders as $order ) {
			$order_id = $order->order_id;
			$order = get_post( $order_id );
			if( !$order ) {
				$wpdb->query( "DELETE FROM {$wpdb->prefix}acproof_orders WHERE `order_id` = $order_id" );
			}
			else {
				$order_info = get_post_meta( $order_id );
				
				$args = array();
				$args['detail'] = $order_info['_' . $type . '_address_2'][0];
				$args['street'] = $order_info['_' . $type . '_address_1'][0];
				$args['city'] = $order_info['_' . $type . '_city'][0];
				$args['postcode'] = $order_info['_' . $type . '_postcode'][0];
				$args['province'] = $this->_getState( $order_info['_' . $type . '_country'][0], $order_info['_' . $type . '_state'][0] );
				$args['country'] = $this->_getCountry( $order_info['_' . $type . '_country'][0] );
				$args['countrycode'] = $order_info['_' . $type . '_country'][0];
				
				$this->_ac_update_order( $order_id, $args, 'update' );	
			}
		}
		return true;
	}
	
	//Check shipping address after editing and saving the order data
	public function ac_save_order( $order_id ) {
		
		$options = get_option( 'acproof-options' );
		$status = $options['status'];
		$type = $options['type'];
		$order = new WC_Order( $order_id );
		
		if( !in_array( $order->status, $status ) )
			return;
		
		$args = array();
		$args['detail'] = $_POST['_' . $type . '_address_2'];
		$args['street'] = $_POST['_' . $type . '_address_1'];
		$args['city'] = $_POST['_' . $type . '_city'];
		$args['postcode'] = $_POST['_' . $type . '_postcode'];
		$args['province'] = $this->_getState( $_POST['_' . $type . '_country'], $_POST['_' . $type . '_state'] );
		$args['country'] = $this->_getCountry( $_POST['_' . $type . '_country'] );
		$args['countrycode'] = $_POST['_' . $type . '_country'];
		
		$this->_ac_update_order( $order_id, $args, 'update' );	
	}
	
	//Modify column status according to shipping address correctness
	public function ac_manage_order_column( $column ) {
		
		if( $column != 'order_status' )
			return;	//Only modify order_status field
			
		$options = get_option( 'acproof-options' );
		$type = $options['type'];
		
		global $acproof;
		global $the_order, $wpdb;
		
		$query = "SELECT * FROM {$wpdb->prefix}acproof_orders WHERE order_id = {$the_order->id}";
		$order = $wpdb->get_row( $query );
		
		wp_register_style( 'acproof-css', $acproof->getPluginUrl() . 'css/acproof.css' );
		wp_enqueue_style( 'acproof-css' );
		
		switch( $order->status ) {
			case '-1':
				echo '<mark class="acproof unknown tips" data-tip="Cannot resolve this ' . $type . ' address"><span><u>AC</u></span></mark>';
				break;
			case '0':
				echo '<mark class="acproof correct tips" data-tip="Correct ' . $type . ' address, Well-formed: ' . $order->suggest . '"><span><i>AC</i></span></mark>';
				break;
			case '1':
				echo '<mark class="acproof country tips" data-tip="' . ucfirst( $type ) . ' Country is wrong, Suggestion: ' . $order->suggest . '"><strike><span><i>AC</i></strike></span></mark>';
				break;
			case '2':
				echo '<mark class="acproof postcode tips" data-tip="' . ucfirst( $type ) . ' Postcode is wrong, Suggestion: ' . $order->suggest . '"><strike><span><i>AC</i></span></strike></mark>';
				break;
			case '3':
				echo '<mark class="acproof province tips" data-tip="' . ucfirst( $type ) . ' State/County is wrong, Suggestion: ' . $order->suggest . '"><strike><span><i>AC</i></span></strike></mark>';
				break;
			case '4':
				echo '<mark class="acproof city tips" data-tip="' . ucfirst( $type ) . ' City is wrong, Suggestion: ' . $order->suggest . '"><strike><span><i>AC</i></span></strike></mark>';
				break;
			case '5':
				echo '<mark class="acproof street tips" data-tip="' . ucfirst( $type ) . ' Street address is wrong, Suggestion: ' . $order->suggest . '"><strike><span><i>AC</i></span></strike></mark>';
				break;
			default:
				echo '<mark class="acproof unchecked tips" data-tip="This ' . $type . ' address was not checked"><span>AC</span></mark>';
				break;
		}
	}
	
	//Use Google API for validating the shipping adress
	//Use woocommerce checkout process action
	public function ac_checkout() {
		$options = get_option( 'acproof-options' );
		$type = $options['type'];
		//Collect required fields for soap request
		$args = array();
		$args['detail'] = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_address_2'] : $_POST[$type . '_address_2'];
		$args['street'] = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_address_1'] : $_POST[$type . '_address_1'];
		$args['city'] = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_city'] : $_POST[$type . '_city'];
		$args['postcode'] = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_postcode'] : $_POST[$type . '_postcode'];
		$country = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_country'] : $_POST[$type . '_country'];
		$state = ( $_POST['shiptobilling'] > 0 ) ? $_POST['billing_state'] : $_POST[$type . '_state'];
		$args['province'] = $this->_getState( $country, $state );
		$args['country'] = $this->_getCountry( $country );
		$args['countrycode'] = $country;
		
		//Make Google api request
		$this->checkAddress( $args );
		
		//Error processing
		global $woocommerce;
		switch( $this->error ) {
			case -1:
				$woocommerce->add_error( 'Such ' . $type .' address does not exist' );
				break;
			case 1:
				$woocommerce->add_error( ucfirst( $type ) . ' Country is wrong' );
				break;
			case 2:
				$woocommerce->add_error( ucfirst( $type ) . ' Postcode is wrong' );
				break;
			case 3:
				$woocommerce->add_error( ucfirst( $type ) . ' State/County is wrong' );
				break;
			case 4:
				$woocommerce->add_error( ucfirst( $type ) . ' City is wrong' );
				break;
			case 5:
				$woocommerce->add_error( ucfirst( $type ) . ' Street address is wrong' );
				break;
			case 0:
			default:
				break;
		}
	}
	
	//Manages adding/editing orders of WooCommerce in accordance with ACProof orders
	private function _ac_update_order( $order_id, &$args, $command ) {
	
		$options = get_option( 'acproof-options' );
		$status = $options['status'];
		$order = new WC_Order( $order_id );
		if( !in_array( $order->status, $status ) )
			return;
		
		global $wpdb;
		//Make api request
		$this->checkAddress( $args );
		
		//Update order check status
		$status = $this->error;
		$suggest = $this->suggest;
		
		$test = $wpdb->get_var( "SELECT count(*) FROM {$wpdb->prefix}acproof_orders WHERE `order_id` = '$order_id'" );
		if( $test == 0 )
			$command = 'insert';
		if( $command == 'update' ) {
			$wpdb->update(
				$wpdb->prefix . 'acproof_orders',
				array(
					'status' => $status,
					'suggest' => $suggest
				),
				array(
					'order_id' => $order_id
				)
			);
		}
		elseif( $command == 'insert' ) {
			$wpdb->insert(
				$wpdb->prefix . 'acproof_orders',
				array(
					'id' => 'NULL',
					'order_id' => $order_id,
					'status' => $status,
					'suggest' => $suggest
				)
			);
		}
		return true;
	}

	//Get in sync when order items are deleted in woocommerce
	public function ac_delete_order( $order_id ) {
		
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}acproof_orders WHERE order_id = '$order_id'" );
		return true;
	}
	
	//API function for outside usage of the engine
	public function check( &$args = false ) {
		$this->checkAddress( $args );
		return $this->error;
	}
	
	//Check addresses using Google API
	private function checkAddress( &$args = false ) {
		
		if( $args == false )
			return;
		
		$options = get_option( 'acproof-options' );
		
		$address = $args['detail'] . ' ' . $args['street'] . ' ' . $args['postcode'] . ' ' . $args['city'] . ' ' . $args['province'] . ' ' . $args['country'];
		$address = urlencode( $address );
		$langs = array( 'en', $args['countrycode'] );
		$checkb4 = -1;
		foreach( $langs as $lang ) {
			$curl = curl_init();
			$url = 'http://maps.google.com/maps/api/geocode/json?address=' . $address . '&sensor=false&language=' . $lang;
			if( trim( $options['client'] ) != '' && trim( $options['signature'] ) != '' ) {
				$url = 'http://maps.google.com/maps/api/geocode/json?address=' . $address . '&client=' . $options['client'] . '&signature=' . $options['signature'] . '&sensor=false&language=' . $lang;
			}
		
			curl_setopt( $curl, CURLOPT_URL, $url );
			if( trim( $options['proxy'] ) != '' ) {
				curl_setopt( $curl, CURLOPT_PROXY, $options['proxy'] );
			}
			curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
			$response = curl_exec( $curl ) or wp_die( curl_error( $curl ) );
			$data = json_decode( $response );
			
			if( $data->status == 'OK' ) {
				$this->parseAddress( $data );
				$formatted = $data->results[0]->formatted_address;
				$this->suggest = $formatted;
				$this->compareAddress( $args, $lang );
				
				if( $this->error != 0 && $this->error < $checkb4 )
					$this->error = $checkb4;
			}
			else {
				$this->error = -1;			//Not sure if the address is correct
				$this->suggest = '';
			}
			
			curl_close( $curl );
			if( $this->error == 0 ) break;
			
			$checkb4 = $this->error;
		}
		return true;
	}
	
	//Parse Address Data from Google Geocode API
	private function parseAddress( $data ) {
		//Initialize regions array
		unset( $this->regions );
		$this->regions = array( 'provinces' => array(), 'locals' => array() );
		
		$components = $data->results[0]->address_components;
		$entity = array( 'country', 'route', 'street_address', 'street_number', 'postal_code', 'post_box' );
		$level1 = array( 'administrative_area_level_1', 'administrative_area_level_2', 'administrative_area_level_3' );
		$level2 = array( 'locality', 'sublocality', 'sublocality_level_1', 'sublocality_level_2', 'sublocality_level_3', 'neighborhood' );
		
		foreach( $components as $part ) {
			$type = $part->types[0];
			if( in_array( $type, $entity ) )
				$this->regions[$type] = $part;
			elseif( in_array( $type, $level1 ) )
				$this->regions['provinces'][] = $part;
			elseif( in_array( $type, $level2 ) )
				$this->regions['locals'][] = $part;
		}
	}
	
	//Perform an address comparison
	private function compareAddress( $args, $lang = 'en' ) {
		if( !isset( $this->regions['country'] ) || sizeof( $this->regions['locals'] ) == 0 ) {
			$this->error = -1;
			return;
		}
	//Country Check Starts
		if( $lang == 'en' && strcasecmp( $args['country'], $this->regions['country']->long_name ) != 0 && strcasecmp( $args['country'], $this->regions['country']->short_name ) != 0 ) {
			$this->error = 1;
			return;
		}		
	//Country Check Ends
		
	//Province/State/County Check Start
		$match = false;
		$pool = array();
		
		foreach( $this->regions['provinces'] as $province ) {
			if( $lang == 'en' ) {
				$p1 = sanitize_title( $province->long_name );
				$p2 = sanitize_title( $province->short_name );
			}
			else {
				$p1 = str_replace( array( '.', ',', ' ' ), '-', $province->long_name );
				$p2 = str_replace( array( '.', ',', ' ' ), '-', $province->short_name );
			}
			$e1 = explode( '-', $p1 );
			$e2 = explode( '-', $p2 );
			$pool = array_merge( $pool, $e1, $e2 );
		}
		$pool = array_unique( $pool );
		
		if( $args['province'] != '' ) {
			if( $lang == 'en' )
				$arg_p = sanitize_title( str_replace( array( '.', ',' ), '', $args['province'] ) );
			else
				$arg_p = str_replace( array( '.', ',', ' ' ), '-', $args['province'] );
			$elements = explode( '-', $arg_p );
			
			foreach( $elements as $e ) {
				if( in_array( $e, $pool ) === false ) {
					$match = false;
					break;
				}
				else
					$match = true;
			}
			
		}
		else
			$match = true;
		if( $match == false ) {
			$this->error = 3;
			return;
		}
	//Province/State/Country Check Ends
		
	//City Check Starts
		$match = false;
		$pool = array();
		foreach( $this->regions['locals'] as $local ) {
			if( $lang == 'en' ) {
				$l1 = sanitize_title( $local->long_name );
				$l2 = sanitize_title( $local->short_name );
			}
			else {
				$l1 = str_replace( array( '.', ',', ' ' ), '-', $local->long_name );
				$l2 = str_replace( array( '.', ',', ' ' ), '-', $local->short_name );
			}
			$e1 = explode( '-', $l1 );
			$e2 = explode( '-', $l2 );
			$pool = array_merge( $pool, $e1, $e2 );
		}
		$pool = array_unique( $pool );
		
		if( $lang == 'en' )
			$arg_l = sanitize_title( str_replace( array( '.', ',' ), '', $args['city'] ) );
		else
			$arg_l = str_replace( array( '.', ',', ' ' ), '-', $args['city'] );
		$elements = explode( '-', $arg_l );
		foreach( $elements as $e ) {
			if( in_array( $e, $pool ) === false ) {
				$match = false;
				break;
			}
			else
				$match = true;
		}
		if( $match == false ) {
			$this->error = 4;
			return;
		}
	//City Check Ends
		
	//Street Address Check Starts
		if( $lang == 'en' ) {
			$clean = sanitize_title( $this->suggest, '', 'save' );	//Convert all non-ANSI characters to ANSI characters
			$search = sanitize_title( $args['street'], '' ,'save' );
		}
		else {
			$clean = str_replace( array( '.', ',', ' ' ), '-', $this->suggest );
			$search = str_replace( array( '.', ',', ' ' ), '-', $args['street'] );
		}
	
		if( $search == '' ) {
			$this->error = 5;
			return;
		}
		$terms = explode( '-', $search );
		
		foreach( $terms as $term ) {
			if( trim( $term ) == '' )
				continue;
			if( strpos( $clean, $term ) === false ) {
				$this->error = 5;
				return;
			}
		}
	//Street Address Check Ends
		
	//Postcode Check Starts
		if( !isset( $this->regions['postal_code'] ) ) {
			$this->error = 2;
			return;
		}
		
		$pcode = sanitize_title( $args['postcode'] );
		$pcode = str_replace( '-', '', $pcode );
		$pcode_long = sanitize_title( $this->regions['postal_code']->long_name );
		$pcode_long = str_replace( '-', '', $pcode_long );
		$pcode_short = sanitize_title( $this->regions['postal_code']->short_name );
		$pcode_short = str_replace( '-', '', $pcode_short);
		
		if( $pcode != $pcode_long || $pcode != $pcode_short ) {
			$this->error = 2;
			return;
		}
		
	//Postcode Check Ends
		
		//If above checks are done, it is safe to say this address is correct
		$this->error = 0;
		return;
	}
	
	//Get standard Country
	private function _getCountry( $code ) {
	
		global $woocommerce;
		$countries = $woocommerce->countries->countries;
		return $countries[ $code ];
	}
	//Get standard State
	private function _getState( $country, $state ) {
		
		global $woocommerce;
		$states = $woocommerce->countries->states;
		if( isset( $states[$country] ) && isset( $states[$country][$state] ) )
			return $states[$country][$state];
		else
			return $state;
	}
}
?>