<?php
/**
 * Tests for the system email report: what it collects and how it routes.
 *
 * @package Email_Router
 */

/**
 * Covers get_system_email_report(), route_recipients(), and split_recipient_list().
 */
class Test_System_Emails extends EIR_Test_Case {

	/**
	 * Convenience wrapper: run the report and return its rows.
	 *
	 * @return array Report rows.
	 */
	private function report() {
		return $this->call_private( $this->router, 'get_system_email_report' );
	}

	/**
	 * Find the first report row whose name matches.
	 *
	 * @param array  $report Report rows.
	 * @param string $name   Row name to look for.
	 * @return array|null
	 */
	private function row_named( $report, $name ) {
		foreach ( $report as $row ) {
			if ( $name === $row['name'] ) {
				return $row;
			}
		}
		return null;
	}

	public function test_report_lists_wordpress_core_emails() {
		$sources = wp_list_pluck( $this->report(), 'source' );

		$this->assertContains( 'WordPress', $sources );
	}

	public function test_report_includes_admin_email_as_a_recipient() {
		$row = $this->row_named( $this->report(), 'New user registration (admin copy)' );

		$this->assertNotNull( $row );
		$this->assertContains( get_option( 'admin_email' ), $row['recipients'] );
	}

	public function test_report_rows_have_the_documented_shape() {
		foreach ( $this->report() as $row ) {
			foreach ( array( 'source', 'name', 'subject', 'status', 'recipients', 'dynamic', 'link', 'routed', 'rerouted' ) as $key ) {
				$this->assertArrayHasKey( $key, $row );
			}
			$this->assertIsArray( $row['recipients'] );
			$this->assertIsArray( $row['dynamic'] );
			$this->assertIsArray( $row['routed'] );
		}
	}

