<?php

/**
 * A conditionally required property on the save path: what it stops, what it
 * lets through, and that its own failure never blocks a save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\RequiredWhenListener;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCP\DB\Exception as DbException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Listener\RequiredWhenListener
 * @uses   \OCA\OpenRegister\Db\MagicMapper\StatisticsScope
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaCatalog
 * @uses   \OCA\OpenRegister\Event\AbstractObjectEntityEvent
 * @uses   \OCA\OpenRegister\Event\ObjectCreatingEvent
 * @uses   \OCA\OpenRegister\Event\ObjectUpdatingEvent
 * @uses   \OCA\OpenRegister\Event\WriteVetoState
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ListQuery
 * @uses   \OCA\OpenRegister\Listener\Support\EventSchemaLookup
 * @uses   \OCA\OpenRegister\Service\Schemas\RequiredWhenDeclaration
 *
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */
class RequiredWhenListenerTest extends TestCase {

	/**
	 * The logger the listener reports its own failures to.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * A `verzoek` schema whose complaint category is required for a complaint,
	 * and whose KvK number is required for a company above a threshold.
	 *
	 * @return Schema The schema.
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setId(12);
		$schema->setUuid('12121212-1212-1212-1212-121212121212');
		$schema->setSlug('verzoek');
		$schema->setProperties(
			[
				'requestType' => ['type' => 'string'],
				'complaintCategory' => [
					'type' => 'string',
					'x-openregister-required-when' => ['field' => 'requestType', 'op' => 'eq', 'value' => 'Klacht'],
				],
				'isCompany' => ['type' => 'boolean'],
				'amount' => ['type' => 'number'],
				'kvkNummer' => [
					'type' => 'string',
					'x-openregister-required-when' => [
						['field' => 'isCompany', 'op' => 'eq', 'value' => true],
						['field' => 'amount', 'op' => 'gt', 'value' => 1000],
					],
				],
			]
		);

		return $schema;
	}//end schema()

	/**
	 * An object of the schema carrying the given data.
	 *
	 * @param array<string, mixed> $data The object's data.
	 *
	 * @return ObjectEntity The entity.
	 */
	private function entity(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema('12121212-1212-1212-1212-121212121212');
		$object->setObject($data);

		return $object;
	}//end entity()

	/**
	 * The listener over a schema mapper answering the schema, or failing.
	 *
	 * @param bool $schemaReadable False for a mapper that cannot read the schema.
	 *
	 * @return RequiredWhenListener The listener.
	 */
	private function listener(bool $schemaReadable=true): RequiredWhenListener {
		$mapper = $this->createMock(SchemaMapper::class);
		if ($schemaReadable === true) {
			$mapper->method('find')->willReturn($this->schema());
		} else {
			$mapper->method('find')->willThrowException(new DbException('database away'));
		}

		$this->logger = $this->createMock(LoggerInterface::class);

		return new RequiredWhenListener(
			lookup: new EventSchemaLookup(
				new SchemaCatalog($this->createMock(RegisterMapperInterface::class), $mapper, $this->createMock(MagicMapperInterface::class)),
				$this->logger
			)
		);
	}//end listener()

	/**
	 * A complaint without a category is refused with 422 data naming the property and the condition.
	 *
	 * @return void
	 */
	public function testAComplaintWithoutACategoryIsRefused(): void {
		$event = new ObjectCreatingEvent($this->entity(['requestType' => 'Klacht']));

		$this->listener()->handle($event);

		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(RequiredWhenListener::ERROR_CODE, $event->getErrors()['code']);
		$this->assertSame('complaintCategory: Required when requestType eq Klacht.', $event->getErrors()['message']);
		$this->assertSame(
			[['property' => 'complaintCategory', 'message' => 'Required when requestType eq Klacht']],
			$event->getErrors()['errors']
		);
	}//end testAComplaintWithoutACategoryIsRefused()

	/**
	 * The same complaint with a category, and a question without one, are created.
	 *
	 * @return void
	 */
	public function testTheRuleOnlyAppliesWhileItsConditionHolds(): void {
		$withCategory = new ObjectCreatingEvent($this->entity(['requestType' => 'Klacht', 'complaintCategory' => 'bejegening']));
		$question = new ObjectCreatingEvent($this->entity(['requestType' => 'Vraag']));

		$this->listener()->handle($withCategory);
		$this->listener()->handle($question);

		foreach ([$withCategory, $question] as $event) {
			$this->assertFalse($event->isPropagationStopped());
			$this->assertSame([], $event->getErrors());
		}
	}//end testTheRuleOnlyAppliesWhileItsConditionHolds()

