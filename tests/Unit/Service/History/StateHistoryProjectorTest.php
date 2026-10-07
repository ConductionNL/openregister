<?php

/**
 * Unit tests for the state-history projector.
 *
 * The property a transition is projected under is the one the SCHEMA declares.
 * The event names the action and the two states but not the field they live
 * in, and the shortcut of reading the key that changed in the payload would
 * project whatever the pipeline attached.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\History;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\StateHistory;
use OCA\OpenRegister\Db\StateHistoryMapper;
use OCA\OpenRegister\Service\History\StateHistoryProjector;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class StateHistoryProjectorTest extends TestCase {

	private StateHistoryMapper&MockObject $mapper;

	private SchemaMapper&MockObject $schemaMapper;

	private StateHistoryProjector $projector;

	protected function setUp(): void {
		parent::setUp();

		$this->mapper = $this->createMock(StateHistoryMapper::class);
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->projector = new StateHistoryProjector(
			$this->mapper,
			$this->schemaMapper,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A real Schema: Entity getters are magic and a mock cannot answer
	 * getConfiguration() at all.
	 *
	 * @param array  $configuration The schema configuration.
	 * @param string $slug          The slug.
	 *
	 * @return Schema
	 */
	private function schema(array $configuration, string $slug = 'zaak'): Schema {
		$schema = new Schema();
		$schema->setSlug($slug);
		$schema->setTitle('Zaak');
		$schema->setConfiguration($configuration);
		return $schema;
	}//end schema()

	/**
	 * A transition closes the interval the object is leaving and opens one at
	 * the property the schema declares.
	 *
	 * @return void
	 */
	public function testATransitionClosesTheOldIntervalAndOpensANewOne(): void {
		$schema = $this->schema(['x-openregister-lifecycle' => ['field' => 'status']]);
		$at = new DateTime('2026-03-01 10:00:00');

		$this->mapper->expects($this->once())
			->method('closeOpenInterval')
			->with('uuid-1', 'status', $at)
			->willReturn(1);

		$written = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				function (StateHistory $interval) use (&$written): StateHistory {
					$written = $interval;
					return $interval;
				}
			);

		$this->assertTrue(
			$this->projector->record(
				objectUuid: 'uuid-1',
				schema: $schema,
				register: 'zaken',
				to: 'bezwaar',
				stampedAt: $at
			)
		);

		$this->assertInstanceOf(StateHistory::class, $written);
		$this->assertSame('status', $written->getProperty());
		$this->assertSame('bezwaar', $written->getValue());
		$this->assertSame('uuid-1', $written->getObjectUuid());
		$this->assertNull($written->getLeftAt(), 'the new interval is the one the object is in now');
	}//end testATransitionClosesTheOldIntervalAndOpensANewOne()

	/**
	 * A schema that declares no lifecycle field projects nothing. It is the
	 * ordinary case for most schemas, and it must not write a row under a
	 * guessed property name.
	 *
	 * @return void
	 */
	public function testASchemaWithoutADeclaredLifecycleFieldProjectsNothing(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->mapper->expects($this->never())->method('closeOpenInterval');

		$this->assertFalse(
			$this->projector->record(
				objectUuid: 'uuid-1',
				schema: $this->schema(['x-openregister-something-else' => ['field' => 'status']]),
				register: 'zaken',
				to: 'bezwaar',
				stampedAt: new DateTime()
			)
		);
	}//end testASchemaWithoutADeclaredLifecycleFieldProjectsNothing()

	/**
	 * An unresolvable schema projects nothing rather than guessing.
	 *
	 * @return void
	 */
	public function testAnUnresolvableSchemaProjectsNothing(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->assertFalse(
			$this->projector->record(
				objectUuid: 'uuid-1',
				schema: null,
				register: 'zaken',
				to: 'bezwaar',
				stampedAt: new DateTime()
			)
		);
	}//end testAnUnresolvableSchemaProjectsNothing()

	/**
	 * The filterable properties are the DECLARED ones, gathered from the
	 * schemas themselves.
	 *
	 * @return void
	 */
	public function testTheProjectedPropertiesAreTheDeclaredLifecycleFields(): void {
		$this->schemaMapper->method('findAll')->willReturn(
			[
				$this->schema(['x-openregister-lifecycle' => ['field' => 'status']], 'zaak'),
				$this->schema(['x-openregister-lifecycle' => ['property' => 'fase']], 'besluit'),
				$this->schema([], 'contact'),
				$this->schema(['x-openregister-lifecycle' => ['field' => 'status']], 'taak'),
			]
		);

		$properties = $this->projector->projectedProperties();

		sort($properties);
		$this->assertSame(['fase', 'status'], $properties);
	}//end testTheProjectedPropertiesAreTheDeclaredLifecycleFields()

	/**
	 * A schema lookup that cannot run answers no properties, which the caller
	 * turns into a refusal naming the property. It never answers "everything
	 * is filterable".
	 *
	 * @return void
	 */
	public function testAFailingSchemaLookupAnswersNoProjectedProperties(): void {
		$this->schemaMapper->method('findAll')->willThrowException(new \RuntimeException('no database'));

		$this->assertSame([], $this->projector->projectedProperties());
	}//end testAFailingSchemaLookupAnswersNoProjectedProperties()
}//end class
