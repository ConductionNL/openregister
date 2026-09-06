<?php

/**
 * In-memory fakes for the timer store, with REAL semantics: the range scans
 * filter on state, purpose and moment; the terminal claim is conditional on
 * `armed`; the fire-ledger claim enforces (timer_uuid, rung_key) uniqueness.
 * A fake that merely echoed the caller could not fail; these can.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Flow\Timer
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Flow\Timer;

use DateTime;
use DateTimeInterface;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Db\FlowTimerEvent;
use OCA\OpenRegister\Db\FlowTimerEventMapper;
use OCA\OpenRegister\Db\FlowTimerFire;
use OCA\OpenRegister\Db\FlowTimerFireMapper;
use OCA\OpenRegister\Db\FlowTimerMapper;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Db\TaskMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;

/**
 * Builds the four fakes over one shared state.
 */
final class InMemoryTimerStore {

	/**
	 * @var array<string, FlowTimer>
	 */
	public array $timers = [];

	/**
	 * @var array<int, FlowTimerFire>
	 */
	public array $fires = [];

	/**
	 * @var array<int, FlowTimerEvent>
	 */
	public array $events = [];

	/**
	 * @var array<string, Task>
	 */
	public array $tasks = [];

	private int $nextId = 1;

	public function __construct(private readonly IDBConnection $db) {
	}//end __construct()

	public function nextId(): int {
		return $this->nextId++;
	}//end nextId()

	public function timerMapper(): FlowTimerMapper {
		$store = $this;

		return new class($this->db, $store) extends FlowTimerMapper {
			public function __construct(IDBConnection $db, private readonly InMemoryTimerStore $store) {
				parent::__construct(db: $db);
			}

			public function insert(Entity $entity): FlowTimer {
				assert($entity instanceof FlowTimer);
				if ($entity->getId() === null) {
					$entity->setId($this->store->nextId());
				}

				if ($entity->getCreated() === null) {
					$entity->setCreated(new DateTime());
				}

				$this->store->timers[(string)$entity->getUuid()] = $entity;

				return $entity;
			}

			public function update(Entity $entity): FlowTimer {
				assert($entity instanceof FlowTimer);
				$entity->setUpdated(new DateTime());
				$this->store->timers[(string)$entity->getUuid()] = $entity;

				return $entity;
			}

			public function findByUuid(string $uuid): FlowTimer {
				if (isset($this->store->timers[$uuid]) === false) {
					throw new DoesNotExistException('timer ' . $uuid);
				}

				return $this->store->timers[$uuid];
			}

			public function findDueExpiries(DateTimeInterface $now, int $limit): array {
				$due = array_filter(
					$this->store->timers,
					static fn (FlowTimer $t): bool => $t->getState() === FlowTimer::STATE_ARMED
						&& $t->getPurpose() === FlowTimer::PURPOSE_EXPIRY
						&& $t->getFireAt() !== null && $t->getFireAt() <= $now
				);
				usort($due, static fn (FlowTimer $a, FlowTimer $b): int => $a->getFireAt() <=> $b->getFireAt());

				return array_slice(array_values($due), 0, $limit);
			}

			public function findDueRungs(DateTimeInterface $now, int $limit): array {
				$due = array_filter(
					$this->store->timers,
					static fn (FlowTimer $t): bool => $t->getState() === FlowTimer::STATE_ARMED
						&& $t->getNextRungAt() !== null && $t->getNextRungAt() <= $now
				);
				usort($due, static fn (FlowTimer $a, FlowTimer $b): int => $a->getNextRungAt() <=> $b->getNextRungAt());

				return array_slice(array_values($due), 0, $limit);
			}

			public function findBySubject(string $subjectType, string $subjectUuid, array $states = []): array {
				return array_values(array_filter(
					$this->store->timers,
					static fn (FlowTimer $t): bool => $t->getSubjectType() === $subjectType
						&& $t->getSubjectUuid() === $subjectUuid
						&& ($states === [] || in_array($t->getState(), $states, true))
				));
			}

			public function findOpenByRun(string $runUuid): array {
				return array_values(array_filter(
					$this->store->timers,
					static fn (FlowTimer $t): bool => in_array($t->getState(), [FlowTimer::STATE_ARMED, FlowTimer::STATE_SUSPENDED], true)
						&& ($t->getRunUuid() === $runUuid || ($t->getSubjectType() === 'run' && $t->getSubjectUuid() === $runUuid))
				));
			}

			public function findSuccessors(string $uuid): array {
				return array_values(array_filter($this->store->timers, static fn (FlowTimer $t): bool => $t->getSupersedesUuid() === $uuid));
			}

			public function findByStatePaged(string $state, int $afterId, int $limit): array {
				$rows = array_filter($this->store->timers, static fn (FlowTimer $t): bool => $t->getState() === $state && (int)$t->getId() > $afterId);
				usort($rows, static fn (FlowTimer $a, FlowTimer $b): int => (int)$a->getId() <=> (int)$b->getId());

				return array_slice(array_values($rows), 0, $limit);
			}

			public function claimFired(string $uuid, DateTimeInterface $firedAt): bool {
				$timer = ($this->store->timers[$uuid] ?? null);
				if ($timer === null || $timer->getState() !== FlowTimer::STATE_ARMED) {
					return false;
				}

				$timer->setState(FlowTimer::STATE_FIRED);
				$timer->setFiredAt(DateTime::createFromInterface($firedAt));

				return true;
			}
		};
	}//end timerMapper()

