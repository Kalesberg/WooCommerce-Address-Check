<?php
/*
 *Manages all admin forms of the Address Proof Plugin
*/

class AC_Admin_Forms {

	public function __construct() {
		//For future use
	}
	
	//magic function
	public function __call( $name, $arguments) {
		//reject any method call which does not exist
		if( !method_exists( $this, $name ) )
			return;
	}

	public function settingsForm() {
		//Save options
		global $acproof;
		//Check unproved order items
		if( isset( $_POST['prove'] ) && isset( $acproof->engine ) ) {
			$acproof->engine->processUnchecked();
			return;
		}
		
		$options = $_POST;
		update_option( 'acproof-options', $options );
	}

}
?>