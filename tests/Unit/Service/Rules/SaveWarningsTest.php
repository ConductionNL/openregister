<?php

/**
 * SaveWarnings: a `warn` validation's message reaches the save response (task 4.3b).
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rules
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rules;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Service\Search\PlaceholderResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer;
use OCA\OpenRegister\Service\Rules\AdministeredValidationRunLog;
use OCA\OpenRegister\Service\Rules\AdministeredValidations;
use OCA\OpenRegister\Service\Rules\ConditionDialect;
use OCA\OpenRegister\Service\Rules\NamedConditionEvaluator;
use OCA\OpenRegister\Service\Rules\NamedConditionLibrary;
use OCA\OpenRegister\Service\Rules\RuleRunRecorder;
use OCA\OpenRegister\Service\Rules\SaveWarnings;
use OCA\OpenRegister\Service\Rules\Support\AdministeredValidationChecks;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Rules\SaveWarnings
 * @covers \OCA\OpenRegister\Service\Rules\AdministeredValidationEnforcer
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Search\Service\Search\PlaceholderResolver
 * @uses \OCA\OpenRegister\Service\Calculation\CalculationEvaluator
 * @uses \OCA\OpenRegister\Service\Rules\AdministeredValidationRunLog
 * @uses \OCA\OpenRegister\Service\Rules\AdministeredValidations
 * @uses \OCA\OpenRegister\Service\Rules\ConditionDialect
 * @uses \OCA\OpenRegister\Service\Rules\NamedConditionEvaluator
 * @uses \OCA\OpenRegister\Service\Rules\NamedConditionLibrary
 * @uses \OCA\OpenRegister\Service\Rules\Support\AdministeredValidationChecks
 *
 * @spec openspec/changes/rules-compose-read-transitions-and-time/specs/object-lifecycle/spec.md
 */
class SaveWarningsTest extends TestCase {

	public function testDrainHandsOverInOrderAndForgets(): void {
		$warnings = new SaveWarnings();
		$warnings->add(object: 'a', warning: ['validation' => 'one', 'message' => 'First']);
		$warnings->add(object: null, warning: ['validation' => 'two', 'message' => 'Second']);

		$this->assertSame(
			[
				['validation' => 'one', 'message' => 'First', 'object' => 'a'],
				['validation' => 'two', 'message' => 'Second', 'object' => null],
			],
			$warnings->drain()
		);
		$this->assertSame([], $warnings->drain());
	}//end testDrainHandsOverInOrderAndForgets()

	public function testKeepsNoMoreThanTheLimitUntilDrained(): void {
		$warnings = new SaveWarnings();
		for ($i = 0; $i < SaveWarnings::LIMIT + 5; $i++) {
			$warnings->add(object: (string)$i, warning: ['validation' => 'v']);
		}

		$drained = $warnings->drain();
		$this->assertCount(SaveWarnings::LIMIT, $drained);
		$this->assertSame('0', $drained[0]['object']);
		$this->assertSame((string)(SaveWarnings::LIMIT - 1), $drained[SaveWarnings::LIMIT - 1]['object']);

		$warnings->add(object: 'after', warning: ['validation' => 'v']);
		$this->assertSame([['validation' => 'v', 'object' => 'after']], $warnings->drain());
	}//end testKeepsNoMoreThanTheLimitUntilDrained()

	public function testAWarningThatSavesIsHandedToTheResponse(): void {
		$warnings = new SaveWarnings();
		$enforcer = $this->enforcer(schema: $this->schema(), warnings: $warnings);

		$refusal = $enforcer->refusalFor(newObject: $this->object(['bedrag' => 60000, 'mandaat' => '']), oldObject: null);

		$this->assertNull($refusal);
		$drained = $warnings->drain();
		$this->assertCount(1, $drained);
		$this->assertSame('mandaat', $drained[0]['validation']);
		$this->assertSame('Boven 50.000 euro is een mandaat verplicht', $drained[0]['message']);
		$this->assertSame(['mandaat'], $drained[0]['properties']);
		$this->assertSame('uuid-1', $drained[0]['object']);
	}//end testAWarningThatSavesIsHandedToTheResponse()

