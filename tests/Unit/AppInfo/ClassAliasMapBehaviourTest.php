<?php

/**
 * ClassAliasMap registers its lazy autoloader once per process, and only when a lazy entry needs it.
 *
 * The autoloader flag is process-wide static state: in a shared PHPUnit process
 * whichever test first applied a lazy map registered it, so these tests run in
 * a fresh process to see the registration happen.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

use OCA\OpenRegister\AppInfo\ClassAliasMap;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Target of the aliases in this test.
 */
final class ClassAliasMapBehaviourTarget {
}//end class

/**
 * Autoloader registration in a fresh process.
 */
class ClassAliasMapBehaviourTest extends TestCase {

	private const OLD_EAGER = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Retired\\A4EagerName';

	private const OLD_FIRST = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Retired\\A4FirstLazyName';

	private const OLD_SECOND = 'OCA\\OpenRegister\\Tests\\Unit\\AppInfo\\Retired\\A4SecondLazyName';

	/**
	 * How many times ClassAliasMap::autoload is on the autoload stack.
	 */
	private function autoloaderCount(): int {
		return count(
			array_filter(
				spl_autoload_functions(),
				static fn ($loader): bool => $loader === [ClassAliasMap::class, 'autoload']
			)
		);
	}//end autoloaderCount()

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testOnlyEagerEntriesRegisterNoAutoloader(): void {
		// A booted app may already have registered the autoloader; the eager map must add none.
		$before = $this->autoloaderCount();
		(new ClassAliasMap(entries: [self::OLD_EAGER => ['class' => ClassAliasMapBehaviourTarget::class, 'eager' => true]]))->aliasClasses();

		$this->assertSame($before, $this->autoloaderCount());
		$this->assertSame(ClassAliasMapBehaviourTarget::class, get_class(new (self::OLD_EAGER)()));
	}//end testOnlyEagerEntriesRegisterNoAutoloader()

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testTheFirstLazyMapRegistersTheAutoloaderAndASecondDoesNotAgain(): void {
		(new ClassAliasMap(entries: [self::OLD_FIRST => ['class' => ClassAliasMapBehaviourTarget::class, 'eager' => false]]))->aliasClasses();
		$this->assertSame(1, $this->autoloaderCount());

		(new ClassAliasMap(entries: [self::OLD_SECOND => ['class' => ClassAliasMapBehaviourTarget::class, 'eager' => false]]))->aliasClasses();
		$this->assertSame(1, $this->autoloaderCount());

		$this->assertSame(ClassAliasMapBehaviourTarget::class, get_class(new (self::OLD_SECOND)()));
		$this->assertSame(ClassAliasMapBehaviourTarget::class, get_class(new (self::OLD_FIRST)()));
	}//end testTheFirstLazyMapRegistersTheAutoloaderAndASecondDoesNotAgain()
}//end class
