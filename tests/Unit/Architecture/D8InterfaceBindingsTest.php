<?php

/**
 * Lane D8: the Service classes below are reached through the interface next to them.
 *
 * Each class implements its interface, the interface is bound in a registrar's
 * BINDINGS (a missing binding is a runtime 500 no other unit test catches), and
 * no lib/ file outside lib/Service names the class itself.
 *
 * Every D8 pull request carries this same file. A row whose interface has not
 * landed yet on the branch under test is skipped; once it lands, the row holds.
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class D8InterfaceBindingsTest extends TestCase {

	/**
	 * The app's lib directory.
	 *
	 * @var string
	 */
	private const LIB = __DIR__ . '/../../../lib';

	/**
	 * The component every class below lives in.
	 *
	 * @var string
	 */
	private const COMPONENT = 'Service';

	/**
	 * Class => [its interface, the lib/ files outside the component still allowed to name it].
	 *
	 * @var array<string, array{0: string, 1: array<int, string>}>
	 */
	private const CLASSES = [
		'OCA\OpenRegister\Service\Operations\JobAlertService' => ['OCA\OpenRegister\Service\Operations\JobAlertServiceInterface', []],
		'OCA\OpenRegister\Service\Operations\OperationsJobsService' => ['OCA\OpenRegister\Service\Operations\OperationsJobsServiceInterface', []],
		'OCA\OpenRegister\Service\Party\PartyRoleService' => ['OCA\OpenRegister\Service\Party\PartyRoleServiceInterface', []],
		// The shared ExampleSet test container keys this caller's lookup on the class name.
		'OCA\OpenRegister\Service\Party\PartyService' => [
			'OCA\OpenRegister\Service\Party\PartyServiceInterface',
			['Notifications/Service/ExampleSet/ExampleSetTimelineEntries.php'],
		],
		'OCA\OpenRegister\Service\Quality\QualityScorer' => ['OCA\OpenRegister\Service\Quality\QualityScorerInterface', []],
		'OCA\OpenRegister\Service\Quality\QualityStatisticsService' => ['OCA\OpenRegister\Service\Quality\QualityStatisticsServiceInterface', []],
		'OCA\OpenRegister\Service\Relation\ObjectRelationService' => ['OCA\OpenRegister\Service\Relation\ObjectRelationServiceInterface', []],
		'OCA\OpenRegister\Service\Relation\RelationGraphService' => ['OCA\OpenRegister\Service\Relation\RelationGraphServiceInterface', []],
		'OCA\OpenRegister\Service\Schema\PropertyConversionService' => ['OCA\OpenRegister\Service\Schema\PropertyConversionServiceInterface', []],
		'OCA\OpenRegister\Service\Survivorship\SourceRecordResolver' => ['OCA\OpenRegister\Service\Survivorship\SourceRecordResolverInterface', []],
		'OCA\OpenRegister\Service\Survivorship\SurvivorshipResolver' => ['OCA\OpenRegister\Service\Survivorship\SurvivorshipResolverInterface', []],
		'OCA\OpenRegister\Service\Sync\HarvestPipelineService' => ['OCA\OpenRegister\Service\Sync\HarvestPipelineServiceInterface', []],
		'OCA\OpenRegister\Service\Task\TaskSequenceService' => ['OCA\OpenRegister\Service\Task\TaskSequenceServiceInterface', []],
		'OCA\OpenRegister\Service\Vocabulary\ConceptRepository' => ['OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface', []],
	];

	/**
	 * One row per class.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function classes(): array {
		$rows = [];
		foreach (array_keys(self::CLASSES) as $class) {
			$rows[substr($class, (int)strrpos($class, '\\') + 1)] = [$class];
		}

		return $rows;
	}//end classes()

	/**
	 * The class implements its interface.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testClassImplementsItsInterface(string $class): void {
		$interface = $this->interfaceOf(class: $class);
		$this->assertTrue(is_subclass_of($class, $interface), $class . ' implements ' . $interface);
	}//end testClassImplementsItsInterface()

	/**
	 * ContractBindings binds the interface to the class.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testInterfaceIsBound(string $class): void {
		$interface = $this->interfaceOf(class: $class);
		$this->assertSame($class, ContractBindings::BINDINGS[$interface] ?? null, $interface . ' is bound in ContractBindings::BINDINGS');
	}//end testInterfaceIsBound()

	/**
	 * No lib/ file outside lib/Service names the class, beyond the listed leftovers.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return void
	 */
	#[DataProvider('classes')]
	public function testNoOtherComponentNamesTheClass(string $class): void {
		$this->interfaceOf(class: $class);
		$naming = array_values(array_diff($this->filesNaming(class: $class), self::CLASSES[$class][1]));
		$this->assertSame([], $naming, $class . ' is named outside lib/' . self::COMPONENT . '; type the caller against the interface');
	}//end testNoOtherComponentNamesTheClass()

	/**
	 * The interface of a class, skipping the row while that interface has not landed.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return string The interface name.
	 */
	private function interfaceOf(string $class): string {
		$interface = self::CLASSES[$class][0];
		if (interface_exists($interface) === false) {
			$this->markTestSkipped($interface . ' lands in its own D8 pull request.');
		}

		return $interface;
	}//end interfaceOf()

	/**
	 * The lib/ files outside the component whose code names the class.
	 *
	 * @param string $class The fully qualified class name.
	 *
	 * @return array<int, string> Paths relative to lib/.
	 */
	private function filesNaming(string $class): array {
		$lib   = (string)realpath(self::LIB);
		$found = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib));
		foreach ($files as $file) {
			$path = substr((string)$file, strlen($lib) + 1);
			if (str_ends_with($path, '.php') === true && str_starts_with($path, self::COMPONENT . '/') === false) {
				$found[] = $path;
			}
		}

		return array_values(array_filter($found, fn (string $path): bool => $this->names(path: $lib . '/' . $path, class: $class)));
	}//end filesNaming()

	/**
	 * Whether the code of one file names the class: an import, a qualified name,
	 * or the bare name in the class's own namespace. Comments do not count.
	 *
	 * @param string $path  The absolute file path.
	 * @param string $class The fully qualified class name.
	 *
	 * @return bool True when the file names the class.
	 */
	private function names(string $path, string $class): bool {
		$tokens = token_get_all((string)file_get_contents($path));
		$short  = substr($class, (int)strrpos($class, '\\') + 1);
		$bare   = null;
		if ($this->namespaceOf(tokens: $tokens) . '\\' . $short === $class) {
			$bare = $short;
		}

		foreach ($tokens as $token) {
			if (is_array($token) === true && $this->tokenNames(token: $token, names: [$class, $bare]) === true) {
				return true;
			}
		}

		return false;
	}//end names()

	/**
	 * Whether one token is the class's qualified name, or its bare name where that counts.
	 *
	 * @param array{0: int, 1: string, 2: int} $token The token.
	 * @param array{0: string, 1: string|null}  $names The qualified name and the bare name, or null.
	 *
	 * @return bool True when the token names the class.
	 */
	private function tokenNames(array $token, array $names): bool {
		if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) === true) {
			return ltrim($token[1], '\\') === $names[0];
		}

		return $token[0] === T_STRING && $token[1] === $names[1];
	}//end tokenNames()

	/**
	 * The namespace a file declares.
	 *
	 * @param array<int, mixed> $tokens The file's tokens.
	 *
	 * @return string The namespace, or '' when none.
	 */
	private function namespaceOf(array $tokens): string {
		foreach ($tokens as $index => $token) {
			if (is_array($token) === true && $token[0] === T_NAMESPACE && is_array($tokens[$index + 2] ?? null) === true) {
				return $tokens[$index + 2][1];
			}
		}

		return '';
	}//end namespaceOf()
}//end class
