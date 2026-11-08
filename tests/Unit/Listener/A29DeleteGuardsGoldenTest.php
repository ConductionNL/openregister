<?php

/**
 * Golden outcomes of the delete and save guard listeners
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\ConceptDeleteGuardListener;
use OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener;
use OCA\OpenRegister\Listener\PartyTreeGuardListener;
use OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener;
use OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarWriteGuard;
use OCA\OpenRegister\Service\Party\PartyDefinition;
use OCA\OpenRegister\Service\Party\PartyPropertyNames;
use OCA\OpenRegister\Service\Party\PartyServiceInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * Pins what each guard allows and what it refuses, with the refusal it carries.
 *
 * Written against the code before its refactor, so a refactor that changes an
 * outcome fails here.
 *
 * @covers \OCA\OpenRegister\Listener\ConceptDeleteGuardListener
 * @covers \OCA\OpenRegister\Listener\FrozenObjectDeleteGuardListener
 * @covers \OCA\OpenRegister\Listener\PartyTreeGuardListener
 * @covers \OCA\OpenRegister\Listener\WorkingCalendarDeleteGuardListener
 */
final class A29DeleteGuardsGoldenTest extends TestCase {

	/**
	 * An object with a schema reference and data.
	 *
	 * @param array<string,mixed> $data The object data.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('11111111-1111-4111-8111-111111111111');
		$object->setSchema('10');
		$object->setObject($data);
		return $object;
	}//end object()

	/**
	 * Run the concept guard on one delete.
	 *
	 * @param array<string,mixed>     $data     The concept data.
	 * @param bool                    $system   Whether the guard calls it system-defined.
	 * @param int                     $count    How many holders it reports.
	 * @param array<string,mixed>|null $scheme  The scheme the repository resolves, or null.
	 * @param bool                    $isConcept Whether the schema is a concept schema.
	 *
	 * @return ObjectDeletingEvent The handled event.
	 */
	private function conceptDelete(array $data, bool $system, int $count, ?array $scheme=null, bool $isConcept=true): ObjectDeletingEvent {
		$guard = $this->createStub(ConceptDeleteGuardInterface::class);
		$guard->method('isConcept')->willReturn($isConcept);
		$guard->method('isSystemDefined')->willReturn($system);
		$guard->method('systemDefinedMessage')->willReturn('system message');
		$guard->method('usage')->willReturnCallback(
			static fn (array $concept, string $schemeUri): array => ['count' => $count, 'holders' => [['scheme' => $schemeUri]]]
		);
		$guard->method('inUseMessage')->willReturn('in use message');
		$concepts = $this->createStub(ConceptRepositoryInterface::class);
		$concepts->method('schemeByReference')->willReturn($scheme);
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());

