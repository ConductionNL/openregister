<?php

/**
 * Golden outcomes of the save, sign-in and retention guard listeners
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

use OCA\OpenRegister\BackgroundJob\RelatedRetentionRecalculationJob;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\IdentityClaimsCollectingEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\IdentityClaimsLoginListener;
use OCA\OpenRegister\Listener\ObjectQuotaListener;
use OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener;
use OCA\OpenRegister\Listener\StateFieldRuleListener;
use OCA\OpenRegister\Service\Archival\DependantRetentionIndex;
use OCA\OpenRegister\Service\Lifecycle\StateConditionEvaluatorInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRuleResolverInterface;
use OCA\OpenRegister\Service\Lifecycle\StateFieldLists;
use OCA\OpenRegister\Service\Lifecycle\StateFieldRules;
use OCA\OpenRegister\Service\Quota\ObjectQuotaService;
use OCA\OpenRegister\Service\Rbac\DerivedGrantStore;
use OCA\OpenRegister\Service\Rules\RuleRunRecorderInterface;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\User\Events\UserLoggedInEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use OCA\OpenRegister\Listener\Support\StateFieldRulePlatform;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;

/**
 * Pins what each guard allows, refuses, grants and queues, written against the
 * code before its refactor.
 *
 * @covers \OCA\OpenRegister\Listener\IdentityClaimsLoginListener
 * @covers \OCA\OpenRegister\Listener\ObjectQuotaListener
 * @covers \OCA\OpenRegister\Listener\RelatedRetentionRecalculationListener
 * @covers \OCA\OpenRegister\Listener\StateFieldRuleListener
 */
final class A29SaveGuardsGoldenTest extends TestCase {

	/**
	 * The sign-in event of one user.
	 *
	 * @return UserLoggedInEvent The event.
	 */
	private function signIn(): UserLoggedInEvent {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('ann');
		return new UserLoggedInEvent($user, 'ann', null, false);
	}//end signIn()

	/**
	 * Claims asserted on sign-in are applied; none asserted forgets the derived access.
	 *
	 * @return void
	 */
	public function testIdentityClaimsApplyOrForget(): void {
		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('rules')->willReturn([['claim' => 'dept']]);
		$store->expects($this->once())->method('apply')->with('ann', ['dept' => 'hr'])->willReturn(['g1']);
		$store->expects($this->never())->method('forget');
		$dispatcher = $this->createStub(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (IdentityClaimsCollectingEvent $event): void {
				$event->assert(['dept' => 'hr']);
			}
		);
		(new IdentityClaimsLoginListener($store, $dispatcher, new NullLogger()))->handle($this->signIn());

		$silent = $this->createMock(DerivedGrantStore::class);
		$silent->method('rules')->willReturn([['claim' => 'dept']]);
		$silent->expects($this->once())->method('forget')->with('ann');
		$silent->expects($this->never())->method('apply');
		(new IdentityClaimsLoginListener($silent, $this->createStub(IEventDispatcher::class), new NullLogger()))->handle($this->signIn());
	}//end testIdentityClaimsApplyOrForget()

	/**
	 * Without rules nothing is dispatched; a failing store never blocks the sign-in.
	 *
	 * @return void
	 */
	public function testIdentityClaimsNoRulesAndFailureAreQuiet(): void {
		$store = $this->createMock(DerivedGrantStore::class);
		$store->method('rules')->willReturn([]);
		$store->expects($this->never())->method('apply');
		$store->expects($this->never())->method('forget');
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->never())->method('dispatchTyped');
		(new IdentityClaimsLoginListener($store, $dispatcher, new NullLogger()))->handle($this->signIn());

