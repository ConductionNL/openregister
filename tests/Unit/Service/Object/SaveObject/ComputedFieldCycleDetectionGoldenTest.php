<?php

/**
 * Golden tests for ComputedFieldHandler::detectCircularDependencies.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * Pins the exact cycles, their order and their paths, for a range of graphs.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler
 */
class ComputedFieldCycleDetectionGoldenTest extends TestCase {

	/**
	 * A computed property with an expression.
	 *
	 * @param mixed $expression The expression.
	 *
	 * @return array The property definition.
	 */
	private static function computed(mixed $expression): array {
		return ['type' => 'string', 'computed' => ['expression' => $expression]];
	}//end computed()

	/**
	 * Schemas and their cycles.
	 *
	 * @return array<string, array{0: array, 1: array}>
	 */
	public static function graphs(): array {
		return [
			'no properties' => [[], []],
			'no computed' => [['a' => ['type' => 'string']], []],
			'computed not an array' => [['a' => ['computed' => 'x {{ a }}']], []],
			'self reference' => [['a' => self::computed('{{ a }}')], [['a', 'a']]],
			'two-cycle' => [
				['b' => self::computed('{{ a }}'), 'a' => self::computed('{{ b }}')],
				[['b', 'a', 'b']],
			],
			'three-cycle with entry' => [
				[
					'x' => self::computed('{{ c }}'),
					'c' => self::computed('{{ a }}'),
					'a' => self::computed('{{ b }}'),
					'b' => self::computed('{{ c }} {{ plain }}'),
					'plain' => ['type' => 'string'],
				],
				[['c', 'a', 'b', 'c']],
			],
			'two cycles sharing a node' => [
				[
					'a' => self::computed('{{ b }}{{ c }}'),
					'b' => self::computed('{{ a }}'),
					'c' => self::computed('{{ a }}'),
				],
				[['a', 'b', 'a'], ['a', 'c', 'a']],
			],
			'same cycle reached twice is reported once' => [
				[
					'p' => self::computed('{{ q }}'),
					'q' => self::computed('{{ r }}'),
					'r' => self::computed('{{ q }}{{ p }}'),
				],
				[['q', 'r', 'q'], ['p', 'q', 'r', 'p']],
			],
			'duplicate references' => [
				['a' => self::computed('{{ b }} {{ b }} {% if b %}{{ a }}{% endif %}'), 'b' => self::computed('1')],
				[['a', 'a']],
			],
			'empty and non string expressions' => [
				['a' => self::computed(''), 'b' => self::computed(5), 'c' => ['computed' => []], 'd' => self::computed('{{ a }}{{ b }}{{ c }}{{ d }}')],
				[['d', 'd']],
			],
			'ref lookups are not edges' => [
				['a' => self::computed('{{ _ref.a }}')],
				[],
			],
			'acyclic chain' => [
				['a' => self::computed('{{ b }}'), 'b' => self::computed('{{ c }}'), 'c' => self::computed('{{ input }}')],
				[],
			],
			'acyclic diamond then cycle' => [
				[
					'a' => self::computed('{{ b }}{{ c }}'),
					'b' => self::computed('{{ d }}'),
					'c' => self::computed('{{ d }}'),
					'd' => self::computed('1'),
					'z' => self::computed('{{ y }}'),
					'y' => self::computed('{{ z }}'),
				],
				[['z', 'y', 'z']],
			],
		];
	}//end graphs()

	/**
	 * Build the handler.
	 *
	 * @return ComputedFieldHandler
	 */
	private function handler(): ComputedFieldHandler {
		return new ComputedFieldHandler(
			objectMapper: $this->createMock(MagicMapperInterface::class),
			mappingTwig: $this->mappingTwig(),
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end handler()

	/**
	 * Detect the cycles.
	 *
	 * @param array $properties The schema properties.
	 * @param array $expected The cycles.
	 *
	 * @return void
	 */
	#[DataProvider('graphs')]
	public function testDetect(array $properties, array $expected): void {
		$schema = new Schema();
		$schema->setProperties($properties);

		$this->assertSame($expected, $this->handler()->detectCircularDependencies($schema));
	}//end testDetect()

	/**
	 * A computed property under an integer-like name reaches the graph walk as
	 * an int key and is refused by its string parameter (current behaviour).
	 *
	 * @return void
	 */
	public function testAnIntegerLikePropertyNameIsATypeError(): void {
		$schema = new Schema();
		$schema->setProperties(['10' => self::computed('{{ a }}'), 'a' => self::computed('1')]);

		$this->expectException(\TypeError::class);
		$this->handler()->detectCircularDependencies($schema);
	}//end testAnIntegerLikePropertyNameIsATypeError()

	/**
	 * The mapping extension and a stand-in runtime loader, installed as before.
	 *
	 * @return MappingTwigInstallerInterface The installer.
	 */
	private function mappingTwig(): MappingTwigInstallerInterface {
		$installer = $this->createMock(MappingTwigInstallerInterface::class);
		$loader    = $this->createMock(MappingRuntimeLoader::class);
		$installer->method('installOn')->willReturnCallback(
			static function (Environment $twig) use ($loader): void {
				$twig->addExtension(new MappingExtension());
				$twig->addRuntimeLoader($loader);
			}
		);

		return $installer;
	}//end mappingTwig()
}//end class
