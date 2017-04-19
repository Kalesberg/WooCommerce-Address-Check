<?php
/**
 *	Plugin Name: Woocommerce Checkout Address Proof
 *	Plugin URI: http://hyunmoo.samcholi.com
 *	Description: Provides exact and fast address availability and suggestions using Address Checker(AC - using Google API) across over 240 countries during WooCommerce checkout process.
 *	Version: 2.5
 *	Author: K.Joomong
 *	Author URI: http://hyunmoo.samcholi.com
 *	License: GPLv2 or later Commercial
 *	Copyright 2013  K.Joomong (email : kalesberg503@gmail.com)
*/

if( !defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

ob_start();

if( !class_exists( 'ACProof' ) ) {

/**
 * Main Address Proof Class
 *
 * Contains the main functions for Address Proof, stores variables, and handles error messages
 *
 * @class ACProof
 * @version	2.5
 * @author K.Joomong
 */
	
class ACProof {
	/**
	 * @var string
	 */
	private $version = '2.5';

	/**
	 * @var string
	 */
	private $plugin_url;

	/**
	 * @var string
	 */
	private $plugin_path;
	
	/**
	 * @var object
	 */
	private $admin_pages;
	
	/**
	 * @var object
	 */
	public $engine = null;
	
	/**
	 * @var object
	 */
	private $admin_forms;
		
	/**
	 * ACProof Constructor.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		//Set class properties
		$this->plugin_url = plugin_dir_url( __FILE__ );
		$this->plugin_path = plugin_dir_path( __FILE__ );
		
		// Hooks
		
		//Plugin initial process hooks
		register_activation_hook( __FILE__, array( $this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'action_links' ) );
		add_action( 'plugins_loaded', array( $this, 'version_check' ) );
		
		//Plugin admin menu
		if( is_admin() ) {
			//Initializes plugin admin pages and forms
			include_once( $this->plugin_path . 'admin/page.php' );
			include_once( $this->plugin_path . 'admin/process.php' );
			$this->admin_pages = new AC_Admin_Pages();
			$this->admin_forms = new AC_Admin_Forms();
			
			add_action( 'admin_menu', array( $this, 'admin_menu' ) );
			add_action( 'admin_init', array( $this, 'admin_form' ) );
		}
				
		//Load Address Checker engine
		if( extension_loaded( 'curl' ) ) {						//This engine requires CURL enabled
			include_once( $this->plugin_path . 'engine.php' );
			$this->engine = new AC_Engine();
		}
	}
	
	/**
	 * action_links function.
	 *
	 * @access public
	 * @param mixed $links
	 * @return void
	 */
	public function action_links( $links ) {

		$plugin_links = array(
			'<a href="' . admin_url( 'options-general.php?page=acproof-settings' ) . '">' . __( 'Settings', 'adproof' ) . '</a>'
		);

		return array_merge( $plugin_links, $links );
	}
	
	// Plugin activiation
	// for future use only
	public function activate() {
	
		$default_options = array();
		$default_options['client'] = '';
		$default_options['signature'] = '';
		$default_options['proxy'] = '';
		$default_options['status'] = array( 'pending', 'failed', 'on-hold', 'processing', 'completed', 'refunded', 'cancelled' );
		$default_options['type'] = 'shipping';
		$default_options['workplace'] = 0;
		
		add_option( 'acproof-options', $default_options );
		
		if( !class_exists( 'woocommerce' ) ) {
			return false;
		}
		
		global $wpdb;
		
		$collate = '';

		if ( $wpdb->has_cap( 'collation' ) ) {
			if( ! empty($wpdb->charset ) )
				$collate .= "DEFAULT CHARACTER SET $wpdb->charset";
			if( ! empty($wpdb->collate ) )
				$collate .= " COLLATE $wpdb->collate";
		}
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		
		//acproof_orders table which checks correctness of approved order billing address
		$sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}acproof_orders (
					id BIGINT(20) NOT NULL auto_increment,
					order_id BIGINT(20) NOT NULL,
					status VARCHAR(2) NOT NULL DEFAULT '-',
					suggest VARCHAR(200) NOT NULL DEFAULT '',
					PRIMARY KEY  (id),
					KEY order_id (order_id)
				) $collate;
				";
		dbDelta( $sql );
		
		//Check if there are already any orders
		$test = $wpdb->query( "select 1 from `{$wpdb->prefix}woocommerce_order_items`" );
		if( $test == 0 )
			return;
		
		//Insert already existing orders to the acproof_orders table
		$query = "SELECT Distinct(order_id) FROM {$wpdb->prefix}woocommerce_order_items";
		
		$orders = $wpdb->get_results( $query );

		foreach( $orders as $order ) {
			$wpdb->insert(
				$wpdb->prefix . 'acproof_orders',
				array(
					'id' => 'NULL',
					'order_id' => $order->order_id
				)
			);
		}
	}
	
	//Plugin deactivation
	public function deactivate() {
	
		global $wpdb;
		
		//Delete acproof_orders table
		$sql = "DROP TABLE IF EXISTS {$wpdb->prefix}acproof_orders";
		
		$wpdb->query( $sql );
		delete_option( 'acproof-options' );
	}
	
	//version check
	public function version_check() {
		return $this->version;
	}
	
	//Load css and js
	public function addDecorations() {
		wp_enqueue_script( 'jquery' );
		wp_register_style( 'acproof-css', $this->getPluginUrl() . 'css/acproof.css' );
		wp_register_script( 'acproof-js', $this->getPluginUrl() . 'js/acproof.js' );
		wp_enqueue_style( 'acproof-css' );
		wp_enqueue_script( 'acproof-js' );
	}
	//Admin plugin menu
	public function admin_menu() {
		//Add admin menu item
		add_options_page( __( 'Address Proof Settings', 'acproof' ), __( 'Address Proof' , 'acproof' ),
			'manage_woocommerce', 'acproof-settings', array( $this->admin_pages, 'settings' ) );
		
	}
	
	//Process plugin page forms
	public function admin_form() {
		//Just work when form is submitted
		if( empty( $_POST ) )
			return;
			
		//Avoid any ajax request
		if(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
			return;
		$query = $_SERVER['QUERY_STRING'];
		$query =  strtolower( strrchr( $query, '-' ) );
		$function = substr( $query, 1 ) . 'Form';
		
		$this->admin_forms->$function();
	}
	
	//Return plugin_path
	public function getPluginPath() {
		return $this->plugin_path;
	}
	
	//Return plugin_url
	public function getPluginUrl() {
		return $this->plugin_url;
	}

}
/**
 * Init Address Proof class
 */
$GLOBALS['acproof'] = new ACProof();

} // class_exists check

//standalone function to check address
/**
 * @var fields array for address components
 * All field values can be either long name or short name for respective region
 * fields['street'] : street address 1
 * fields['detail'] : street address 2
 * fields['postcode'] : Postal code
 * fields['city'] : City
 * fields['province'] : State or County
 * fields['country'] : Country
 */
function acproof( $fields = array() ) {
	if( !$fields )	return -1;
	if( is_array( $fields ) && empty( $fields ) )	return -1;
	
	global $acproof;
	if( !$acproof->engine )	return -1;
	
	$defaults = array(
		'detail'	=> '',
		'street'	=> '',
		'postcode'	=> '',
		'city'		=> '',
		'province'	=> '',
		'country'	=> ''
	);
	$args = wp_parse_args( $fields, $defaults );
	$engine = & $acproof->engine;
	$result = $engine->check( $args );
	return $result;
}