	public function fireMapper(): FlowTimerFireMapper {
		$store = $this;

		return new class($this->db, $store) extends FlowTimerFireMapper {
			public function __construct(IDBConnection $db, private readonly InMemoryTimerStore $store) {
				parent::__construct(db: $db);
			}

			public function insert(Entity $entity): FlowTimerFire {
				assert($entity instanceof FlowTimerFire);
				$entity->setId($this->store->nextId());
				$this->store->fires[] = $entity;

				return $entity;
			}

			public function claim(FlowTimerFire $fire): ?FlowTimerFire {
				foreach ($this->store->fires as $existing) {
					if ($existing->getTimerUuid() === $fire->getTimerUuid() && $existing->getRungKey() === $fire->getRungKey()) {
						return null;
					}
				}

				return $this->insert(entity: $fire);
			}

			public function findByTimer(string $timerUuid): array {
				return array_values(array_filter($this->store->fires, static fn (FlowTimerFire $f): bool => $f->getTimerUuid() === $timerUuid));
			}
		};
	}//end fireMapper()

	public function eventMapper(): FlowTimerEventMapper {
		$store = $this;

		return new class($this->db, $store) extends FlowTimerEventMapper {
			public function __construct(IDBConnection $db, private readonly InMemoryTimerStore $store) {
				parent::__construct(db: $db);
			}

			public function insert(Entity $entity): FlowTimerEvent {
				assert($entity instanceof FlowTimerEvent);
				$entity->setId($this->store->nextId());
				$this->store->events[] = $entity;

				return $entity;
			}

			public function findByTimer(string $timerUuid): array {
				return array_values(array_filter($this->store->events, static fn (FlowTimerEvent $e): bool => $e->getTimerUuid() === $timerUuid));
			}
		};
	}//end eventMapper()

	public function taskMapper(): TaskMapper {
		$store = $this;

		return new class($this->db, $store) extends TaskMapper {
			public function __construct(IDBConnection $db, private readonly InMemoryTimerStore $store) {
				parent::__construct(db: $db);
			}

			public function findByUuid(string $uuid): Task {
				if (isset($this->store->tasks[$uuid]) === false) {
					throw new DoesNotExistException('task ' . $uuid);
				}

				return $this->store->tasks[$uuid];
			}

			public function update(Entity $entity): Task {
				assert($entity instanceof Task);
				$this->store->tasks[(string)$entity->getUuid()] = $entity;

				return $entity;
			}
		};
	}//end taskMapper()
}//end class
