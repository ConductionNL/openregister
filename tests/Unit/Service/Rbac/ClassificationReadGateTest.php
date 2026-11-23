<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Rbac\ClassificationCaller;
use OCA\OpenRegister\Service\Rbac\ClassificationReadGate;
use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use OCA\OpenRegister\Service\Rbac\ClearanceResolver;
use OCA\OpenRegister\Service\Rbac\ObjectClassification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Read above a caller's clearance is refused; a ceiling refuses its scope outright.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationReadGate
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationCaller
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClearanceResolver
 * @uses   \OCA\OpenRegister\Service\Rbac\ObjectClassification
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses   \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 *
 * @spec openspec/changes/classification-clearance-on-an-object/specs/classification-clearance/spec.md#requirement-read-above-a-callers-clearance-is-refused-on-both-layers
 */
class ClassificationReadGateTest extends TestCase {

	private const LEVELS = ['openbaar', 'intern', 'vertrouwelijk', 'geheim'];

	/**
	 * Warnings the gate's collaborators logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A schema with the four-level block, or without one.
	 *
	 * @param bool $classified Whether the schema declares the block.
	 *
	 * @return Schema
	 */
	private static function schema(bool $classified = true): Schema {
		$schema = new Schema();
		$schema->setSlug('document');
		$schema->setProperties(['vertrouwelijkheidaanduiding' => ['type' => 'string']]);
		if ($classified === true) {
			$schema->setConfiguration(
				[
					ClassificationScale::ANNOTATION => [
						'property' => 'vertrouwelijkheidaanduiding',
						'levels' => self::LEVELS,
						'clearances' => [['group' => 'juristen', 'level' => 'geheim'], ['group' => 'medewerkers', 'level' => 'intern']],
						'default' => 'openbaar',
						'ceilings' => [['scope' => 'public', 'atOrAbove' => 'vertrouwelijk']],
					],
				]
			);
		}

		return $schema;
	}

	/**
	 * An object carrying a label, or none.
	 *
	 * @param string|null $label The label.
	 *
	 * @return ObjectEntity
	 */
	private static function object(?string $label): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('doc-1');
		$data = ['titel' => 'Besluit'];
		if ($label !== null) {
			$data['vertrouwelijkheidaanduiding'] = $label;
		}

		$object->setObject($data);
		return $object;
	}

	/**
	 * The gate over real collaborators and a logger recording warnings.
	 *
	 * @return ClassificationReadGate
	 */
	private function gate(): ClassificationReadGate {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			}
		);

		return new ClassificationReadGate(new ObjectClassification($logger), new ClearanceResolver($logger), $logger);
	}

	/**
	 * A user cleared one level short is refused, naming both levels; one cleared high enough is not.
	 *
	 * @return void
	 */
	public function testReadAboveTheClearanceIsRefusedBothDirections(): void {
		$gate = $this->gate();

		$this->assertSame(
			'The object is classified "vertrouwelijk", above the caller\'s clearance "intern"',
			$gate->refusal(self::schema(), self::object('vertrouwelijk'), new ClassificationCaller('mila', ['medewerkers'], 'read'))
		);
		$this->assertNull($gate->refusal(self::schema(), self::object('intern'), new ClassificationCaller('mila', ['medewerkers'], 'read')));
		$this->assertNull($gate->refusal(self::schema(), self::object('geheim'), new ClassificationCaller('jan', ['medewerkers', 'juristen'], 'read')));
	}

	/**
	 * An unmapped user takes the default, an administrator the top, and an unknown label ranks highest.
	 *
	 * @return void
	 */
	public function testDefaultAdministratorAndUnknownLabel(): void {
		$gate = $this->gate();

		$this->assertNotNull($gate->refusal(self::schema(), self::object('intern'), new ClassificationCaller('piet', ['sport'], 'read')));
		$this->assertNull($gate->refusal(self::schema(), self::object('openbaar'), new ClassificationCaller('piet', ['sport'], 'read')));
		$this->assertNull($gate->refusal(self::schema(), self::object('geheim'), new ClassificationCaller('beheer', ['admin'], 'read')));

		$this->assertSame(
			'The object is classified "geheim", above the caller\'s clearance "intern"',
			$gate->refusal(self::schema(), self::object(null), new ClassificationCaller('mila', ['medewerkers'], 'read'))
		);
		$this->assertStringContainsString('Object "doc-1" carries nothing', implode("\n", $this->warnings));
	}

	/**
	 * Clearance governs reads only; other actions are left to the rules.
	 *
	 * @return void
	 */
	public function testClearanceGovernsReadsOnly(): void {
		$this->assertNull($this->gate()->refusal(self::schema(), self::object('geheim'), new ClassificationCaller('mila', ['medewerkers'], 'update')));
	}

	/**
	 * A ceiling refuses the anonymous caller at or above its level, on every action, and only them.
	 *
	 * @return void
	 */
	public function testACeilingRefusesTheAnonymousScope(): void {
		$gate = $this->gate();
		$refusal = 'The classification ceiling for "public" at or above "vertrouwelijk" refuses this object';

		$this->assertSame($refusal, $gate->refusal(self::schema(), self::object('vertrouwelijk'), new ClassificationCaller(null, [], 'read')));
		$this->assertSame($refusal, $gate->refusal(self::schema(), self::object('geheim'), new ClassificationCaller(null, [], 'update')));
		$this->assertNull($gate->refusal(self::schema(), self::object('openbaar'), new ClassificationCaller(null, [], 'read')));
		$this->assertNull($gate->refusal(self::schema(), self::object('geheim'), new ClassificationCaller('jan', ['juristen'], 'read')));
	}

	/**
	 * A schema without the block refuses nothing.
	 *
	 * @return void
	 */
	public function testASchemaWithoutTheBlockRefusesNothing(): void {
		$this->assertNull($this->gate()->refusal(self::schema(classified: false), self::object('geheim'), new ClassificationCaller(null, [], 'read')));
	}
}
