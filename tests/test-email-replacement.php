<?php
/**
 * Tests for the wp_mail interception: recipient replacement, subject routing,
 * and the blacklist.
 *
 * @package Email_Router
 */

class Test_Email_Replacement extends EIR_Test_Case {

	public function test_replaces_target_with_single_recipient() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'new@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'old@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'new@example.com', $args['to'] );
	}

	public function test_replaces_target_with_multiple_recipients() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'a@example.com,b@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'old@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'a@example.com,b@example.com', $args['to'] );
	}

	public function test_replaces_when_to_is_an_array() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'new@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => array( 'old@example.com' ),
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'new@example.com', $args['to'] );
	}

	public function test_leaves_unmatched_recipients_untouched() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'new@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'someone-else@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'someone-else@example.com', $args['to'] );
	}

	public function test_blacklist_strips_recipient_during_replacement() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'keep@example.com,block@example.com',
					),
				),
				'email_blacklist'         => array( 'block@example.com' ),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'old@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertContains( 'keep@example.com', (array) $args['to'] );
		$this->assertNotContains( 'block@example.com', (array) $args['to'] );
	}

	public function test_subject_pattern_routes_to_configured_recipients() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'orders@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'New Order #42',
			)
		);

		$this->assertSame( array( 'orders@example.com' ), $args['to'] );
	}

	public function test_subject_pattern_is_case_insensitive() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'invoice',
						'recipients' => 'billing@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'Your INVOICE is ready',
			)
		);

		$this->assertSame( array( 'billing@example.com' ), $args['to'] );
	}

	public function test_non_matching_subject_leaves_recipients_unchanged() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'orders@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'Just saying hi',
			)
		);

		$this->assertSame( 'someone@example.com', $args['to'] );
	}

	public function test_missing_subject_key_is_a_no_op() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'orders@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject( array( 'to' => 'someone@example.com' ) );

		$this->assertSame( array( 'to' => 'someone@example.com' ), $args );
	}

	public function test_only_the_matching_pair_is_applied_among_many() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'one@example.com',
						'replacement' => 'r1@example.com',
					),
					array(
						'target'      => 'two@example.com',
						'replacement' => 'r2@example.com',
					),
					array(
						'target'      => 'three@example.com',
						'replacement' => 'r3@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'two@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'r2@example.com', $args['to'] );
	}

	public function test_pair_with_empty_replacement_is_skipped() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => '',
					),
				),
			)
		);

		$args = $this->router->replace_emails(
			array(
				'to'      => 'old@example.com',
				'subject' => 'Hello',
			)
		);

		// With no replacement configured the original recipient is left in place.
		$this->assertSame( 'old@example.com', $args['to'] );
	}

	public function test_no_replacement_rules_is_a_no_op() {
		$args = $this->router->replace_emails(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'someone@example.com', $args['to'] );
	}

	public function test_duplicate_recipients_are_collapsed_when_to_is_array() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'old@example.com',
						'replacement' => 'dup@example.com',
					),
				),
			)
		);

		// Both addresses map to the same replacement; unique_flatten() dedupes them.
		$args = $this->router->replace_emails(
			array(
				'to'      => array( 'old@example.com', 'old@example.com' ),
				'subject' => 'Hello',
			)
		);

		$this->assertSame( 'dup@example.com', $args['to'] );
	}

	public function test_subject_pattern_with_empty_recipients_is_skipped() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => '',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'New Order #42',
			)
		);

		$this->assertSame( 'someone@example.com', $args['to'] );
	}

	public function test_subject_pattern_routes_to_multiple_recipients() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'a@example.com, b@example.com',
					),
				),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'New Order',
			)
		);

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), array_values( $args['to'] ) );
	}

	public function test_subject_routing_then_blacklist_strips_recipient() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'keep@example.com,block@example.com',
					),
				),
				'email_blacklist'       => array( 'block@example.com' ),
			)
		);

		$args = $this->router->replace_by_subject(
			array(
				'to'      => 'someone@example.com',
				'subject' => 'New Order',
			)
		);

		$this->assertContains( 'keep@example.com', (array) $args['to'] );
		$this->assertNotContains( 'block@example.com', (array) $args['to'] );
	}
}
