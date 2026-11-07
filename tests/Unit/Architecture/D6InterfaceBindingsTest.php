<?php

/**
 * The flow classes hidden behind an interface stay hidden.
 *
 * Each class below is reached from other lib/ components only through the
 * interface next to it. The test pins the three things that keep it that way:
 * the class implements the interface, the component's registrar binds the
 * interface to the class (a missing binding is a runtime 500 that no other unit
 * test catches), and no lib/ file outside the class's own component names the
 * concrete class.
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
use OCA\OpenRegister\Service\Flow\FlowNodePreflight;
use OCA\OpenRegister\Service\Flow\FlowNodePreflightInterface;
use OCA\OpenRegister\Service\Flow\FlowScheduleService;
use OCA\OpenRegister\Service\Flow\FlowScheduleServiceInterface;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNode;
use OCA\OpenRegister\Service\Flow\Nodes\UserTaskNodeInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class D6InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * Class => [the interface its outside callers use, the registrar that binds it].
	 *
	 * @var array<class-string, array{0: class-string, 1: class-string}>
	 */
	private const HIDDEN = [
		FlowNodePreflight::class => [FlowNodePreflightInterface::class, ContractBindings::class],
		FlowScheduleService::class => [FlowScheduleServiceInterface::class, ContractBindings::class],
		UserTaskNode::class => [UserTaskNodeInterface::class, ContractBindings::class],
	];

	/**
	 * Each class implements the interface its callers type against.
	 *
	 * @return void
	 */
	public function testEachClassImplementsItsInterface(): void {
		foreach (self::HIDDEN as $class => [$interface]) {
			$this->assertTrue(interface_exists($interface), $interface . ' exists');
			$this->assertContains($interface, class_implements($class), $class . ' implements ' . $interface);
		}
	}//end testEachClassImplementsItsInterface()

	/**
	 * Each interface is bound to its class in the component's registrar.
	 *
	 * @return void
	 */
	public function testEachInterfaceIsBoundInItsRegistrar(): void {
		foreach (self::HIDDEN as $class => [$interface, $registrar]) {
			$bindings = constant($registrar . '::BINDINGS');
			$this->assertSame($class, $bindings[$interface] ?? null, $interface . ' is bound in ' . $registrar);
		}
	}//end testEachInterfaceIsBoundInItsRegistrar()

	/**
	 * No lib/ file outside the class's own component names the concrete class.
	 *
	 * @return void
	 */
	public function testNoOtherComponentNamesTheClass(): void {
		$files = $this->libFiles();
		foreach (array_keys(self::HIDDEN) as $class) {
			$component = explode('\\', $class)[2];
			$outside   = array_filter(
				$files,
				fn (string $path): bool => str_starts_with($path, $component . '/') === false
			);
			$naming    = array_values(array_filter($outside, fn (string $path): bool => $this->names(path: $path, class: $class)));
			$this->assertSame([], $naming, $class . ' is named outside lib/' . $component);
		}
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * Every PHP file under lib/, relative to lib/.
	 *
	 * @return array<int, string>
	 */
	private function libFiles(): array {
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::LIB));
		foreach ($files as $file) {
			$path = (string)$file;
			if (str_ends_with($path, '.php') === true) {
				$found[] = substr($path, strlen(self::LIB) + 1);
			}
		}

		return $found;
	}//end libFiles()

	/**
	 * Whether the code of a lib/ file (comments excluded) names the class.
	 *
	 * An import or a fully qualified name counts, and so does the bare name in
	 * a file that shares the class's namespace.
	 *
	 * @param string $path  The file, relative to lib/.
	 * @param string $class The fully qualified class name.
	 *
	 * @return bool
	 */
	private function names(string $path, string $class): bool {
		$source = (string)file_get_contents(self::LIB . '/' . $path);
		$names  = $this->codeNames(source: $source);
		if (in_array($class, $names['qualified'], true) === true) {
			return true;
		}

		$namespace = substr($class, 0, (int)strrpos($class, '\\'));
		$local     = preg_match('/^namespace\s+' . preg_quote($namespace, '/') . ';/m', $source) === 1;
		return $local === true && in_array(substr($class, strlen($namespace) + 1), $names['bare'], true) === true;
	}//end names()

	/**
	 * The names a PHP source uses in code, split into qualified and bare ones.
	 *
	 * Comments are separate tokens, so they never contribute a name.
	 *
	 * @param string $source The PHP source.
	 *
	 * @return array{qualified: array<int, string>, bare: array<int, string>}
	 */
	private function codeNames(string $source): array {
		$names = ['qualified' => [], 'bare' => []];
		foreach (\PhpToken::tokenize($source) as $token) {
			if ($token->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) === true) {
				$names['qualified'][] = ltrim($token->text, '\\');
			} else if ($token->is(T_STRING) === true) {
				$names['bare'][] = $token->text;
			}
		}

		return $names;
	}//end codeNames()
}//end class
