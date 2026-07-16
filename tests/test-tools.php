<?php
/**
 * Tests for the Tools-tab logic: bulk remove from all replacements and the
 * site-wide "find usage" scan.
 *
 * @package Email_Router
 */

class Test_Tools extends EIR_Test_Case {

	public function test_remove_strips_email_from_all_recipient_lists() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't1@example.com',
						'replacement' => 'a@example.com,x@example.com',
					),
					array(
						'target'      => 't2@example.com',
						'replacement' => 'x@example.com',
					),
					array(
						'target'      => 't3@example.com',
						'replacement' => 'b@example.com',
					),
				),
			)
		);

		$affected = $this->call_private( $this->router, 'remove_email_from_replacements', 'x@example.com' );

		$this->assertSame( 2, $affected );

		$pairs = get_option( self::OPTION )['email_replacement_pairs'];
		$this->assertSame( 'a@example.com', $pairs[0]['replacement'] );
		$this->assertSame( '', $pairs[1]['replacement'] );
		$this->assertSame( 'b@example.com', $pairs[2]['replacement'] );
	}

	public function test_remove_is_case_insensitive() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't@example.com',
						'replacement' => 'Mixed@Example.com',
					),
				),
			)
		);

		$affected = $this->call_private( $this->router, 'remove_email_from_replacements', 'mixed@example.com' );

		$this->assertSame( 1, $affected );
	}

	public function test_remove_of_absent_email_changes_nothing() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't@example.com',
						'replacement' => 'a@example.com',
					),
				),
			)
		);

		$affected = $this->call_private( $this->router, 'remove_email_from_replacements', 'absent@example.com' );

		$this->assertSame( 0, $affected );
	}

	public function test_remove_leaves_targets_untouched() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'shared@example.com',
						'replacement' => 'shared@example.com',
					),
				),
			)
		);

		$this->call_private( $this->router, 'remove_email_from_replacements', 'shared@example.com' );

		$pairs = get_option( self::OPTION )['email_replacement_pairs'];
		$this->assertSame( 'shared@example.com', $pairs[0]['target'] );
		$this->assertSame( '', $pairs[0]['replacement'] );
	}

	public function test_find_usage_locates_replacement_recipient() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't@example.com',
						'replacement' => 'recipient@example.com',
					),
				),
			)
		);

		$results   = $this->call_private( $this->router, 'find_email_usage', 'recipient@example.com' );
		$locations = wp_list_pluck( $results, 'location' );

		$this->assertContains( 'Replacement rule (recipient)', $locations );
	}

	public function test_find_usage_locates_subject_pattern_recipient() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'team@example.com',
					),
				),
			)
		);

		$results   = $this->call_private( $this->router, 'find_email_usage', 'team@example.com' );
		$locations = wp_list_pluck( $results, 'location' );

		$this->assertContains( 'Subject pattern recipient', $locations );
	}

	public function test_find_usage_locates_wordpress_admin_email() {
		$admin_email = strtolower( get_option( 'admin_email' ) );

		$results = $this->call_private( $this->router, 'find_email_usage', $admin_email );
		$sources = wp_list_pluck( $results, 'source' );

		$this->assertContains( 'WordPress', $sources );
	}

	public function test_find_usage_returns_empty_for_unused_address() {
		$results = $this->call_private( $this->router, 'find_email_usage', 'nobody-unused-xyz@example.org' );

		$this->assertSame( array(), $results );
	}

	public function test_find_usage_ignores_invalid_input() {
		$results = $this->call_private( $this->router, 'find_email_usage', 'not-an-email' );

		$this->assertSame( array(), $results );
	}

	public function test_find_usage_locates_blacklist_entry() {
		$this->set_settings(
			array(
				'email_blacklist' => array( 'blocked@example.com' ),
			)
		);

		$results   = $this->call_private( $this->router, 'find_email_usage', 'blocked@example.com' );
		$locations = wp_list_pluck( $results, 'location' );

		$this->assertContains( 'Blacklisted (blocked from all mail)', $locations );
	}

	public function test_find_usage_locates_user_account() {
		$user_id = self::factory()->user->create(
			array(
				'user_email' => 'person@example.com',
				'role'       => 'editor',
			)
		);

		$results  = $this->call_private( $this->router, 'find_email_usage', 'person@example.com' );
		$accounts = array_filter(
			$results,
			static function ( $row ) {
				return 'WordPress' === $row['source'] && false !== strpos( $row['location'], 'User account' );
			}
		);

		$this->assertNotEmpty( $accounts );

		// Clean up the user we created.
		wp_delete_user( $user_id );
	}

	public function test_find_usage_normalizes_uppercase_input() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 't@example.com',
						'replacement' => 'recipient@example.com',
					),
				),
			)
		);

		$results   = $this->call_private( $this->router, 'find_email_usage', 'RECIPIENT@EXAMPLE.COM' );
		$locations = wp_list_pluck( $results, 'location' );

		$this->assertContains( 'Replacement rule (recipient)', $locations );
	}
}
