<?php
/**
 * Tests for routing CC and BCC headers through replace_emails().
 *
 * @package Email_Router
 */

/**
 * Covers the replacement rules and blacklist as applied to CC and BCC headers.
 */
class Test_Copy_Headers extends EIR_Test_Case {

	/**
	 * Settings with one alias that expands to two addresses.
	 */
	private function set_alias_rule() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'alias@example.com',
						'replacement' => 'rep1@example.com,rep2@example.com',
					),
				),
			)
		);
	}

	public function test_bcc_alias_in_an_array_of_headers_is_expanded() {
		$this->set_alias_rule();

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'subject' => 'Hello',
				'headers' => array(
					'Content-Type: text/html; charset=UTF-8',
					'Bcc: alias@example.com',
					'Bcc: staff@example.com',
				),
			)
		);

		$this->assertSame(
			array(
				'Content-Type: text/html; charset=UTF-8',
				'Bcc: rep1@example.com, rep2@example.com',
				'Bcc: staff@example.com',
			),
			$args['headers']
		);
		$this->assertSame( 'customer@example.com', $args['to'] );
	}

	public function test_cc_alias_in_a_string_of_headers_is_expanded() {
		$this->set_alias_rule();

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'subject' => 'Hello',
				'headers' => "From: Site <site@example.com>\r\nCc: Sales <alias@example.com>, other@example.com",
			)
		);

		$this->assertSame(
			"From: Site <site@example.com>\nCc: rep1@example.com, rep2@example.com, other@example.com",
			$args['headers']
		);
	}

	public function test_header_name_matching_ignores_case() {
		$this->set_alias_rule();

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'headers' => array( 'BCC:alias@example.com' ),
			)
		);

		$this->assertSame( array( 'BCC: rep1@example.com, rep2@example.com' ), $args['headers'] );
	}

	public function test_blacklisted_copy_is_removed() {
		$this->set_settings( array( 'email_blacklist' => array( 'blocked@example.com' ) ) );

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'headers' => array( 'Cc: keep@example.com, Blocked <BLOCKED@example.com>' ),
			)
		);

		$this->assertSame( array( 'Cc: keep@example.com' ), $args['headers'] );
	}

	public function test_header_with_every_address_blacklisted_is_dropped() {
		$this->set_settings( array( 'email_blacklist' => array( 'blocked@example.com' ) ) );

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'headers' => array(
					'From: Site <site@example.com>',
					'Bcc: blocked@example.com',
				),
			)
		);

		$this->assertSame( array( 'From: Site <site@example.com>' ), array_values( $args['headers'] ) );
	}

	public function test_expansion_into_a_blacklisted_address_is_blocked() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'alias@example.com',
						'replacement' => 'rep1@example.com,blocked@example.com',
					),
				),
				'email_blacklist'         => array( 'blocked@example.com' ),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'headers' => array( 'Bcc: alias@example.com' ),
			)
		);

		$this->assertSame( array( 'Bcc: rep1@example.com' ), $args['headers'] );
	}

	public function test_other_headers_are_left_alone() {
		$this->set_alias_rule();

		$headers = array(
			'From: Alias <alias@example.com>',
			'Reply-To: alias@example.com',
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'headers' => $headers,
			)
		);

		$this->assertSame( $headers, $args['headers'] );
	}

	public function test_missing_headers_are_left_alone() {
		$this->set_alias_rule();

		$args = $this->router->replace_emails(
			array(
				'to'      => 'customer@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertArrayNotHasKey( 'headers', $args );
	}

	public function test_subject_routing_leaves_copies_alone() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Hello',
						'recipients' => 'subject@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'customer@example.com',
				'subject' => 'Hello',
				'headers' => array( 'Bcc: staff@example.com' ),
			)
		);

		$this->assertSame( array( 'subject@example.com' ), $args['to'] );
		$this->assertSame( array( 'Bcc: staff@example.com' ), $args['headers'] );
	}

	public function test_wp_mail_delivers_bcc_to_the_expanded_addresses() {
		$this->set_alias_rule();
		reset_phpmailer_instance();

		wp_mail(
			'customer@example.com',
			'Hello',
			'Body',
			array( 'Bcc: alias@example.com' )
		);

		$mailer = tests_retrieve_phpmailer_instance();
		$bcc    = array_map(
			function ( $pair ) {
				return $pair[0];
			},
			$mailer->getBccAddresses()
		);

		$this->assertSame( array( 'rep1@example.com', 'rep2@example.com' ), $bcc );
	}
}
