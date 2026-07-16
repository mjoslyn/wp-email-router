<?php
/**
 * Tests for the private apply_blacklist() helper in isolation.
 *
 * @package Email_Router
 */

/**
 * Covers apply_blacklist() in isolation.
 */
class Test_Blacklist extends EIR_Test_Case {

	public function test_no_blacklist_returns_args_unchanged() {
		$args = array(
			'to'      => 'a@example.com',
			'subject' => 'Hi',
		);

		$result = $this->call_private( $this->router, 'apply_blacklist', $args );

		$this->assertSame( $args, $result );
	}

	public function test_strips_blacklisted_address_from_string_list() {
		$this->set_settings( array( 'email_blacklist' => array( 'block@example.com' ) ) );

		$result = $this->call_private(
			$this->router,
			'apply_blacklist',
			array( 'to' => 'keep@example.com, block@example.com' )
		);

		$this->assertSame( array( 'keep@example.com' ), array_values( $result['to'] ) );
	}

	public function test_strips_blacklisted_address_from_array_list() {
		$this->set_settings( array( 'email_blacklist' => array( 'block@example.com' ) ) );

		$result = $this->call_private(
			$this->router,
			'apply_blacklist',
			array( 'to' => array( 'keep@example.com', 'block@example.com' ) )
		);

		$this->assertSame( array( 'keep@example.com' ), array_values( $result['to'] ) );
	}

	public function test_strips_multiple_blacklisted_addresses() {
		$this->set_settings(
			array( 'email_blacklist' => array( 'b1@example.com', 'b2@example.com' ) )
		);

		$result = $this->call_private(
			$this->router,
			'apply_blacklist',
			array( 'to' => array( 'keep@example.com', 'b1@example.com', 'b2@example.com' ) )
		);

		$this->assertSame( array( 'keep@example.com' ), array_values( $result['to'] ) );
	}

	public function test_removing_every_recipient_yields_empty_list() {
		$this->set_settings( array( 'email_blacklist' => array( 'block@example.com' ) ) );

		$result = $this->call_private(
			$this->router,
			'apply_blacklist',
			array( 'to' => 'block@example.com' )
		);

		$this->assertSame( array(), $result['to'] );
	}

	public function test_non_blacklisted_recipients_pass_through() {
		$this->set_settings( array( 'email_blacklist' => array( 'block@example.com' ) ) );

		$result = $this->call_private(
			$this->router,
			'apply_blacklist',
			array( 'to' => array( 'a@example.com', 'b@example.com' ) )
		);

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), array_values( $result['to'] ) );
	}
}
