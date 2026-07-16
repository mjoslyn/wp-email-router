<?php
/**
 * Tests for the global helper functions shipped with the plugin.
 *
 * @package Email_Router
 */

class Test_Helpers extends EIR_Test_Case {

	public function test_unique_flatten_flattens_nested_arrays() {
		$result = email_router_unique_flatten( array( 'a@example.com', array( 'b@example.com', 'c@example.com' ) ) );

		$this->assertSame(
			array( 'a@example.com', 'b@example.com', 'c@example.com' ),
			array_values( $result )
		);
	}

	public function test_unique_flatten_removes_duplicates() {
		$result = email_router_unique_flatten( array( 'a@example.com', array( 'a@example.com', 'b@example.com' ) ) );

		$this->assertSame(
			array( 'a@example.com', 'b@example.com' ),
			array_values( $result )
		);
	}

	public function test_plugin_singleton_is_loaded() {
		$this->assertInstanceOf( 'EmailRouter', EmailRouter::get_instance() );
	}

	public function test_wp_mail_filters_are_registered() {
		$this->assertNotFalse( has_filter( 'wp_mail', array( $this->router, 'replace_emails' ) ) );
		$this->assertNotFalse( has_filter( 'wp_mail', array( $this->router, 'replace_by_subject' ) ) );
	}
}