		$event = new ObjectDeletingEvent($this->object($data));
		(new ConceptDeleteGuardListener($guard, $concepts, $schemas, new NullLogger()))->handle($event);
		return $event;
	}//end conceptDelete()

	/**
	 * A system-defined concept is refused with its code and 409.
	 *
	 * @return void
	 */
	public function testConceptSystemDefinedIsRefused(): void {
		$event = $this->conceptDelete(['uri' => 'u:1', 'inScheme' => 'https://s'], true, 0);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			['code' => 'concept-system-defined', 'message' => 'system message', 'status' => 409, 'concept' => 'u:1', 'systemDefined' => true],
			$event->getErrors()
		);
	}//end testConceptSystemDefinedIsRefused()

	/**
	 * A held concept is refused with its holders.
	 *
	 * @return void
	 */
	public function testConceptInUseIsRefused(): void {
		$event = $this->conceptDelete(['uri' => 'u:1', 'inScheme' => ['uri' => 'https://s']], false, 2);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			['code' => 'concept-in-use', 'message' => 'in use message', 'status' => 409, 'concept' => 'u:1', 'inUse' => 2, 'holders' => [['scheme' => 'https://s']]],
			$event->getErrors()
		);
	}//end testConceptInUseIsRefused()

	/**
	 * A scheme named by reference is resolved to its uri before counting.
	 *
	 * @return void
	 */
	public function testConceptSchemeByReferenceIsResolved(): void {
		$event = $this->conceptDelete(['uri' => 'u:1', 'inScheme' => ['id' => 'ref-1']], false, 1, ['uri' => ' https://resolved ']);
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame([['scheme' => 'https://resolved']], $event->getErrors()['holders']);
	}//end testConceptSchemeByReferenceIsResolved()

	/**
	 * Unused, unresolvable, schemeless and non-concept deletes are allowed.
	 *
	 * @return void
	 */
	public function testConceptAllowedCases(): void {
		$allowed = [
			$this->conceptDelete(['uri' => 'u:1', 'inScheme' => 'https://s'], false, 0),
			$this->conceptDelete(['uri' => 'u:1', 'inScheme' => 'ref'], false, 3, null),
			$this->conceptDelete(['uri' => 'u:1', 'inScheme' => 'ref'], false, 3, ['uri' => '  ']),
			$this->conceptDelete(['uri' => 'u:1', 'inScheme' => '  '], false, 3),
			$this->conceptDelete(['uri' => 'u:1', 'inScheme' => 5], false, 3),
			$this->conceptDelete(['uri' => 'u:1'], true, 3, null, false),
		];
		foreach ($allowed as $event) {
			$this->assertFalse($event->isPropagationStopped());
			$this->assertSame([], $event->getErrors());
		}
	}//end testConceptAllowedCases()

	/**
	 * A failing guard allows the delete (fail open).
	 *
	 * @return void
	 */
	public function testConceptGuardFailureAllows(): void {
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willThrowException(new RuntimeException('db down'));
		$event = new ObjectDeletingEvent($this->object(['uri' => 'u:1']));
		(new ConceptDeleteGuardListener(
			$this->createStub(ConceptDeleteGuardInterface::class),
			$this->createStub(ConceptRepositoryInterface::class),
			$schemas,
			new NullLogger()
		))->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testConceptGuardFailureAllows()

	/**
	 * Run the working calendar guard on one delete.
	 *
	 * @param array<string,mixed> $data     The calendar data.
	 * @param int                 $count    Open timers.
	 * @param bool                $calendar Whether the object is a calendar.
	 * @param bool                $throws   Whether the guard fails.
	 *
	 * @return ObjectDeletingEvent The handled event.
	 */
	private function calendarDelete(array $data, int $count, bool $calendar=true, bool $throws=false): ObjectDeletingEvent {
		$guard = $this->createStub(WorkingCalendarWriteGuard::class);
		$guard->method('isWorkingCalendar')->willReturn($calendar);
		if ($throws === true) {
			$guard->method('deletionBlockers')->willThrowException(new RuntimeException('db down'));
		} else {
			$guard->method('deletionBlockers')->willReturn(['count' => $count, 'timers' => ['t1']]);
		}

		$guard->method('blockedMessage')->willReturn('blocked');
		$event = new ObjectDeletingEvent($this->object($data));
		(new WorkingCalendarDeleteGuardListener($guard, new NullLogger()))->handle($event);
		return $event;
	}//end calendarDelete()

	/**
	 * A calendar named by open timers is refused; every other delete is allowed.
	 *
	 * @return void
	 */
	public function testWorkingCalendarOutcomes(): void {
		$refused = $this->calendarDelete(['slug' => ' cal '], 2);
		$this->assertTrue($refused->isPropagationStopped());
		$this->assertSame(
			['code' => 'working-calendar-in-use', 'message' => 'blocked', 'status' => 409, 'calendar' => 'cal', 'openTimers' => 2, 'timers' => ['t1']],
			$refused->getErrors()
		);
		foreach ([
			$this->calendarDelete(['slug' => 'cal'], 0),
			$this->calendarDelete(['slug' => ' '], 2),
			$this->calendarDelete(['slug' => 'cal'], 2, false),
			$this->calendarDelete(['slug' => 'cal'], 2, true, true),
		] as $allowed) {
			$this->assertFalse($allowed->isPropagationStopped());
			$this->assertSame([], $allowed->getErrors());
		}
	}//end testWorkingCalendarOutcomes()

	/**
	 * A frozen object is refused with who and when; an unfrozen one is allowed.
	 *
	 * @return void
	 */
	public function testFrozenObjectOutcomes(): void {
		$user = $this->createStub(IUser::class);
		$user->method('getDisplayName')->willReturn('Ann');
		$users = $this->createStub(IUserManager::class);
		$users->method('get')->willReturnMap([['ann', $user], ['gone', null]]);
		$listener = new FrozenObjectDeleteGuardListener($users);

		$cases = [
			[['by' => 'ann', 'at' => '2026-03-01T10:00:00+00:00'], 'This record is locked by Ann since 2026-03-01. Unlock it before deleting it.', 'ann', '2026-03-01T10:00:00+00:00'],
			[['by' => 'gone', 'at' => 'not a date'], 'This record is locked by gone since not a date. Unlock it before deleting it.', 'gone', 'not a date'],
			[['by' => ' ', 'at' => 7], 'This record is locked by an unknown user since an unrecorded date. Unlock it before deleting it.', null, null],
		];
		foreach ($cases as [$marker, $message, $by, $at]) {
			$object = $this->object([]);
			$object->setFrozen($marker);
			$event = new ObjectDeletingEvent($object);
			$listener->handle($event);
			$this->assertTrue($event->isPropagationStopped());
			$this->assertSame(
				['code' => 'object-frozen', 'message' => $message, 'status' => 409, 'frozenBy' => $by, 'frozenAt' => $at, 'uuid' => '11111111-1111-4111-8111-111111111111'],
				$event->getErrors()
			);
		}

		$event = new ObjectDeletingEvent($this->object([]));
		$listener->handle($event);
		$this->assertFalse($event->isPropagationStopped());
	}//end testFrozenObjectOutcomes()

	/**
	 * A party save is refused when the tree guard throws, on both save events.
	 *
	 * @return void
	 */
	public function testPartyTreeOutcomes(): void {
		$parties = $this->createStub(PartyServiceInterface::class);
		$parties->method('definitionFor')->willReturn(new PartyDefinition(properties: new PartyPropertyNames(parentProperty: 'parent')));
		$parties->method('assertParentAllowed')->willReturnCallback(
			static function (string $partyUuid, ?string $parentUuid): void {
				if ($parentUuid === 'cycle') {
					throw new Exception('cycle refused', 422);
				}
			}
		);
		$listener = new PartyTreeGuardListener($parties);

		$create = new ObjectCreatingEvent($this->object(['parent' => ' cycle ']));
		$listener->handle($create);
		$this->assertTrue($create->isPropagationStopped());
		$this->assertSame(['message' => 'cycle refused', 'code' => 422], $create->getErrors());

		$update = new ObjectUpdatingEvent($this->object(['parent' => 'cycle']));
		$listener->handle($update);
		$this->assertTrue($update->isPropagationStopped());
		$this->assertSame(['message' => 'cycle refused', 'code' => 422], $update->getErrors());

		foreach ([['parent' => 'fine'], ['parent' => ' '], []] as $data) {
			$allowed = new ObjectUpdatingEvent($this->object($data));
			$listener->handle($allowed);
			$this->assertFalse($allowed->isPropagationStopped());
		}

		$noTree = $this->createStub(PartyServiceInterface::class);
		$noTree->method('definitionFor')->willReturn(new PartyDefinition());
		$plain = new ObjectCreatingEvent($this->object(['parent' => 'cycle']));
		(new PartyTreeGuardListener($noTree))->handle($plain);
		$this->assertFalse($plain->isPropagationStopped());
	}//end testPartyTreeOutcomes()
}//end class
