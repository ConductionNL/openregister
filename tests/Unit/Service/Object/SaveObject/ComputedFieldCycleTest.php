<?php

/**
 * Computed fields in a dependency cycle are not evaluated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-circular-dependency-detection
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Service\Object\SaveObject\ComputedPass;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler;
use OCA\OpenRegister\Twig\MappingExtension;
use OCA\OpenRegister\Twig\MappingRuntimeLoader;
use OCA\OpenRegister\Twig\MappingTwigInstallerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Twig\Environment;

/**
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\ComputedFieldHandler
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Twig\MappingExtension
 */
class ComputedFieldCycleTest extends TestCase {

	/**
	 * The logger double.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A handler with a real sandboxed Twig.
	 *
	 * @return ComputedFieldHandler
	 */
	private function handler(): ComputedFieldHandler {
		$this->logger = $this->createMock(LoggerInterface::class);

		return new ComputedFieldHandler(
			objectMapper: $this->createMock(MagicMapperInterface::class),
			mappingTwig: $this->mappingTwig(),
			logger: $this->logger
		);
	}//end handler()

	/**
	 * A schema with the given properties.
	 *
	 * @param array<string, array<string, mixed>> $properties The properties.
	 *
	 * @return Schema
	 */
	private function schema(array $properties): Schema {
		$schema = new Schema();
		$schema->setId(3);
		$schema->setProperties($properties);

		return $schema;
	}//end schema()

	/**
	 * A computed property.
	 *
	 * @param string $expression The Twig expression.
	 * @param string $evaluateOn The evaluation mode.
	 *
	 * @return array<string, mixed>
	 */
	private function computed(string $expression, string $evaluateOn = 'save'): array {
		return ['type' => 'string', 'computed' => ['expression' => $expression, 'evaluateOn' => $evaluateOn]];
	}//end computed()

	/**
	 * A valid chain is evaluated, and only for its own evaluation mode.
	 *
	 * @return void
	 */
	public function testAValidChainIsEvaluated(): void {
		$schema = $this->schema(
			[
				'aantal' => ['type' => 'number'],
				'prijs' => ['type' => 'number'],
				'subtotaal' => $this->computed(expression: '{{ aantal * prijs }}'),
				'totaal' => $this->computed(expression: '{{ subtotaal + 1 }}'),
				'label' => $this->computed(expression: 'x{{ aantal }}', evaluateOn: 'read'),
				'leeg' => ['type' => 'string', 'computed' => ['expression' => '']],
			]
		);

		$data = $this->handler()->evaluateComputedFields(data: ['aantal' => 2, 'prijs' => 5], pass: new ComputedPass(schema: $schema));

		$this->assertSame(['aantal' => 2, 'prijs' => 5, 'subtotaal' => 10, 'totaal' => 11], $data);
	}//end testAValidChainIsEvaluated()

	/**
	 * A field that references itself is null and logged.
	 *
	 * @return void
	 */
	public function testADirectSelfReferenceIsNull(): void {
		$handler = $this->handler();
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('Circular dependency detected'));

		$data = $handler->evaluateComputedFields(
			data: ['a' => 4],
			pass: new ComputedPass(schema: $this->schema(['a' => $this->computed(expression: '{{ a + 1 }}')]))
		);

		$this->assertNull($data['a']);
	}//end testADirectSelfReferenceIsNull()

	/**
	 * Two fields that reference each other are both null, while a field outside the cycle evaluates.
	 *
	 * @return void
	 */
	public function testAnIndirectCycleNullsBothAndSparesTheRest(): void {
		$handler = $this->handler();
		$this->logger->expects($this->once())->method('warning')
			->with($this->logicalAnd($this->stringContains('a'), $this->stringContains('b')));

		$schema = $this->schema(
			[
				'a' => $this->computed(expression: '{{ b * 2 }}'),
				'b' => $this->computed(expression: '{{ a + 1 }}'),
				'c' => $this->computed(expression: '{{ n + 1 }}'),
			]
		);
		$data = $handler->evaluateComputedFields(data: ['n' => 1], pass: new ComputedPass(schema: $schema));

		$this->assertNull($data['a']);
		$this->assertNull($data['b']);
		$this->assertSame(2, $data['c']);
	}//end testAnIndirectCycleNullsBothAndSparesTheRest()

	/**
	 * A list read analyses the schema once, so the cycle is logged once.
	 *
	 * @return void
	 */
	public function testTheCycleIsAnalysedOncePerSchema(): void {
		$handler = $this->handler();
		$this->logger->expects($this->once())->method('warning');
		$schema = $this->schema(['a' => $this->computed(expression: '{{ a }}', evaluateOn: 'read')]);

		foreach ([1, 2, 3] as $n) {
			$this->assertNull($handler->evaluateComputedFields(data: ['n' => $n], pass: new ComputedPass(schema: $schema, evaluateOn: 'read'))['a']);
		}
	}//end testTheCycleIsAnalysedOncePerSchema()

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
