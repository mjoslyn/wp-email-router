<?php
/**
 * Tests for the Settings API sanitize callback.
 *
 * @package Email_Router
 */

/**
 * Covers the sanitize_settings() Settings API callback.
 */
class Test_Sanitize_Settings extends EIR_Test_Case {

	public function test_keeps_title_target_and_replacement() {
		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'title'       => 'Redirect sales',
						'target'      => 'sales@example.com',
						'replacement' => 'rep@example.com',
					),
				),
			)
		);

		$pairs = array_values( $out['email_replacement_pairs'] );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'Redirect sales', $pairs[0]['title'] );
		$this->assertSame( 'sales@example.com', $pairs[0]['target'] );
		$this->assertSame( 'rep@example.com', $pairs[0]['replacement'] );
	}

	public function test_drops_pairs_without_a_target() {
		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'title'       => 'Good',
						'target'      => 'a@example.com',
						'replacement' => 'b@example.com',
					),
					array(
						'title'       => 'Bad',
						'target'      => '',
						'replacement' => 'c@example.com',
					),
				),
			)
		);

		$pairs = array_values( $out['email_replacement_pairs'] );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'a@example.com', $pairs[0]['target'] );
	}

	public function test_invalid_target_is_emptied_and_dropped() {
		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'not-an-email',
						'replacement' => 'b@example.com',
					),
				),
			)
		);

		$this->assertEmpty( array_filter( (array) ( $out['email_replacement_pairs'] ?? array() ) ) );
	}

	public function test_drops_subject_patterns_without_a_pattern() {
		$out = $this->router->sanitize_settings(
			array(
				'subject_pattern_pairs' => array(
					array(
						'pattern'    => 'Order',
						'recipients' => 'o@example.com',
					),
					array(
						'pattern'    => '',
						'recipients' => 'x@example.com',
					),
				),
			)
		);

		$this->assertCount( 1, array_values( $out['subject_pattern_pairs'] ) );
	}

	public function test_blacklist_keeps_only_valid_emails() {
		$out = $this->router->sanitize_settings(
			array(
				'email_blacklist' => array( 'good@example.com', 'nope', '' ),
			)
		);

		$this->assertSame( array( 'good@example.com' ), array_values( $out['email_blacklist'] ) );
	}

	public function test_strips_tags_from_title() {
		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'title'       => '<script>alert(1)</script>Sales',
						'target'      => 'a@example.com',
						'replacement' => 'b@example.com',
					),
				),
			)
		);

		$pairs = array_values( $out['email_replacement_pairs'] );
		$this->assertSame( 'Sales', $pairs[0]['title'] );
	}

	public function test_pair_without_title_or_replacement_keys_is_kept() {
		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array( 'target' => 'a@example.com' ),
				),
			)
		);

		$pairs = array_values( $out['email_replacement_pairs'] );
		$this->assertCount( 1, $pairs );
		$this->assertSame( 'a@example.com', $pairs[0]['target'] );
		$this->assertSame( '', $pairs[0]['title'] );
		$this->assertSame( '', $pairs[0]['replacement'] );
	}

	public function test_merges_with_existing_options_from_other_tabs() {
		// Simulate data saved on another tab already in the option.
		$this->set_settings(
			array(
				'email_blacklist' => array( 'blocked@example.com' ),
			)
		);

		$out = $this->router->sanitize_settings(
			array(
				'email_replacement_pairs' => array(
					array(
						'target'      => 'a@example.com',
						'replacement' => 'b@example.com',
					),
				),
			)
		);

		// The replacement pair is added without wiping the blacklist.
		$this->assertSame( array( 'blocked@example.com' ), array_values( $out['email_blacklist'] ) );
		$this->assertCount( 1, array_values( $out['email_replacement_pairs'] ) );
	}
}
