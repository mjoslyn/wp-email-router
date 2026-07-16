<?php
/**
 * Shared base test case for Email Router.
 *
 * @package Email_Router
 */

/**
 * Adds a helper for exercising the plugin's private business logic and ensures
 * each test starts from a clean settings option.
 */
abstract class EIR_Test_Case extends WP_UnitTestCase {

	/**
	 * Name of the option the plugin stores all its settings under.
	 *
	 * @var string
	 */
	const OPTION = 'email_router_settings';

	/**
	 * The plugin singleton under test.
	 *
	 * @var EmailRouter
	 */
	protected $router;

	/**
	 * Reset settings and grab the singleton before each test.
	 */
	public function set_up() {
		parent::set_up();
		delete_option( self::OPTION );
		$this->router = EmailRouter::get_instance();
	}

	/**
	 * Invoke a private/protected method on an object via reflection.
	 *
	 * @param object $target Target object.
	 * @param string $method Method name.
	 * @param mixed  ...$args Arguments to pass.
	 * @return mixed
	 */
	protected function call_private( $target, $method, ...$args ) {
		$ref = new ReflectionMethod( $target, $method );
		$ref->setAccessible( true );
		return $ref->invoke( $target, ...$args );
	}

	/**
	 * Convenience setter for the plugin settings option.
	 *
	 * @param array $settings Settings array.
	 */
	protected function set_settings( array $settings ) {
		update_option( self::OPTION, $settings );
	}
}