	public function test_report_applies_replacement_rules_to_recipients() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => get_option( 'admin_email' ),
						'replacement' => 'ops@example.com',
					),
				),
			)
		);

		$row = $this->row_named( $this->report(), 'New user registration (admin copy)' );

		$this->assertSame( array( 'ops@example.com' ), $row['routed'] );
		$this->assertTrue( $row['rerouted'] );
	}

	public function test_report_marks_unrouted_rows_as_not_rerouted() {
		$row = $this->row_named( $this->report(), 'New user registration (admin copy)' );

		$this->assertSame( $row['recipients'], $row['routed'] );
		$this->assertFalse( $row['rerouted'] );
	}

	public function test_report_shows_a_blacklisted_recipient_as_undelivered() {
		$this->set_settings(
			array(
				'email_blacklist' => array( get_option( 'admin_email' ) ),
			)
		);

		$row = $this->row_named( $this->report(), 'New user registration (admin copy)' );

		$this->assertSame( array(), $row['routed'] );
	}

	public function test_report_rows_can_be_added_by_filter() {
		$add = static function ( $rows ) {
			$rows[] = array(
				'source'     => 'Custom Plugin',
				'name'       => 'Nightly digest',
				'recipients' => array( 'digest@example.com' ),
			);
			return $rows;
		};

		add_filter( 'email_router_system_emails', $add );
		$report = $this->report();
		remove_filter( 'email_router_system_emails', $add );

		$row = $this->row_named( $report, 'Nightly digest' );

		$this->assertNotNull( $row );
		$this->assertSame( 'Custom Plugin', $row['source'] );
		$this->assertSame( array( 'digest@example.com' ), $row['routed'] );
	}

	public function test_route_recipients_applies_subject_routing_when_subject_is_known() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'New Order',
						'recipients' => 'orders@example.com',
					),
				),
			)
		);

		$routed = $this->call_private(
			$this->router,
			'route_recipients',
			array( 'shop@example.com' ),
			'New Order #42'
		);

		$this->assertSame( array( 'orders@example.com' ), $routed );
	}

	public function test_route_recipients_skips_subject_routing_without_a_subject() {
		$this->set_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => '.*',
						'recipients' => 'catchall@example.com',
					),
				),
			)
		);

		$routed = $this->call_private( $this->router, 'route_recipients', array( 'shop@example.com' ), '' );

		$this->assertSame( array( 'shop@example.com' ), $routed );
	}

	public function test_route_recipients_returns_nothing_for_an_empty_list() {
		$routed = $this->call_private( $this->router, 'route_recipients', array(), 'Anything' );

		$this->assertSame( array(), $routed );
	}

	public function test_route_recipients_deduplicates_the_result() {
		$this->set_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'a@example.com',
						'replacement' => 'shared@example.com',
					),
					array(
						'target'      => 'b@example.com',
						'replacement' => 'shared@example.com',
					),
				),
			)
		);

		$routed = $this->call_private(
			$this->router,
			'route_recipients',
			array( 'a@example.com', 'b@example.com' ),
			''
		);

		$this->assertSame( array( 'shared@example.com' ), $routed );
	}

	public function test_split_recipient_list_separates_addresses_from_merge_tags() {
		list( $literal, $dynamic ) = $this->call_private(
			$this->router,
			'split_recipient_list',
			'team@example.com, {admin_email}, [your-email]'
		);

		$this->assertSame( array( 'team@example.com' ), $literal );
		$this->assertSame( array( '{admin_email}', '[your-email]' ), $dynamic );
	}

	public function test_split_recipient_list_reads_the_name_and_address_form() {
		list( $literal, $dynamic ) = $this->call_private(
			$this->router,
			'split_recipient_list',
			'Support Team <support@example.com>'
		);

		$this->assertSame( array( 'support@example.com' ), $literal );
		$this->assertSame( array(), $dynamic );
	}

	public function test_split_recipient_list_ignores_empty_entries() {
		list( $literal, $dynamic ) = $this->call_private( $this->router, 'split_recipient_list', ' , , ' );

		$this->assertSame( array(), $literal );
		$this->assertSame( array(), $dynamic );
	}

	public function test_csv_row_quotes_and_escapes_fields() {
		$line = $this->call_private( $this->router, 'csv_row', array( 'a', 'b,c', 'say "hi"' ) );

		$this->assertSame( '"a","b,c","say ""hi"""', $line );
	}

	public function test_csv_export_handler_is_registered() {
		$this->assertNotFalse(
			has_action( 'admin_post_email_router_system_emails_export', array( $this->router, 'handle_system_emails_export' ) )
		);
	}

	public function test_email_subject_reads_a_working_subject() {
		$email = $this->getMockBuilder( stdClass::class )
			->addMethods( array( 'get_subject' ) )
			->getMock();
		$email->method( 'get_subject' )->willReturn( 'Your order is complete' );

		$this->assertSame(
			'Your order is complete',
			$this->call_private( $this->router, 'email_subject', $email )
		);
	}

	public function test_email_subject_survives_an_email_that_cannot_build_one() {
		// WC_Email_Customer_Invoice::get_subject() dereferences a null order on the
		// bare instances WC()->mailer()->get_emails() returns, which took the Tools
		// page down with a fatal before the subject read was guarded.
		$email = $this->getMockBuilder( stdClass::class )
			->addMethods( array( 'get_subject' ) )
			->getMock();
		$email->method( 'get_subject' )->willThrowException(
			new Error( 'Call to a member function has_status() on null' )
		);

		$this->assertSame( '', $this->call_private( $this->router, 'email_subject', $email ) );
	}

	public function test_email_subject_is_empty_when_the_email_has_no_get_subject() {
		$this->assertSame( '', $this->call_private( $this->router, 'email_subject', new stdClass() ) );
	}

	public function test_copies_accept_a_plain_address() {
		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source' => 'Test',
					'name'   => 'Plain copy',
					'copies' => array( 'cc@example.com' ),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Plain copy' );

		$this->assertNotNull( $row );
		$this->assertSame(
			array(
				array(
					'address' => 'cc@example.com',
					'label'   => '',
				),
			),
			$row['copies']
		);
	}

	public function test_copies_accept_an_address_with_a_label() {
		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source' => 'Test',
					'name'   => 'Labelled copy',
					'copies' => array(
						array(
							'address' => 'bcc@example.com',
							'label'   => 'BCC',
						),
					),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Labelled copy' );

		$this->assertSame( 'bcc@example.com', $row['copies'][0]['address'] );
		$this->assertSame( 'BCC', $row['copies'][0]['label'] );
	}

	public function test_unrouted_is_read_as_copies() {
		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source'   => 'Test',
					'name'     => 'Legacy unrouted',
					'unrouted' => array(
						array(
							'address' => 'bcc@example.com',
							'label'   => 'BCC',
						),
					),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Legacy unrouted' );

		$this->assertArrayNotHasKey( 'unrouted', $row );
		$this->assertSame( 'bcc@example.com', $row['copies'][0]['address'] );
		$this->assertSame( 'BCC', $row['copies'][0]['label'] );
	}

	public function test_copies_are_routed_by_replacement_rules() {
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

		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source' => 'Test',
					'name'   => 'Copy expands',
					'copies' => array(
						array(
							'address' => 'alias@example.com',
							'label'   => 'BCC',
						),
					),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Copy expands' );

		$this->assertSame(
			array(
				array(
					'address' => 'rep1@example.com',
					'label'   => 'BCC',
				),
				array(
					'address' => 'rep2@example.com',
					'label'   => 'BCC',
				),
			),
			$row['routed_copies']
		);
		$this->assertTrue( $row['rerouted'] );
	}

	public function test_blacklisted_copies_are_not_delivered() {
		$this->set_settings( array( 'email_blacklist' => array( 'blocked@example.com' ) ) );

		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source'     => 'Test',
					'name'       => 'Blocked copy',
					'recipients' => array( 'to@example.com' ),
					'copies'     => array( 'blocked@example.com', 'cc@example.com' ),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Blocked copy' );

		$this->assertSame( array( 'to@example.com' ), $row['routed'] );
		$this->assertCount( 1, $row['routed_copies'] );
		$this->assertSame( 'cc@example.com', $row['routed_copies'][0]['address'] );
	}

	public function test_copies_survive_a_blacklisted_recipient_list() {
		$this->set_settings( array( 'email_blacklist' => array( 'blocked@example.com' ) ) );

		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source'     => 'Test',
					'name'       => 'Blocked to with a CC',
					'recipients' => array( 'blocked@example.com' ),
					'copies'     => array(
						array(
							'address' => 'cc@example.com',
							'label'   => 'CC',
						),
					),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Blocked to with a CC' );

		// Every To recipient is blacklisted, but the CC still receives.
		$this->assertSame( array(), $row['routed'] );
		$this->assertCount( 1, $row['routed_copies'] );
	}

	public function test_copies_are_not_subject_routed() {
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

		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source'     => 'Test',
					'name'       => 'Subject routed with a CC',
					'subject'    => 'Hello',
					'recipients' => array( 'to@example.com' ),
					'copies'     => array( 'cc@example.com' ),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Subject routed with a CC' );

		$this->assertSame( array( 'subject@example.com' ), $row['routed'] );
		$this->assertSame( 'cc@example.com', $row['routed_copies'][0]['address'] );
	}

	public function test_copies_drop_empty_entries() {
		add_filter(
			'email_router_system_emails',
			function ( $rows ) {
				$rows[] = array(
					'source' => 'Test',
					'name'   => 'Messy copies',
					'copies' => array( '', '  ', array( 'address' => '' ), 'ok@example.com' ),
				);
				return $rows;
			}
		);

		$row = $this->row_named( $this->report(), 'Messy copies' );

		$this->assertCount( 1, $row['copies'] );
		$this->assertSame( 'ok@example.com', $row['copies'][0]['address'] );
	}

	public function test_rows_without_copies_still_get_the_keys() {
		foreach ( $this->report() as $row ) {
			$this->assertArrayHasKey( 'copies', $row );
			$this->assertIsArray( $row['copies'] );
			$this->assertArrayHasKey( 'routed_copies', $row );
			$this->assertIsArray( $row['routed_copies'] );
		}
	}
}
