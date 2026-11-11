<?php

/**
 * The A10 flow classes are reached through the interface next to them.
 *
 * Each class listed here had more than ten lib files naming it. It now has a
 * narrow interface beside it; callers type against that interface and
 * ContractBindings aliases it to the class. A missing alias is a runtime 500
 * that no other unit test sees, so this test pins the binding as well as the
 * implementation and the callers.
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

use OCA\OpenRegister\Service\ContractBindings;
use OCA\OpenRegister\Service\Flow\FlowNodeCatalogueInterface;
use OCA\OpenRegister\Service\Flow\FlowNodeRegistry;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculator;
use OCA\OpenRegister\Service\Flow\Timer\SlaCalculatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @coversNothing
 */
final class A10InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => the interface its callers type against.
	 *
	 * @var array<class-string, class-string>
	 */
	private const CLASSES = [
		FlowNodeRegistry::class => FlowNodeCatalogueInterface::class,
		SlaCalculator::class => SlaCalculatorInterface::class,
	];

	/**
	 * Lib files (under lib/) besides the class itself still allowed to name it, and why.
	 *
	 * ContractBindings binds the interface to the class. RegisterFlowNodesEvent
	 * is the public event other apps receive; its constructor stays as it is.
	 *
	 * @var array<class-string, list<string>>
	 */
	private const KEPT_CALLERS = [
		FlowNodeRegistry::class => ['Service/ContractBindings.php', 'Service/Flow/RegisterFlowNodesEvent.php'],
		SlaCalculator::class => ['Service/ContractBindings.php'],
	];

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
	 * The class implements its interface.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheClassImplementsItsInterface(string $class): void {
		$contract = self::CLASSES[$class];
		$this->assertTrue(interface_exists($contract), $contract . ' exists');
		$this->assertContains($contract, class_implements($class), $class . ' implements ' . $contract);
	}//end testTheClassImplementsItsInterface()

	/**
	 * ContractBindings aliases the interface to the class.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testTheInterfaceIsBound(string $class): void {
		$bindings = constant(ContractBindings::class . '::BINDINGS');
		$this->assertArrayHasKey(self::CLASSES[$class], $bindings);
		$this->assertSame($class, $bindings[self::CLASSES[$class]]);
	}//end testTheInterfaceIsBound()

	/**
	 * No other lib file names the class in code.
	 *
	 * @param class-string $class The class.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testOnlyTheKeptCallersNameTheClass(string $class): void {
		$own    = str_replace('\\', '/', substr($class, strlen('OCA\\OpenRegister\\'))) . '.php';
		$naming = [];
		foreach ($this->libFiles() as $relative => $path) {
			if ($relative !== $own && $this->namesClass(source: (string) file_get_contents($path), class: $class) === true) {
				$naming[] = $relative;
			}
		}

		$this->assertSame(self::KEPT_CALLERS[$class] ?? [], $naming, 'Type against ' . self::CLASSES[$class] . ' instead.');
	}//end testOnlyTheKeptCallersNameTheClass()

	/**
	 * Every lib PHP file, keyed by its path under lib/.
	 *
	 * @return array<string, string>
	 */
	private function libFiles(): array {
		$lib   = (string) realpath(self::LIB);
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lib)) as $file) {
			if ($file->getExtension() === 'php') {
				$files[substr($file->getPathname(), strlen($lib) + 1)] = $file->getPathname();
			}
		}

		ksort($files);
		return $files;
	}//end libFiles()

	/**
	 * Whether code (not comments) in this source names the class.
	 *
	 * A short name counts when the file imports the class or shares its namespace.
	 *
	 * @param string       $source The PHP source.
	 * @param class-string $class  The class.
	 *
	 * @return bool
	 */
	private function namesClass(string $source, string $class): bool {
		$cut       = (int) strrpos($class, '\\');
		$short     = substr($class, $cut + 1);
		$namespace = substr($class, 0, $cut);
		$bare      = str_contains($source, 'use ' . $class . ';') || str_contains($source, 'namespace ' . $namespace . ';');
		foreach (token_get_all($source) as $token) {
			if (is_array($token) === false) {
				continue;
			}

			if ($this->qualifiedNames(token: $token, class: $class) === true
				|| ($bare === true && $token[0] === T_STRING && $token[1] === $short)
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
	private function qualifiedNames(array $token, string $class): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === false) {
			return false;
		}

		return ltrim($token[1], '\\') === $class;
	}//end qualifiedNames()
}//end class
