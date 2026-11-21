<?php

/**
 * Mutation-killing tests for the redactor that shapes an object's history for a reader.
 *
 * Written against the mutants Infection left alive in HistoryEntryRedactor:
 * a property known only from the history is still probed and hidden, the
 * cascade context drops a hidden trigger property, a write-only property
 * change is withheld while other entries stay, a hidden property's
 * relations (and only its own, by dotted prefix) and metadata copies are
 * withheld from `@self`, and every row comes back.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Audit\HistoryEntryRedactor;
use OCA\OpenRegister\Service\Audit\ReadableObject;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class HistoryEntryRedactorMutationTest extends TestCase {
	/**
	 * Test double or fixture for properties.
	 *
	 * @var PropertyRbacHandlerInterface&MockObject
	 */
	private PropertyRbacHandlerInterface&MockObject $properties;

	/**
	 * @var list<array<string, mixed>>
	 */
	private array $probes = [];

	protected function setUp(): void {
		$this->properties = $this->createMock(PropertyRbacHandlerInterface::class);
		// The reader may not see `bsn` or `iban`; `wachtwoord` and a nested `pin` are write-only.
		$this->properties->method('filterReadableProperties')->willReturnCallback(
			function (Schema $schema, array $object): array {
				$this->probes[] = $object;
				unset($object['bsn'], $object['iban']);
				return $object;
			}
		);
		$this->properties->method('stripWriteOnlyProperties')->willReturnCallback(
			static function (Schema $schema, array $object): array {
				unset($object['wachtwoord']);
				foreach ($object as $key => $value) {
					if (is_array($value) === true) {
						unset($object[$key]['pin']);
					}
				}

				return $object;
			}
		);
	}//end setUp()

	private function schema(array $configuration=[]): Schema {
		$schema = new Schema();
		$schema->setId(4);
		$schema->setConfiguration($configuration);

		return $schema;
	}//end schema()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('persoon-1');
		$object->setObject(['naam' => 'Jan']);

		return $object;
	}//end object()

	private function row(array $changed): AuditTrail {
		$row = new AuditTrail();
		$row->setChanged($changed);

		return $row;
	}//end row()

	private function changed(array $changed, array $configuration=[]): array {
		$entries = (new HistoryEntryRedactor($this->properties))->redact(
			[$this->row($changed)],
			new ReadableObject($this->object(), $this->schema($configuration))
		);

		return $entries[0]['changed'];
	}//end changed()

	public function testAPropertyKnownOnlyFromTheHistoryIsProbedAndHidden(): void {
		$changed = $this->changed([
			'bsn' => ['old' => '111', 'new' => '222'],
			'iban' => ['old' => 'NL01', 'new' => 'NL02'],
			'naam' => ['old' => 'Piet', 'new' => 'Jan'],
			'@version' => ['old' => 1, 'new' => 2],
		]);

		$this->assertSame(['naam', '@version'], array_keys($changed));
		$this->assertArrayHasKey('bsn', $this->probes[0]);
		$this->assertNull($this->probes[0]['bsn']);
		$this->assertArrayNotHasKey('@version', $this->probes[0]);
		$this->assertSame('Jan', $this->probes[0]['naam']);
	}//end testAPropertyKnownOnlyFromTheHistoryIsProbedAndHidden()

	public function testTheCascadeDropsAHiddenTriggerProperty(): void {
		$hiddenTrigger = $this->changed([
			'bsn' => ['old' => '1', 'new' => '2'],
			'cascadeContext' => ['property' => 'bsn.nummer', 'triggerObject' => 'x', 'triggerSchema' => 'y'],
		]);
		$visibleTrigger = $this->changed(['cascadeContext' => ['property' => 'naam', 'triggerObject' => 'x']]);

		$this->assertSame(['property' => null, 'triggerObject' => null, 'triggerSchema' => null], $hiddenTrigger['cascadeContext']);
		$this->assertSame('naam', $visibleTrigger['cascadeContext']['property']);
		$this->assertNull($visibleTrigger['cascadeContext']['triggerObject']);
	}//end testTheCascadeDropsAHiddenTriggerProperty()

	public function testAWriteOnlyChangeIsWithheldAndTheRestStays(): void {
		$changed = $this->changed([
			'wachtwoord' => ['old' => 'a', 'new' => 'b'],
			'note' => 'free text',
			'kaart' => ['old' => ['nr' => 1, 'pin' => 1234], 'new' => ['nr' => 2, 'pin' => 4321]],
		]);

		$this->assertSame(
			['note' => 'free text', 'kaart' => ['old' => ['nr' => 1], 'new' => ['nr' => 2]]],
			$changed
		);
	}//end testAWriteOnlyChangeIsWithheldAndTheRestStays()

	public function testSelfWithholdsOnlyTheHiddenPropertysRelationsAndCopies(): void {
		$changed = $this->changed(
			[
				'bsn' => ['old' => '1', 'new' => '2'],
				'@self' => [
					'new' => [
						'relations' => ['bsn.bron' => 'a', 'bsnummer' => 'b', 'bsn' => 'c', 'naam' => 'd'],
						'name' => '123456789',
						'description' => 'Jan',
					],
				],
			],
			['objectNameField' => ' bsn.waarde ', 'objectDescriptionField' => 'naam', 'objectSummaryField' => 'bsn']
		);

		$this->assertArrayNotHasKey('summary', $changed['@self']['new']);

		$this->assertSame(['bsnummer' => 'b', 'naam' => 'd'], $changed['@self']['new']['relations']);
		$this->assertNull($changed['@self']['new']['name']);
		$this->assertSame('Jan', $changed['@self']['new']['description']);
	}//end testSelfWithholdsOnlyTheHiddenPropertysRelationsAndCopies()

	public function testAnExplicitRevealPropertyIsWithheldFromSelf(): void {
		$schema = $this->schema();
		$schema->setProperties(['geheim' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']]]);

		$entries = (new HistoryEntryRedactor($this->properties))->redact(
			[$this->row(['@self' => ['old' => ['relations' => ['geheim' => 'a', 'naam' => 'b']]]])],
			new ReadableObject($this->object(), $schema)
		);

		$this->assertSame(['naam' => 'b'], $entries[0]['changed']['@self']['old']['relations']);
	}//end testAnExplicitRevealPropertyIsWithheldFromSelf()

	public function testEveryRowComesBack(): void {
		$entries = (new HistoryEntryRedactor($this->properties))->redact(
			[$this->row(['naam' => ['old' => 'a', 'new' => 'b']]), $this->row(['naam' => ['old' => 'b', 'new' => 'c']])],
			new ReadableObject($this->object(), $this->schema())
		);

		$this->assertSame(
			[['old' => 'a', 'new' => 'b'], ['old' => 'b', 'new' => 'c']],
			array_map(static fn (array $entry): array => $entry['changed']['naam'], $entries)
		);
	}//end testEveryRowComesBack()
}//end class