	public function testAWarningWhoseConditionDoesNotHoldHandsNothing(): void {
		$warnings = new SaveWarnings();
		$enforcer = $this->enforcer(schema: $this->schema(), warnings: $warnings);

		$this->assertNull($enforcer->refusalFor(newObject: $this->object(['bedrag' => 10, 'mandaat' => '']), oldObject: null));
		$this->assertSame([], $warnings->drain());
	}//end testAWarningWhoseConditionDoesNotHoldHandsNothing()

	public function testARefusedWriteCarriesItsWarningsInTheRefusalOnly(): void {
		$schema = $this->schema();
		$config = $schema->getConfiguration();
		$config[AdministeredValidations::ANNOTATION]['verplicht'] = [
			'severity' => AdministeredValidations::REFUSE,
			'properties' => ['mandaat'],
			'condition' => ['==' => [['var' => 'mandaat'], '']],
			'message' => 'Mandaat ontbreekt',
		];
		$schema->setConfiguration($config);
		$warnings = new SaveWarnings();
		$enforcer = $this->enforcer(schema: $schema, warnings: $warnings);

		$refusal = $enforcer->refusalFor(newObject: $this->object(['bedrag' => 60000, 'mandaat' => '']), oldObject: null);

		$this->assertIsArray($refusal);
		$this->assertSame('verplicht', $refusal['validation']);
		$this->assertSame('mandaat', $refusal['warnings'][0]['validation']);
		$this->assertSame([], $warnings->drain());
	}//end testARefusedWriteCarriesItsWarningsInTheRefusalOnly()

	public function testWithoutACollectorAWarningStillSaves(): void {
		$enforcer = $this->enforcer(schema: $this->schema(), warnings: null);

		$this->assertNull($enforcer->refusalFor(newObject: $this->object(['bedrag' => 60000, 'mandaat' => '']), oldObject: null));
	}//end testWithoutACollectorAWarningStillSaves()

	private function schema(): Schema {
		$schema = new Schema();
		$schema->setSlug('aanvraag');
		$schema->setConfiguration(
			[
				AdministeredValidations::ANNOTATION => [
					'mandaat' => [
						'severity' => AdministeredValidations::WARN,
						'properties' => ['mandaat'],
						'condition' => ['and' => [['>' => [['var' => 'bedrag'], 50000]], ['==' => [['var' => 'mandaat'], '']]]],
						'message' => 'Boven 50.000 euro is een mandaat verplicht',
					],
				],
			]
		);

		return $schema;
	}//end schema()

	private function object(array $data): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setSchema('aanvraag');
		$entity->setUuid('uuid-1');
		$entity->setRegister('reg');
		$entity->setObject($data);

		return $entity;
	}//end object()

	private function enforcer(Schema $schema, ?SaveWarnings $warnings): AdministeredValidationEnforcer {
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('nl');
		$logger  = $this->createMock(LoggerInterface::class);
		$library = new NamedConditionLibrary();
		$dialect = new ConditionDialect(
			ast: new CalculationEvaluator(placeholders: new PlaceholderResolver(userSession: $this->createMock(IUserSession::class)))
		);

		return new AdministeredValidationEnforcer(
			schemaMapper: $schemas,
			checks: new AdministeredValidationChecks(
				validations: new AdministeredValidations(evaluator: new NamedConditionEvaluator(dialect: $dialect, library: $library)),
				conditions: $library,
				l10n: $l10n
			),
			runLog: new AdministeredValidationRunLog(ruleRuns: $this->createMock(RuleRunRecorder::class), logger: $logger),
			logger: $logger,
			saveWarnings: $warnings
		);
	}//end enforcer()
}//end class