		$broken = $this->createStub(DerivedGrantStore::class);
		$broken->method('rules')->willThrowException(new RuntimeException('db down'));
		(new IdentityClaimsLoginListener($broken, $this->createStub(IEventDispatcher::class), new NullLogger()))->handle($this->signIn());
		$this->addToAssertionCount(1);
	}//end testIdentityClaimsNoRulesAndFailureAreQuiet()

	/**
	 * Run the retention listener on one update.
	 *
	 * @param array<string,mixed> $old      The stored data.
	 * @param array<string,mixed> $new      The saved data.
	 * @param list<string>        $watched  The watched properties.
	 * @param bool                $queued   Whether the job is already queued.
	 *
	 * @return list<array<string,mixed>> The arguments a job was added with.
	 */
	private function retention(array $old, array $new, array $watched, bool $queued=false): array {
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn(new Schema());
		$index = $this->createStub(DependantRetentionIndex::class);
		$index->method('watchedPropertiesOf')->willReturn($watched);
		$added = [];
		$jobs = $this->createStub(IJobList::class);
		$jobs->method('has')->willReturn($queued);
		$jobs->method('add')->willReturnCallback(
			static function (string $job, mixed $argument) use (&$added): void {
				$added[] = [$job, $argument];
			}
		);
		$newObject = new ObjectEntity();
		$newObject->setUuid('u-1');
		$newObject->setSchema('7');
		$newObject->setObject($new);
		$oldObject = new ObjectEntity();
		$oldObject->setObject($old);
		(new RelatedRetentionRecalculationListener($schemas, $index, $jobs, new NullLogger()))->handle(new ObjectUpdatedEvent($newObject, $oldObject));
		return $added;
	}//end retention()

	/**
	 * A change to a watched property queues the dependants once; anything else queues nothing.
	 *
	 * @return void
	 */
	public function testRelatedRetentionQueuesOnWatchedChange(): void {
		$this->assertSame(
			[[RelatedRetentionRecalculationJob::class, ['uuid' => 'u-1']]],
			$this->retention(['closed' => '2020'], ['closed' => '2021'], ['closed'])
		);
		$this->assertSame([], $this->retention(['closed' => '2020'], ['closed' => '2020', 'x' => 1], ['closed']));
		$this->assertSame([], $this->retention(['closed' => '2020'], ['closed' => '2021'], []));
		$this->assertSame([], $this->retention(['closed' => '2020'], ['closed' => '2021'], ['closed'], true));
	}//end testRelatedRetentionQueuesOnWatchedChange()

	/**
	 * The quota refusal carries the full payload; a failing count refuses with the unchecked code.
	 *
	 * @return void
	 */
	public function testObjectQuotaRefusalPayloads(): void {
		$schema = new Schema();
		$schema->setSlug('cases');
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		$full = $this->createStub(ObjectQuotaService::class);
		$full->method('limitFor')->willReturn(3);
		$full->method('count')->willReturn(3);
		$event = $this->quotaCreate(new ObjectQuotaListener($full, $this->eventSchemaLookup($schemas, new NullLogger())));
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'object-quota-exceeded',
				'message' => 'This organisation already holds 3 of at most 3 objects of schema "cases".',
				'schema' => 'cases',
				'organisation' => 'org-1',
				'count' => 3,
				'limit' => 3,
			],
			$event->getErrors()
		);

		$broken = $this->createStub(ObjectQuotaService::class);
		$broken->method('limitFor')->willReturn(3);
		$broken->method('count')->willThrowException(new RuntimeException('db down'));
		$event = $this->quotaCreate(new ObjectQuotaListener($broken, $this->eventSchemaLookup($schemas, new NullLogger())));
		$this->assertTrue($event->isPropagationStopped());
		$this->assertSame(
			[
				'code' => 'object-quota-unchecked',
				'message' => 'Schema "9" limits how many objects one organisation holds, and that limit could not be checked just now, so nothing was created. Try again.',
			],
			$event->getErrors()
		);

		$room = $this->createStub(ObjectQuotaService::class);
		$room->method('limitFor')->willReturn(3);
		$room->method('count')->willReturn(2);
		$event = $this->quotaCreate(new ObjectQuotaListener($room, $this->eventSchemaLookup($schemas, new NullLogger())));
		$this->assertFalse($event->isPropagationStopped());
	}//end testObjectQuotaRefusalPayloads()

	/**
	 * Run the quota listener on one create.
	 *
	 * @param ObjectQuotaListener $listener The listener.
	 *
	 * @return ObjectCreatingEvent The handled event.
	 */
	private function quotaCreate(ObjectQuotaListener $listener): ObjectCreatingEvent {
		$object = new ObjectEntity();
		$object->setOrganisation('org-1');
		$object->setSchema('9');
		$object->setRegister('4');
		$event = new ObjectCreatingEvent($object);
		$listener->handle($event);
		return $event;
	}//end quotaCreate()
	/**
	 * Run the state field rule listener with fixed rules per state.
	 *
	 * @param array<string,mixed>          $old       The stored data, or null for a create.
	 * @param array<string,mixed>          $new       The saved data.
	 * @param array<string,StateFieldRules> $rules     The rules per state.
	 * @param array<string,mixed>|null     $condition The condition refusal, or null.
	 *
	 * @return array{0: bool, 1: array<string,mixed>} Whether it stopped, and the errors.
	 */
	private function stateRules(?array $old, array $new, array $rules, ?array $condition=null): array {
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schemas = $this->createStub(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);
		$resolver = $this->createStub(StateFieldRuleResolverInterface::class);
		$resolver->method('annotationOf')->willReturn(['field' => 'status', 'states' => []]);
		$resolver->method('stateOf')->willReturnCallback(static fn (array $annotation, array $data): ?string => ($data['status'] ?? null));
		$resolver->method('resolveFromAnnotation')->willReturnCallback(
			static fn (array $annotation, array $data, ?string $state=null): StateFieldRules => ($rules[$state] ?? new StateFieldRules())
		);
		$conditions = $this->createStub(StateConditionEvaluatorInterface::class);
		$conditions->method('refusal')->willReturn($condition);
		$listener = new StateFieldRuleListener(new StateFieldRulePlatform($schemas, $this->createStub(RuleRunRecorderInterface::class), new NullLogger()), $resolver, $conditions);

		$newObject = new ObjectEntity();
		$newObject->setSchema('7');
		$newObject->setObject($new);
		$event = new ObjectCreatingEvent($newObject);
		if ($old !== null) {
			$oldObject = new ObjectEntity();
			$oldObject->setObject($old);
			$event = new ObjectUpdatingEvent($newObject, $oldObject);
		}

		$listener->handle($event);
		return [$event->isPropagationStopped(), $event->getErrors()];
	}//end stateRules()

	/**
	 * Read-only, hidden, required and condition refusals carry their exact payloads; allowed writes pass.
	 *
	 * @return void
	 */
	public function testStateFieldRuleOutcomes(): void {
		$open = new StateFieldRules(state: 'open', fields: new StateFieldLists(hidden: ['secret'], readOnly: ['ref'], required: ['name']), messages: ['ref' => 'Ref is fixed.']);
		$rules = ['open' => $open, 'done' => new StateFieldRules(state: 'done', fields: new StateFieldLists(required: ['outcome']))];

		$this->assertSame(
			[true, ['code' => 'state-field-read-only', 'field' => 'ref', 'state' => 'open', 'message' => 'Ref is fixed.']],
			$this->stateRules(['status' => 'open', 'ref' => 'a'], ['status' => 'open', 'ref' => 'b', 'name' => 'n'], $rules)
		);
		$this->assertSame(
			[true, ['code' => 'state-field-hidden', 'field' => 'secret', 'state' => 'open', 'message' => 'Field "secret" cannot be written in state "open".']],
			$this->stateRules(['status' => 'open'], ['status' => 'open', 'secret' => 'x', 'name' => 'n'], $rules)
		);
		$this->assertSame(
			[true, ['code' => 'state-field-required', 'field' => 'outcome', 'state' => 'done', 'message' => 'Field "outcome" is required in state "done".']],
			$this->stateRules(['status' => 'open', 'ref' => 'a'], ['status' => 'done', 'ref' => 'a', 'outcome' => ' '], $rules)
		);
		$this->assertSame(
			[true, ['code' => 'state-field-required', 'field' => 'name', 'state' => 'open', 'message' => 'Field "name" is required in state "open".']],
			$this->stateRules(null, ['status' => 'open', 'ref' => 'b', 'secret' => 'x'], $rules)
		);
		$this->assertSame(
			[true, ['code' => 'condition', 'state' => 'done']],
			$this->stateRules(['status' => 'open'], ['status' => 'done', 'outcome' => 'ok'], $rules, ['code' => 'condition', 'state' => 'done'])
		);
		$this->assertSame(
			[false, []],
			$this->stateRules(['status' => 'open', 'ref' => 'a'], ['status' => 'done', 'ref' => 'a', 'outcome' => 0], $rules)
		);
		$this->assertSame(
			[false, []],
			$this->stateRules(['status' => 'open', 'ref' => 'a'], ['status' => 'open', 'name' => ['x'], '_notSupplied' => []], $rules)
		);
		$this->assertSame([false, []], $this->stateRules(['x' => 1], ['y' => 2], $rules));
	}//end testStateFieldRuleOutcomes()

	/**
	 * The schema lookup a listener under test resolves through.
	 */
	private function eventSchemaLookup(SchemaMapperInterface $schemas, LoggerInterface $logger): EventSchemaLookup {
		return new EventSchemaLookup(
			new SchemaCatalog($this->createMock(RegisterMapperInterface::class), $schemas, $this->createMock(MagicMapperInterface::class)),
			$logger
		);
	}
}//end class
