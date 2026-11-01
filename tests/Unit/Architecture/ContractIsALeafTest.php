<?php

/**
 * lib/Contract and lib/Kernel/Contracts are the leaves every component may depend on.
 *
 * They hold interfaces and value types only. A contract may name another
 * contract, the entities in lib/Db, an event or an exception, and nothing
 * else of OpenRegister: a contract that reaches a mapper or a service ties
 * every caller of the contract to that class again, which is the coupling
 * the contract exists to remove.
 *
 * lib/Contract is the contract other apps consume (ADR-084), so it holds
 * exactly the published files. Interfaces between OpenRegister's own
 * components live in lib/Kernel/Contracts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class ContractIsALeafTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The files of the published contract.
	 *
	 * @var array<int, string>
	 */
	private const PUBLISHED = [
		'EmailLinkReaderInterface.php',
		'ObjectEntityInterface.php',
		'ObjectServiceInterface.php',
		'RegisterSlugResolution.php',
		'RegisterSlugResolverInterface.php',
	];

	/**
	 * A Kernel contract names a Kernel entity interface, never a Db class.
	 *
	 * This keeps lib/Kernel a leaf: lib/Db implements Kernel interfaces, so a
	 * Kernel contract that named a Db class would close a Db-Kernel cycle.
	 *
	 * @return void
	 */
	public function testKernelContractsNameNoDbClass(): void {
		$naming = [];
		foreach ((array)glob(self::LIB . '/Kernel/Contracts/*.php') as $path) {
			foreach ($this->referencedClasses(source: (string)file_get_contents((string)$path)) as $class) {
				if (str_starts_with($class, 'OCA\\OpenRegister\\Db\\') === true) {
					$naming[] = basename((string)$path) . ' → ' . $class;
				}
			}
		}

		$this->assertSame(expected: [], actual: $naming, message: 'name the Kernel entity interface instead');
	}//end testKernelContractsNameNoDbClass()

	/**
	 * Every OpenRegister class a contract names is a contract, an entity, an event or an exception.
	 *
	 * @return void
	 */
	public function testContractsNameOnlyKernelTypes(): void {
		$offenders = [];

		$paths = array_merge((array)glob(self::LIB . '/Contract/*.php'), (array)glob(self::LIB . '/Kernel/Contracts/*.php'));
		foreach ($paths as $path) {
			foreach ($this->referencedClasses(source: (string)file_get_contents((string)$path)) as $class) {
				if ($this->isKernelType(class: $class) === false) {
					$offenders[] = basename((string)$path) . ' → ' . $class;
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $offenders,
			message: 'a contract may only name kernel types: ' . implode(', ', $offenders)
		);
	}//end testContractsNameOnlyKernelTypes()

	/**
	 * lib/Contract holds the published contract and nothing else.
	 *
	 * @return void
	 */
	public function testContractHoldsOnlyThePublishedFiles(): void {
		$files = array_map(
			fn (string $path): string => basename($path),
			(array)glob(self::LIB . '/Contract/*.php')
		);
		sort($files);

		$this->assertSame(expected: self::PUBLISHED, actual: $files);
	}//end testContractHoldsOnlyThePublishedFiles()

	/**
	 * The check itself rejects a contract that names a service or a mapper.
	 *
	 * @return void
	 */
	public function testTheCheckRejectsAServiceAndAMapper(): void {
		$source = "<?php\nnamespace OCA\\OpenRegister\\Contract;\n"
			. "use OCA\\OpenRegister\\Service\\ObjectService;\n"
			. "use OCA\\OpenRegister\\Db\\{Schema, SchemaMapper};\n"
			. "interface X { public function a(\\OCA\\OpenRegister\\Event\\ObjectCreatedEvent \$e): void; }\n";

		$rejected = array_values(
			array_filter(
				$this->referencedClasses(source: $source),
				fn (string $class): bool => $this->isKernelType(class: $class) === false
			)
		);

		$this->assertSame(
			expected: ['OCA\\OpenRegister\\Service\\ObjectService', 'OCA\\OpenRegister\\Db\\SchemaMapper'],
			actual: $rejected
		);
	}//end testTheCheckRejectsAServiceAndAMapper()

	/**
	 * The OpenRegister classes a source names in use statements or as fully qualified names.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> Class names without a leading backslash, in order of appearance.
	 */
	private function referencedClasses(string $source): array {
		$code    = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
		$classes = [];

		preg_match_all('/^use\s+([^;]+);/m', $code, $uses);
		foreach ($uses[1] as $use) {
			if (preg_match('/^([^{]*)\{([^}]*)\}/', trim($use), $group) === 1) {
				foreach (explode(',', $group[2]) as $member) {
					$classes[] = trim($group[1]) . trim(explode(' as ', $member)[0]);
				}

				continue;
			}

			$classes[] = trim(explode(' as ', $use)[0]);
		}

		$body = (string)preg_replace('/^use\s+[^;]+;/m', '', $code);
		preg_match_all('/\\\\?(OCA\\\\OpenRegister\\\\[A-Za-z0-9_\\\\]+)/', $body, $qualified);
		foreach ($qualified[1] as $name) {
			$classes[] = rtrim($name, '\\');
		}

		$own = array_filter(
			$classes,
			fn (string $class): bool => str_starts_with(ltrim($class, '\\'), 'OCA\\OpenRegister\\') === true
				&& preg_match('/^OCA\\\\OpenRegister\\\\(Contract|Kernel\\\\Contracts)(\\\\|$)/', ltrim($class, '\\')) !== 1
		);

		return array_values(array_unique(array_map(fn (string $class): string => ltrim($class, '\\'), $own)));
	}//end referencedClasses()

	/**
	 * Whether a class is a kernel type: an entity in lib/Db, an event or an exception.
	 *
	 * @param string $class Fully qualified class name.
	 *
	 * @return bool
	 */
	private function isKernelType(string $class): bool {
		if (str_starts_with($class, 'OCA\\OpenRegister\\Event\\') === true
			|| str_starts_with($class, 'OCA\\OpenRegister\\Exception\\') === true
		) {
			return true;
		}

		if (preg_match('/^OCA\\\\OpenRegister\\\\Db\\\\(\w+)$/', $class, $match) !== 1) {
			return false;
		}

		$path = self::LIB . '/Db/' . $match[1] . '.php';

		return is_file($path) === true
			&& preg_match('/\bextends\s+Entity\b/', (string)file_get_contents($path)) === 1;
	}//end isKernelType()
}//end class
