<?php
/**
 * Tests for the wp_ajax_email_router_usage handler (the "Where used?" modal).
 *
 * These use WP_Ajax_UnitTestCase and so must be run with the ajax group:
 *   bin/docker-test.sh --group ajax
 * (PHPUnit excludes the ajax group from the default run.)
 *
 * @group ajax
 *
 * @package Email_Router
 */

class Test_Ajax_Usage extends WP_Ajax_UnitTestCase {

	const OPTION = 'email_router_settings';

	/**
	 * The plugin singleton under test.
	 *
	 * @var EmailRouter
	 */
	protected $router;

	public function set_up() {
		parent::set_up();
		delete_option( self::OPTION );
		$this->router = EmailRouter::get_instance();
	}

	/**
	 * Decode whatever the handler streamed to the buffer as JSON.
	 *
	 * @return array
	 */
	private function last_json() {
		return json_decode( $this->_last_response, true );
	}

	public function test_non_admin_is_denied() {
		$this->_setRole( 'subscriber' );
		$_POST['nonce'] = wp_create_nonce( 'email_router_usage' );
		$_POST['email'] = 'someone@example.com';

		try {
			$this->_handleAjax( 'email_router_usage' );
			$this->fail( 'Expected the AJAX handler to die.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = $this->last_json();
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Permission denied.', $response['data']['message'] );
	}

	public function test_invalid_nonce_is_rejected() {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = 'not-a-valid-nonce';
		$_POST['email'] = 'someone@example.com';

		// A failed nonce check dies with "-1" (a stop, not a continue).
		$this->expectException( 'WPAjaxDieStopException' );
		$this->_handleAjax( 'email_router_usage' );
	}

	public function test_invalid_email_is_rejected() {
		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'email_router_usage' );
		$_POST['email'] = 'not-an-email';

		try {
			$this->_handleAjax( 'email_router_usage' );
			$this->fail( 'Expected the AJAX handler to die.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = $this->last_json();
		$this->assertFalse( $response['success'] );
		$this->assertSame( 'Invalid email address.', $response['data']['message'] );
	}

	public function test_valid_request_returns_usage_results() {
		update_option(
			self::OPTION,
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't@example.com',
						'replacement' => 'found@example.com',
					),
				),
			)
		);

		$this->_setRole( 'administrator' );
		$_POST['nonce'] = wp_create_nonce( 'email_router_usage' );
		$_POST['email'] = 'found@example.com';

		try {
			$this->_handleAjax( 'email_router_usage' );
			$this->fail( 'Expected the AJAX handler to die.' );
		} catch ( WPAjaxDieContinueException $e ) {
			unset( $e );
		}

		$response = $this->last_json();
		$this->assertTrue( $response['success'] );
		$this->assertSame( 'found@example.com', $response['data']['email'] );

		$locations = wp_list_pluck( $response['data']['results'], 'location' );
		$this->assertContains( 'Replacement rule (recipient)', $locations );
	}
}
