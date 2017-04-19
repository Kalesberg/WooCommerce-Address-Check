<?php
/*
 *Manages all admin pages of the Address Proof Plugin
 *Run css, js related hooks according to menu pages
*/

class AC_Admin_Pages {

	public function __construct() {
		//For future use
	}
	
	//magic function
	public function __call( $name, $arguments) {
		//reject any method call which does not exist
		if( !method_exists( $this, $name ) )
			return;
	}
	
	public function settings() {

		global $acproof;

		//load css, js files
		$acproof->addDecorations();
		
		$options = get_option( 'acproof-options' );
		$client = $options['client'];
		$signature = $options['signature'];
		$proxy = $options['proxy'];
		$status = $options['status'];
		$type = $options['type'];
		$workplace = $options['workplace'];
		$all_status = array(
			'pending'		=>	'Pending',
			'failed'		=>	'Failed',
			'on-hold'		=>	'On-hold',
			'processing'	=>	'Processing',
			'completed'		=>	'Completed',
			'refunded'		=>	'Refunded',
			'cancelled'		=>	'Cancelled'
		);
		//page contents
?>
<br>
<?php screen_icon(); ?>
<h1><?php _e( 'Address Proof Settings', 'acproof' ) ?></h1>
<?php
	if( !class_exists( 'woocommerce' ) ) {
		wp_die( 'This plugin requires WooCommerce to be installed.' );
	}
?>
<?php
	if( !extension_loaded( 'curl' ) ) :
?>
<br>
<div class="alert">
	<button type="button" class="close" data-dismiss="alert">&times;</button>
	This plugin requires <strong>CURL</strong> extension to be loaded first.
</div>
<?php endif; ?>
<form method="post" action="">
<div class="container">
	<p>
	<label for="client">Business Client ID(optional) : </label><br>
	<input type="text" id="client" name="client" placeholder="xxxxxxxxxxxx" value="<?php echo $client ?>" />
	&nbsp;<small><i>For business customers only, leave blank if you do not use it</i></small>
	</p>
	<p>
	<label for="client">Digital Signature(optional) : </label><br>
	<input type="text" id="signature" name="signature" placeholder="xxxxxxxxxxxx" value="<?php echo $signature ?>" />
	&nbsp;<small><i>For business customers only, leave blank if you do not use it</i></small>
	</p>
	<p>
	<label for="proxy">Proxy(ip:port)(optional) : </label><br>
	<input type="text" id="proxy" name="proxy" placeholder="192.168.0.1:808" value="<?php echo $proxy ?>" />
	&nbsp;<small><i>Leave this blank if you do not use proxy</i></small>
	</p>
	<p>
	<label for="status">Order Status to be checked(optional) : </label><br>
	<select id="status" name="status[]" multiple="multiple" />
	<?php
		foreach( $all_status as $value => $text ) :
			$selected = '';
			if( in_array( $value, $status ) )
				$selected = ' selected="selected"';
	?>
		<option value="<?php echo $value ?>"<?php echo $selected ?>><?php echo $text ?></option>
	<?php endforeach; ?>
	</select>
	&nbsp;<small><i>Only orders with selected status will be checked</i></small>
	</p>
	<p>
	<label for="type">Type : </label><br>
	<select name="type" id="type">
		<option value="billing" <?php if( $type == 'billing' ): ?>selected="selected"<?php endif; ?>>Billing Address</option>
		<option value="shipping" <?php if( $type == 'shipping' ): ?>selected="selected"<?php endif; ?>>Shipping Address</option>
	</select>
	</p>
	<p>
	<label for="workplace">Works at : </label><br>
	<select name="workplace" id="workplace">
		<option value="0" <?php if( $workplace == 0 ): ?>selected="selected"<?php endif; ?>>Backend</option>
		<option value="1" <?php if( $workplace == 1 ): ?>selected="selected"<?php endif; ?>>Frontend</option>
	</select>
	&nbsp;<small><i>Backend mode is recommended not to bother customers to place orders</i></small>
	</p>
	<p><input type="submit" value="Update" /></p>
</div>
</form>
<?php
	global $wpdb;
	
	$count = 0;
	$query = "SELECT order_id FROM {$wpdb->prefix}acproof_orders WHERE `status` = '-'";
	$results = $wpdb->get_results( $query );
	foreach( $results as $record ) {
		$order = new WC_Order( $record->order_id );
		if( in_array( $order->status, $status ) )
			$count ++;
	}
	if( $count > 0 ) :
?>
<div class="alert">
	<button type="button" class="close" data-dismiss="alert">&times;</button>
	There are <strong><?php echo $count ?></strong> address-unproved order(s).
	<form method="post" action="">
	<p><input type="submit" class="button" name="prove" value="Prove!" /></p>
	</form>
</div>
<?php endif; ?>
<?php
	}

}
?>