	/**
	 * An empty string and an empty list are as empty as a missing value.
	 *
	 * @return void
	 */
	public function testABlankValueIsAsEmptyAsAMissingOne(): void {
		foreach (['', [], null] as $blank) {
			$event = new ObjectCreatingEvent($this->entity(['requestType' => 'Klacht', 'complaintCategory' => $blank]));
			$this->listener()->handle($event);
			$this->assertTrue($event->isPropagationStopped());
		}
	}//end testABlankValueIsAsEmptyAsAMissingOne()

	/**
	 * Every broken rule is refused at once, and a list of conditions applies only when all hold.
	 *
	 * @return void
	 */
	public function testEveryBrokenRuleIsRefusedTogether(): void {
		$both = new ObjectCreatingEvent($this->entity(['requestType' => 'Klacht', 'isCompany' => true, 'amount' => 5000]));
		$smallCompany = new ObjectCreatingEvent($this->entity(['requestType' => 'Vraag', 'isCompany' => true, 'amount' => 50]));

		$this->listener()->handle($both);
		$this->listener()->handle($smallCompany);

		$this->assertSame(['complaintCategory', 'kvkNummer'], array_column($both->getErrors()['errors'], 'property'));
		$this->assertStringContainsString(
			'kvkNummer: Required when isCompany eq true and amount gt 1000.',
			$both->getErrors()['message']
		);
		$this->assertFalse($smallCompany->isPropagationStopped());
	}//end testEveryBrokenRuleIsRefusedTogether()

	/**
	 * An update is judged on the stored object with the write laid over it.
	 *
	 * @return void
	 */
	public function testAnUpdateIsJudgedOnTheMergedObject(): void {
		$patchOther = new ObjectUpdatingEvent(
			$this->entity(['amount' => 10]),
			$this->entity(['requestType' => 'Klacht'])
		);
		$becomesComplaint = new ObjectUpdatingEvent(
			$this->entity(['requestType' => 'Klacht']),
			$this->entity(['requestType' => 'Vraag'])
		);
		$fillsCategory = new ObjectUpdatingEvent(
			$this->entity(['complaintCategory' => 'bejegening']),
			$this->entity(['requestType' => 'Klacht'])
		);
		$clearsCategory = new ObjectUpdatingEvent(
			$this->entity(['complaintCategory' => '']),
			$this->entity(['requestType' => 'Klacht', 'complaintCategory' => 'bejegening'])
		);

		foreach ([$patchOther, $becomesComplaint, $fillsCategory, $clearsCategory] as $event) {
			$this->listener()->handle($event);
		}

		$this->assertTrue($patchOther->isPropagationStopped());
		$this->assertTrue($becomesComplaint->isPropagationStopped());
		$this->assertFalse($fillsCategory->isPropagationStopped());
		$this->assertTrue($clearsCategory->isPropagationStopped());
	}//end testAnUpdateIsJudgedOnTheMergedObject()

	/**
	 * A schema that cannot be read lets the save through and says so in the log.
	 *
	 * @return void
	 */
	public function testAnUnreadableSchemaLetsTheSaveThroughAndIsLogged(): void {
		$listener = $this->listener(schemaReadable: false);
		$this->logger->expects($this->once())->method('warning')->with(
			$this->stringContains('[RequiredWhenListener] The guard itself failed, allowing the save: database away')
		);

		$event = new ObjectCreatingEvent($this->entity(['requestType' => 'Klacht']));
		$listener->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnUnreadableSchemaLetsTheSaveThroughAndIsLogged()

	/**
	 * An object without a schema is not judged at all.
	 *
	 * @return void
	 */
	public function testAnObjectWithoutASchemaIsNotJudged(): void {
		$object = new ObjectEntity();
		$object->setObject(['requestType' => 'Klacht']);
		$event = new ObjectCreatingEvent($object);

		$this->listener()->handle($event);

		$this->assertFalse($event->isPropagationStopped());
	}//end testAnObjectWithoutASchemaIsNotJudged()
}//end class
