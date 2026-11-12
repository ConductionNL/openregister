<?php

/**
 * The F02 survivorship, dedup, placeholder, source-fetcher and task-adapter classes are reached through their interfaces.
 *
 * Each class listed here was named directly by a lib file in another component.
 * It now has an interface next to it; outside callers type against that interface
 * (or get an instance from a factory inside the class's own component), and the
 * registrar of the class's own component aliases the interface to the class. A
 * missing alias is a runtime 500 that no other unit test sees, so this test pins
 * the binding as well as the implementation and the callers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapter;
use OCA\OpenRegister\Notifications\Service\Notification\TaskObjectAdapters;
use OCA\OpenRegister\Search\SearchRegistrar;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicy;
use OCA\OpenRegister\Search\Service\Quality\DedupCreatePolicyInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolverInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolvers;
use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolver;
use OCA\OpenRegister\Service\Survivorship\TrustTierResolverInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherLookupInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class F02RuntimeInterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface outside callers type against, and the registrar that binds it.
	 *
	 * TaskObjectAdapter is an ObjectEntity built per task by TaskObjectAdapters::forTask(); callers
	 * type it as the entity, so nothing is bound for it.
	 *
	 * @var array<class-string, array{class-string, class-string|null}>
	 */
	private const CLASSES = [
		DedupCreatePolicy::class => [DedupCreatePolicyInterface::class, SearchRegistrar::class],
		PlaceholderResolver::class => [PlaceholderResolverInterface::class, SearchRegistrar::class],
		SourceFetcherRegistry::class => [SourceFetcherLookupInterface::class, ContractBindings::class],
		TaskObjectAdapter::class => [ObjectEntity::class, null],
		TrustTierResolver::class => [TrustTierResolverInterface::class, ContractBindings::class],
	];

	/**
	 * Outside files still allowed to name the class, and why.
	 *
	 * None: no outside file names any of these classes.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [];

	/**
	 * One case per class.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function classes(): array {
		$cases = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$cases[$class] = [$class];
		}

		return $cases;
	}//end classes()

	/**
	 * One case per class whose interface the container resolves.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function boundClasses(): array {
		return array_filter(self::classes(), static fn (array $case): bool => self::CLASSES[$case[0]][1] !== null);
	}//end boundClasses()

	/**
	 * The class implements its interface (or, for an entity adapter, extends the entity).
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		$contract = self::CLASSES[$class][0];
		$this->assertTrue(condition: (interface_exists($contract) || class_exists($contract)), message: $contract . ' exists');
		$this->assertTrue(condition: is_a($class, $contract, true), message: $class . ' is a ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * The interface is bound to the class in the registrar of the class's component.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('boundClasses')]
	public function testTheInterfaceIsBound(string $class): void {
		[$contract, $registrar] = self::CLASSES[$class];
		$this->assertSame(expected: explode('\\', $class)[2], actual: explode('\\', (string) $registrar)[2], message: 'Bound in its own component');
		$bindings = constant($registrar . '::BINDINGS');
		$this->assertArrayHasKey(key: $contract, array: $bindings);
		$this->assertSame(expected: $class, actual: $bindings[$contract]);
	}//end testTheInterfaceIsBound()

	/**
	 * No lib file outside the class's component names the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOutsideFileNamesTheClass(string $class): void {
		$naming = [];
		foreach ($this->outsideFiles(class: $class) as $relative => $path) {
			if ($this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(expected: (self::KEPT_CALLERS[$class] ?? []), actual: $naming, message: 'Type against ' . self::CLASSES[$class][0] . ' instead.');
	}//end testNoOutsideFileNamesTheClass()

	/**
	 * Each factory the outside callers use hands back the class itself.
	 *
	 * @return array<string, array{callable(self): object, class-string}>
	 */
	public static function factories(): array {
		return [
			TaskObjectAdapter::class => [static fn (self $test): object => TaskObjectAdapters::forTask(), TaskObjectAdapter::class],
			PlaceholderResolver::class => [static fn (self $test): object => PlaceholderResolvers::forSession(userSession: $test->createMock(IUserSession::class)), PlaceholderResolver::class],
		];
	}//end factories()

	/**
	 * The factory builds the same class the outside callers used to construct.
	 *
	 * @param callable     $build Calls the factory.
	 * @param class-string $class The class it must build.
	 *
	 * @return void
	 */
	#[DataProvider('factories')]
	public function testTheFactoryBuildsTheClass(callable $build, string $class): void {
		$this->assertInstanceOf(expected: $class, actual: $build($this));
	}//end testTheFactoryBuildsTheClass()

	/**
	 * Every lib PHP file outside the component of the class, keyed by its path under lib/.
	 *
	 * @param class-string $class The class.
	 *
	 * @return array<string, string>
	 */
	private function outsideFiles(string $class): array {
		$component = explode('\\', $class)[2];
		$lib       = (string) realpath(self::LIB);
		$files     = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			$relative = substr($file->getPathname(), strlen($lib) + 1);
			if ($file->getExtension() === 'php' && str_starts_with($relative, $component . '/') === false) {
				$files[$relative] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end outsideFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$short    = substr($class, (int) strrpos($class, '\\') + 1);
		$imported = str_contains($source, 'use ' . $class . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->tokenNames(token: $token, class: $class) === true
				|| ($imported === true && $token[0] === T_STRING && $token[1] === $short)
			) {
				return true;
			}
		}

		return false;
	}//end namesClass()

	/**
	 * Whether a qualified-name token is the class.
	 *
	 * @param array{int, string, int} $token The token.
	 * @param class-string            $class The class.
	 *
	 * @return bool
	 */
	private function tokenNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end tokenNames()
}//end class
