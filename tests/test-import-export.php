<?php
/**
 * Tests for import settings sanitization.
 *
 * @package Email_Router
 */

class Test_Import_Export extends EIR_Test_Case {

	public function test_imports_valid_payload() {
		$data = array(
			'email_replacement_pairs' => array(
				array( 'title' => 'X', 'target' => 't@example.com', 'replacement' => 'r@example.com' ),
			),
			'subject_pattern_pairs'            => array(
				array( 'pattern' => 'Order', 'recipients' => 'o@example.com' ),
			),
			'email_blacklist'                  => array( 'b@example.com' ),
		);

		$clean = $this->call_private( $this->router, 'sanitize_imported_settings', $data );

		$this->assertCount( 1, $clean['email_replacement_pairs'] );
		$this->assertSame( 'X', $clean['email_replacement_pairs'][0]['title'] );
		$this->assertCount( 1, $clean['subject_pattern_pairs'] );
		$this->assertSame( array( 'b@example.com' ), $clean['email_blacklist'] );
	}

	public function test_import_drops_invalid_entries() {
		$data = array(
			'email_replacement_pairs' => array(
				array( 'target' => 'valid@example.com', 'replacement' => 'r@example.com' ),
				array( 'target' => '' ),               // No target -> dropped.
				array( 'target' => 'not-an-email' ),   // Invalid -> dropped.
			),
			'subject_pattern_pairs'            => array(
				array( 'pattern' => 'Order', 'recipients' => 'o@example.com' ),
				array( 'pattern' => '' ),              // No pattern -> dropped.
			),
			'email_blacklist'                  => array( 'good@example.com', 'bad', '' ),
		);

		$clean = $this->call_private( $this->router, 'sanitize_imported_settings', $data );

		$this->assertCount( 1, $clean['email_replacement_pairs'] );
		$this->assertSame( 'valid@example.com', $clean['email_replacement_pairs'][0]['target'] );
		$this->assertCount( 1, $clean['subject_pattern_pairs'] );
		$this->assertSame( array( 'good@example.com' ), $clean['email_blacklist'] );
	}

	public function test_import_of_empty_payload_returns_empty_array() {
		$clean = $this->call_private( $this->router, 'sanitize_imported_settings', array() );
		$this->assertSame( array(), $clean );
	}

	public function test_export_then_import_round_trip() {
		$settings = array(
			'email_replacement_pairs' => array(
				array( 'title' => 'Round trip', 'target' => 'a@example.com', 'replacement' => 'b@example.com' ),
			),
		);

		// Simulate exporting (json) and re-importing.
		$decoded = json_decode( wp_json_encode( $settings ), true );
		$clean   = $this->call_private( $this->router, 'sanitize_imported_settings', $decoded );

		$this->assertSame(
			$settings['email_replacement_pairs'][0]['target'],
			$clean['email_replacement_pairs'][0]['target']
		);
		$this->assertSame(
			$settings['email_replacement_pairs'][0]['replacement'],
			$clean['email_replacement_pairs'][0]['replacement']
		);
	}
}
