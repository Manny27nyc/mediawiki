<?php

use MediaWiki\HookContainer\HookContainer;
use PHPUnit\Framework\Constraint\Constraint;
use PHPUnit\Framework\MockObject\MockObject;
use Pimple\Psr11\ServiceLocator;
use Wikimedia\ObjectFactory;

/**
 * For code common to both MediaWikiUnitTestCase and MediaWikiIntegrationTestCase.
 */
trait MediaWikiTestCaseTrait {
	/** @var int|null */
	private $originalPhpErrorFilter;

	/**
	 * Returns a PHPUnit constraint that matches anything other than a fixed set of values. This can
	 * be used to list accepted values, e.g.
	 *   $mock->expects( $this->never() )->method( $this->anythingBut( 'foo', 'bar' ) );
	 * which will throw if any unexpected method is called.
	 *
	 * @param mixed ...$values Values that are not matched
	 * @return Constraint
	 */
	protected function anythingBut( ...$values ) {
		return $this->logicalNot( $this->logicalOr(
			...array_map( [ $this, 'matches' ], $values )
		) );
	}

	/**
	 * Return a PHPUnit mock that is expected to never have any methods called on it.
	 *
	 * @param string $type
	 * @param string[] $allow methods to allow
	 *
	 * @return MockObject
	 */
	protected function createNoOpMock( $type, $allow = [] ) {
		$mock = $this->createMock( $type );
		$mock->expects( $this->never() )->method( $this->anythingBut( '__destruct', ...$allow ) );
		return $mock;
	}

	/**
	 * Return a PHPUnit mock that is expected to never have any methods called on it.
	 *
	 * @param string $type
	 * @param string[] $allow methods to allow
	 * @return MockObject
	 */
	protected function createNoOpAbstractMock( $type, $allow = [] ) {
		$mock = $this->getMockBuilder( $type )
			->disableOriginalConstructor()
			->disableOriginalClone()
			->disableArgumentCloning()
			->disallowMockingUnknownTypes()
			->getMockForAbstractClass();
		$mock->expects( $this->never() )->method( $this->anythingBut( '__destruct', ...$allow ) );
		return $mock;
	}

	/**
	 * Create an ObjectFactory with no dependencies
	 *
	 * @return ObjectFactory
	 */
	protected function createSimpleObjectFactory() {
		return new ObjectFactory(
			new ServiceLocator( new \Pimple\Container(), [] )
		);
	}

	/**
	 * Create an initially empty HookContainer with an empty service container
	 * attached. Register only the hooks specified in the parameter.
	 *
	 * @param callable[] $hooks
	 * @return HookContainer
	 */
	protected function createHookContainer( $hooks = [] ) {
		$hookContainer = new HookContainer(
			new \MediaWiki\HookContainer\StaticHookRegistry(),
			$this->createSimpleObjectFactory()
		);
		foreach ( $hooks as $name => $callback ) {
			$hookContainer->register( $name, $callback );
		}
		return $hookContainer;
	}

	/**
	 * Don't throw a warning if $function is deprecated and called later
	 *
	 * @since 1.19
	 *
	 * @param string $function
	 */
	public function hideDeprecated( $function ) {
		// Construct a regex that will match the message generated by
		// wfDeprecated() if it is called for the specified function.
		$this->filterDeprecated( '/Use of ' . preg_quote( $function, '/' ) . ' /' );
	}

	/**
	 * Don't throw a warning for deprecation messages matching a regex.
	 *
	 * @since 1.35
	 *
	 * @param string $regex
	 */
	public function filterDeprecated( $regex ) {
		MWDebug::filterDeprecationForTest( $regex );
	}

	/**
	 * Check whether file contains given data.
	 * @param string $fileName
	 * @param string $actualData
	 * @param bool $createIfMissing If true, and file does not exist, create it with given data
	 *                              and skip the test.
	 * @param string $msg
	 * @since 1.30
	 */
	protected function assertFileContains(
		$fileName,
		$actualData,
		$createIfMissing = false,
		$msg = ''
	) {
		if ( $createIfMissing ) {
			if ( !file_exists( $fileName ) ) {
				file_put_contents( $fileName, $actualData );
				$this->markTestSkipped( "Data file $fileName does not exist" );
			}
		} else {
			$this->assertFileExists( $fileName );
		}
		$this->assertEquals( file_get_contents( $fileName ), $actualData, $msg );
	}

