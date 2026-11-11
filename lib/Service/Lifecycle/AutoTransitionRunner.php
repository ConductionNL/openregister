<?php

/**
 * OpenRegister AutoTransitionRunner
 *
 * Decides one object's automatic move against the stored object, and either
 * fires it through the named-transition engine or queues it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Lifecycle
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

namespace OCA\OpenRegister\Service\Lifecycle;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The work of one step of a pass: decide, then fire or queue.
 *
 * Split from {@see AutoTransitionPass}, which owns the request-scoped state
 * (the boundary counter, what has been recorded, the cap's lineage). The pass
 * decides WHETHER a step may be taken; this class knows HOW to take one.
 *
 * LAZY RESOLUTION IS DELIBERATE. `ObjectService` and `TransitionEngine` both
 * open the pass boundary, so they depend on the pass, which depends on this
 * class. Naming either of them as a constructor parameter would make the
 * container recurse while building `ObjectService`. They are resolved from the
 * container at the moment a move is actually made, which is always inside a
 * request that has already built them.
 *
 * @spec openspec/specs/object-lifecycle/spec.md
 */
class AutoTransitionRunner {

	/**
	 * Constructor.
	 *
	 * @param AutoTransitionDecider $decider Re-reads the object and picks the one eligible move, or none.
	 * @param ContainerInterface $container Resolves TransitionEngine lazily; see the class docblock.
	 * @param AutoTransitionQueue $queue Decides inline versus queued, and owns the job list.
	 * @param LoggerInterface $logger Logs every refusal, so a move that never happens is visible.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly AutoTransitionDecider $decider,
		private readonly ContainerInterface $container,
		private readonly AutoTransitionQueue $queue,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The move this object is eligible for right now, or null.
	 *
	 * Decided against the object AS STORED, not against the event payload:
	 * computed fields, defaults and file ids are all present by then, and the
	 * two update events of a file-bearing save collapse into one decision.
	 *
	 * @param ObjectRef $ref The object's register and schema references and its uuid.
	 * @param array<string, mixed> $previous The object before the triggering write.
	 *
	 * @return AutoTransitionDecision|null The decided move, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function decide(ObjectRef $ref, array $previous): ?AutoTransitionDecision {
		return $this->decider->decide(
			entry: ['uuid' => $ref->id, 'register' => (string)$ref->register, 'schema' => (string)$ref->schema],
			previous: $previous
		);
	}//end decide()

	/**
	 * Apply the decided move, or queue it, and never let it reach the caller.
	 *
	 * A refusal by any gate a manual transition meets is caught here and logged
	 * at warning, carrying the refusal code where the exception has one. The
	 * write that triggered this has already succeeded and nothing about the
	 * refusal is the caller's fault, so it is not retried and not surfaced.
	 *
	 * For a move decided inside a write boundary; one decided outside any
	 * boundary goes to {@see queueOutsideBoundary()}.
	 *
	 * @param AutoTransitionDecision $decision The decided move.
	 * @param array<string, mixed> $lineage The pass lineage to carry into a queued move.
	 *
	 * @return ObjectEntity|null The object after the move, or null when it was queued or refused.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function fire(AutoTransitionDecision $decision, array $lineage): ?ObjectEntity {
		if ($this->queue->appliesInline(candidate: $decision->candidate, queueOnly: false) === false) {
			$this->queue->queue(decision: $decision, lineage: $lineage);
			return null;
		}

		try {
			return $this->engine()->transition(
				objectId: $decision->uuid,
				action: $decision->candidate->action
			);
		} catch (Throwable $e) {
			$this->logRefusal(decision: $decision, error: $e);
			return null;
		}
	}//end fire()

	/**
	 * Queue a move decided outside any write boundary, whatever its declared mode.
	 *
	 * There is no point after such a write and before a response at which a
	 * sync move could run, so the declared mode does not apply.
	 *
	 * @param AutoTransitionDecision $decision The decided move.
	 * @param array<string, mixed> $lineage The pass lineage to carry into the queued move.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function queueOutsideBoundary(AutoTransitionDecision $decision, array $lineage): void {
		$this->queue->queue(decision: $decision, lineage: $lineage);
	}//end queueOutsideBoundary()

	/**
	 * Log an inline move the engine refused; the triggering write stands.
	 *
	 * @param AutoTransitionDecision $decision The refused move.
	 * @param Throwable $error The exception raised while applying it.
	 *
	 * @return void
	 */
	private function logRefusal(AutoTransitionDecision $decision, Throwable $error): void {
		$this->logger->warning(
			'[AutoTransitionRunner] An automatic transition was refused; the triggering write stands.',
			[
				'schema' => $decision->schemaSlug,
				'uuid' => $decision->uuid,
				'action' => $decision->candidate->action,
				'from' => $decision->candidate->from,
				'to' => $decision->candidate->to,
				'refusalCode' => $this->refusalCodeOf(error: $error),
				'error' => $error->getMessage(),
			]
		);
	}//end logRefusal()

	/**
	 * The refusal code an exception carries, when it carries one.
	 *
	 * @param Throwable $error The exception raised while applying the move.
	 *
	 * @return string|null The lifecycle refusal code, or null.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function refusalCodeOf(Throwable $error): ?string {
		if ($error instanceof HookStoppedException === false) {
			return null;
		}

		$code = ($error->getErrors()['code'] ?? null);
		if (is_string($code) === true && $code !== '') {
			return $code;
		}

		return null;
	}//end refusalCodeOf()

	/**
	 * The named-transition engine, resolved at the moment a move is made.
	 *
	 * @return TransitionEngine The engine every automatic move goes through.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	private function engine(): TransitionEngine {
		return $this->container->get(TransitionEngine::class);
	}//end engine()

	/**
	 * Flush every queued move to the job list now.
	 *
	 * Delegated to {@see AutoTransitionQueue}, which owns the job list. It stays
	 * on this class because the pass drives one collaborator, not two.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function flushQueued(): void {
		$this->queue->flushQueued();
	}//end flushQueued()
}//end class
