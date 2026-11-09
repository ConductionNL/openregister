<?php

/**
 * ADR-011: the shared value objects and the SchemaCatalog aggregate keep their shape.
 *
 * Rule 1 introduces RegisterSchema, RegisterSchemaRef, ObjectRef and
 * ListQuery once, and rule 4 the SchemaCatalog aggregate. Every other lane
 * builds on them, so they stay what the ADR says they are: final, public
 * fields readonly, no setters, no injected service in a value object, and
 * the two Kernel/Contracts classes leaves that name nothing outside
 * lib/Kernel/Contracts.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

class Adr011ValueObjectRulesTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The value objects of rule 1, relative to lib/.
	 *
	 * @var array<int, string>
	 */
	private const VALUE_OBJECTS = [
		'Db/ListQuery.php',
		'Db/RegisterSchema.php',
		'Kernel/Contracts/ObjectRef.php',
		'Kernel/Contracts/RegisterSchemaRef.php',
	];

	/**
	 * The value objects that live in lib/Kernel/Contracts and must stay leaves.
	 *
	 * @var array<int, string>
	 */
	private const KERNEL_LEAVES = [
		'Kernel/Contracts/ObjectRef.php',
		'Kernel/Contracts/RegisterSchemaRef.php',
	];

	/**
	 * The named aggregate of rule 4, relative to lib/.
	 *
	 * @var string
	 */
	private const AGGREGATE = 'Db/SchemaCatalog.php';

	/**
	 * The most members a named aggregate may hold (rule 4).
	 *
	 * @var int
	 */
	private const MAX_AGGREGATE_MEMBERS = 4;

	/**
	 * Every value object and the aggregate is final.
	 *
	 * @return void
	 */
	public function testEveryClassIsFinal(): void {
		$this->assertSame([], $this->violations('notFinal'));
	}//end testEveryClassIsFinal()

	/**
	 * Every public property, promoted or declared, is readonly.
	 *
	 * @return void
	 */
	public function testEveryPublicPropertyIsReadonly(): void {
		$this->assertSame([], $this->violations('mutablePublicProperties'));
	}//end testEveryPublicPropertyIsReadonly()

	/**
	 * No class has a setter.
	 *
	 * @return void
	 */
	public function testNoClassHasASetter(): void {
		$this->assertSame([], $this->violations('setters'));
	}//end testNoClassHasASetter()

	/**
	 * A value object takes no service, mapper, handler or platform interface.
	 *
	 * @return void
	 */
	public function testNoValueObjectTakesAService(): void {
		$offenders = [];
		foreach (self::VALUE_OBJECTS as $file) {
			foreach ($this->servicesTaken(source: $this->source(file: $file)) as $type) {
				$offenders[] = $file . ' → ' . $type;
			}
		}

		$this->assertSame([], $offenders);
	}//end testNoValueObjectTakesAService()

	/**
	 * The aggregate takes mapper interfaces only, and at most four of them.
	 *
	 * @return void
	 */
	public function testTheAggregateTakesAtMostFourMapperInterfaces(): void {
		$types = $this->constructorTypes(source: $this->source(file: self::AGGREGATE));

		$this->assertLessThanOrEqual(self::MAX_AGGREGATE_MEMBERS, count($types));
		$this->assertSame([], $this->nonMapperTypes(types: $types));
	}//end testTheAggregateTakesAtMostFourMapperInterfaces()

	/**
	 * The Kernel/Contracts value objects name no OpenRegister class outside lib/Kernel/Contracts.
	 *
	 * @return void
	 */
	public function testKernelValueObjectsAreLeaves(): void {
		$offenders = [];
		foreach (self::KERNEL_LEAVES as $file) {
			foreach ($this->namesOutsideKernelContracts(source: $this->source(file: $file)) as $class) {
				$offenders[] = $file . ' → ' . $class;
			}
		}

		$this->assertSame([], $offenders);
	}//end testKernelValueObjectsAreLeaves()

	/**
	 * Each check rejects a class that breaks its rule.
	 *
	 * @return void
	 */
	public function testTheChecksRejectABrokenClass(): void {
		$source = "<?php\nnamespace OCA\\OpenRegister\\Kernel\\Contracts;\n"
			. "use OCA\\OpenRegister\\Db\\Register;\n"
			. "use OCA\\OpenRegister\\Service\\ObjectService;\n"
			. "class Broken {\n"
			. "\tpublic string \$name;\n"
			. "\tpublic function __construct(public int \$count, private ObjectService \$objects, Register \$register) {}\n"
			. "\tpublic function setName(string \$name): void { \$this->name = \$name; }\n"
			. "}\n";

		$this->assertTrue($this->notFinal(source: $source));
		$this->assertSame(['$name', '$count'], $this->mutablePublicProperties(source: $source));
		$this->assertSame(['setName'], $this->setters(source: $source));
		$this->assertSame(['ObjectService'], $this->servicesTaken(source: $source));
		$this->assertSame(['int', 'ObjectService', 'Register'], $this->nonMapperTypes(types: $this->constructorTypes(source: $source)));
		$this->assertSame(
			['OCA\\OpenRegister\\Db\\Register', 'OCA\\OpenRegister\\Service\\ObjectService'],
			$this->namesOutsideKernelContracts(source: $source)
		);
	}//end testTheChecksRejectABrokenClass()

	/**
	 * The checks accept a class written the way the ADR asks.
	 *
	 * @return void
	 */
	public function testTheChecksAcceptAWellFormedClass(): void {
		$source = "<?php\nnamespace OCA\\OpenRegister\\Kernel\\Contracts;\n"
			. "final class Fine {\n"
			. "\tpublic const LIMIT = 20;\n"
			. "\tpublic function __construct(public readonly string \$name, public readonly ?int \$count=null) {}\n"
			. "\tpublic function settled(): bool { return true; }\n"
			. "\tpublic static function fromParams(array \$params): self { return new self(name: 'x'); }\n"
			. "}\n";

		$this->assertFalse($this->notFinal(source: $source));
		$this->assertSame([], $this->mutablePublicProperties(source: $source));
		$this->assertSame([], $this->setters(source: $source));
		$this->assertSame([], $this->servicesTaken(source: $source));
		$this->assertSame([], $this->namesOutsideKernelContracts(source: $source));
	}//end testTheChecksAcceptAWellFormedClass()

	/**
	 * The offenders of one source check across the five classes.
	 *
	 * @param string $check The name of the check method.
	 *
	 * @return array<int, string> "file → offence" for every offence.
	 */
	private function violations(string $check): array {
		$offenders = [];
		foreach (array_merge(self::VALUE_OBJECTS, [self::AGGREGATE]) as $file) {
			$result = $this->{$check}(source: $this->source(file: $file));
			if ($result === true) {
				$offenders[] = $file;
			}

			if (is_array($result) === true) {
				foreach ($result as $offence) {
					$offenders[] = $file . ' → ' . $offence;
				}
			}
		}

		return $offenders;
	}//end violations()

	/**
	 * The source of a lib file, without comments.
	 *
	 * @param string $file Path relative to lib/.
	 *
	 * @return string The source.
	 */
	private function source(string $file): string {
		$path = self::LIB . '/' . $file;
		$this->assertFileExists($path);

		return (string)file_get_contents($path);
	}//end source()

	/**
	 * The source without comments.
	 *
	 * @param string $source PHP source.
	 *
	 * @return string The code.
	 */
	private function code(string $source): string {
		return (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source);
	}//end code()

	/**
	 * Whether the class in a source is not declared final.
	 *
	 * @param string $source PHP source.
	 *
	 * @return bool True when it is not final.
	 */
	private function notFinal(string $source): bool {
		return preg_match('/^\s*final\s+(readonly\s+)?class\s/m', $this->code(source: $source)) !== 1;
	}//end notFinal()

	/**
	 * The public properties, declared or promoted, that are not readonly.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> The property names with their dollar sign.
	 */
	private function mutablePublicProperties(string $source): array {
		preg_match_all(
			'/\bpublic\s+(?!readonly\b|function\b|const\b|static\s+function\b)(?:static\s+)?(?:[?\\\\\w|]+\s+)?(\$\w+)/',
			$this->code(source: $source),
			$matches
		);

		return $matches[1];
	}//end mutablePublicProperties()

	/**
	 * The methods named as setters.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> The method names.
	 */
	private function setters(string $source): array {
		preg_match_all('/\bfunction\s+(set[A-Z_]\w*)\s*\(/', $this->code(source: $source), $matches);

		return $matches[1];
	}//end setters()

	/**
	 * The declared types of the constructor parameters, in order.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> The types as written, nullable mark removed.
	 */
	private function constructorTypes(string $source): array {
		if (preg_match('/function\s+__construct\s*\((.*?)\)\s*\{/s', $this->code(source: $source), $match) !== 1) {
			return [];
		}

		preg_match_all(
			'/(?:^|,)\s*(?:(?:public|protected|private|readonly)\s+)*\??([\\\\\w|]+)\s+\$\w+/',
			$match[1],
			$params
		);

		return $params[1];
	}//end constructorTypes()

	/**
	 * The constructor parameter types that are services: anything named a
	 * service, mapper, handler, factory, provider or interface, or a platform type.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> The offending types as written.
	 */
	private function servicesTaken(string $source): array {
		return array_values(
			array_filter(
				$this->constructorTypes(source: $source),
				fn (string $type): bool => preg_match(
					'/(Service|Mapper|Handler|Factory|Provider|Interface|Catalog)$|^\\\\?(OCP|Psr)\\\\/',
					$type
				) === 1
			)
		);
	}//end servicesTaken()

	/**
	 * The types that are not mapper interfaces.
	 *
	 * @param array<int, string> $types The types.
	 *
	 * @return array<int, string> The types that do not end in MapperInterface.
	 */
	private function nonMapperTypes(array $types): array {
		return array_values(
			array_filter($types, fn (string $type): bool => str_ends_with($type, 'MapperInterface') === false)
		);
	}//end nonMapperTypes()

	/**
	 * The OpenRegister classes a source names outside lib/Kernel/Contracts,
	 * in use statements or as fully qualified names.
	 *
	 * @param string $source PHP source.
	 *
	 * @return array<int, string> The class names, without a leading backslash.
	 */
	private function namesOutsideKernelContracts(string $source): array {
		$code = $this->code(source: $source);
		preg_match_all('/^use\s+\\\\?([^;\s]+)\s*(?:as\s+\w+)?;/m', $code, $uses);
		$body = (string)preg_replace('/^(namespace|use)\s+[^;]+;/m', '', $code);
		preg_match_all('/\\\\?(OCA\\\\OpenRegister\\\\[A-Za-z0-9_\\\\]+)/', $body, $qualified);

		$named = array_map(
			fn (string $class): string => trim($class, '\\'),
			array_merge($uses[1], $qualified[1])
		);

		return array_values(
			array_unique(
				array_filter(
					$named,
					fn (string $class): bool => str_starts_with($class, 'OCA\\OpenRegister\\') === true
						&& str_starts_with($class, 'OCA\\OpenRegister\\Kernel\\Contracts\\') === false
				)
			)
		);
	}//end namesOutsideKernelContracts()
}//end class