	/**
	 * Assert that two arrays are equal. By default this means that both arrays need to hold
	 * the same set of values. Using additional arguments, order and associated key can also
	 * be set as relevant.
	 *
	 * @since 1.20
	 *
	 * @param array $expected
	 * @param array $actual
	 * @param bool $ordered If the order of the values should match
	 * @param bool $named If the keys should match
	 * @param string $message
	 * @param float $delta Deprecated in assertEquals()
	 * @param int $maxDepth Deprecated in assertEquals()
	 * @param bool $canonicalize Deprecated in assertEquals()
	 * @param bool $ignoreCase Deprecated in assertEquals()
	 */
	public function assertArrayEquals(
		array $expected, array $actual, $ordered = false, $named = false, string $message = '',
		float $delta = 0.0, int $maxDepth = 10, bool $canonicalize = false, bool $ignoreCase = false
	) {
		if ( !$ordered ) {
			$this->objectAssociativeSort( $expected );
			$this->objectAssociativeSort( $actual );
		}

		if ( !$named ) {
			$expected = array_values( $expected );
			$actual = array_values( $actual );
		}

		$this->assertEquals(
			$expected, $actual, $message,
			// Deprecated args
			$delta, $maxDepth, $canonicalize, $ignoreCase
		);
	}

	/**
	 * Does an associative sort that works for objects.
	 *
	 * @since 1.20
	 *
	 * @param array &$array
	 */
	protected function objectAssociativeSort( array &$array ) {
		uasort(
			$array,
			static function ( $a, $b ) {
				return serialize( $a ) <=> serialize( $b );
			}
		);
	}

	/**
	 * @before
	 */
	protected function phpErrorFilterSetUp() {
		$this->originalPhpErrorFilter = intval( ini_get( 'error_reporting' ) );
	}

	/**
	 * @after
	 */
	protected function phpErrorFilterTearDown() {
		$phpErrorFilter = intval( ini_get( 'error_reporting' ) );

		if ( $phpErrorFilter !== $this->originalPhpErrorFilter ) {
			ini_set( 'error_reporting', $this->originalPhpErrorFilter );
			$message = "PHP error_reporting setting found dirty."
				. " Did you forget AtEase::restoreWarnings?";
			$this->fail( $message );
		}
	}

	/**
	 * Re-enable any disabled deprecation warnings and allow same deprecations to be thrown
	 * multiple times in different tests, so the PHPUnit expectDeprecation() works.
	 * @after
	 */
	protected function mwDebugTearDown() {
		MWDebug::clearLog();
	}

	/**
	 * @param string $text
	 * @param array $params
	 * @return Message|MockObject
	 * @since 1.35
	 */
	protected function getMockMessage( $text = '', $params = [] ) {
		/** @var MockObject $msg */
		$msg = $this->getMockBuilder( Message::class )
			->disableOriginalConstructor()
			->setMethods( [] )
			->getMock();
		$msg->method( 'toString' )->willReturn( $text );
		$msg->method( '__toString' )->willReturn( $text );
		$msg->method( 'text' )->willReturn( $text );
		$msg->method( 'parse' )->willReturn( $text );
		$msg->method( 'plain' )->willReturn( $text );
		$msg->method( 'parseAsBlock' )->willReturn( $text );
		$msg->method( 'escaped' )->willReturn( $text );
		$msg->method( 'title' )->willReturn( $msg );
		$msg->method( 'getKey' )->willReturn( $text );
		$msg->method( 'params' )->willReturn( $msg );
		$msg->method( 'getParams' )->willReturn( $params );
		$msg->method( 'rawParams' )->willReturn( $msg );
		$msg->method( 'numParams' )->willReturn( $msg );
		$msg->method( 'inLanguage' )->willReturn( $msg );
		$msg->method( 'inContentLanguage' )->willReturn( $msg );
		$msg->method( 'useDatabase' )->willReturn( $msg );
		$msg->method( 'setContext' )->willReturn( $msg );
		$msg->method( 'exists' )->willReturn( true );
		$msg->method( 'content' )->willReturn( new MessageContent( $msg ) );
		return $msg;
	}
}
