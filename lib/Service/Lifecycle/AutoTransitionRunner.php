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
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\HookStoppedException;
use OCA\OpenRegister\Service\Deferral\DeferredEntryObjectResolver;
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
 */
class AutoTransitionRunner {


	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves ObjectService and TransitionEngine lazily; see the class docblock.
	 * @param SchemaMapper $schemaMapper Reads the schema's lifecycle annotation.
	 * @param AutoTransitionSelector $selector Picks the one eligible move, or none.
	 * @param AutoTransitionQueue $queue Decides inline versus queued, and owns the job list.
	 * @param LoggerInterface $logger Logs every refusal, so a move that never happens is visible.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly SchemaMapper $schemaMapper,
		private readonly AutoTransitionSelector $selector,
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
	 * @param string $uuid The object's uuid.
	 * @param string $register The object's register reference.
	 * @param string $schema The object's schema reference.
	 * @param array<string, mixed> $previous The object before the triggering write.
	 *
	 * @return AutoTransitionDecision|null The decided move, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function decide(string $uuid, string $register, string $schema, array $previous): ?AutoTransitionDecision {
		$object = $this->resolveObject(uuid: $uuid, register: $register, schema: $schema);
		if ($object === null) {
			return null;
		}

		$declaration = $this->annotationFor(schema: $schema);
		if ($declaration === null) {
			return null;
		}

		[$annotation, $slug] = $declaration;

		$candidate = $this->selector->select(
			annotation: $annotation,
			objectData: ($object->getObject() ?? []),
			previous: $previous,
			schemaSlug: $slug
		);
		if ($candidate === null) {
			return null;
		}

		return new AutoTransitionDecision(
			uuid: $uuid,
			register: $register,
			schema: $schema,
			schemaSlug: $slug,
			candidate: $candidate,
			version: $object->getVersion(),
			updated: $object->getUpdated()
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
	 * @param AutoTransitionDecision $decision The decided move.
	 * @param array<string, mixed> $lineage The pass lineage to carry into a queued move.
	 * @param bool $queueOnly True for a move decided outside any write boundary.
	 *
	 * @return ObjectEntity|null The object after the move, or null when it was queued or refused.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function fire(AutoTransitionDecision $decision, array $lineage, bool $queueOnly): ?ObjectEntity {
		if ($this->queue->appliesInline(candidate: $decision->candidate, queueOnly: $queueOnly) === false) {
			$this->queue->queue(decision: $decision, lineage: $lineage);
			return null;
		}

		try {
			return $this->engine()->transition(
				objectId: $decision->uuid,
				action: $decision->candidate->action
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[AutoTransitionRunner] An automatic transition was refused; the triggering write stands.',
				[
					'schema' => $decision->schemaSlug,
					'uuid' => $decision->uuid,
					'action' => $decision->candidate->action,
					'from' => $decision->candidate->from,
					'to' => $decision->candidate->to,
					'refusalCode' => $this->refusalCodeOf(error: $e),
					'error' => $e->getMessage(),
				]
			);
			return null;
		}//end try
	}//end fire()




	/**
	 * The refusal code an exception carries, when it carries one.
	 *
	 * @param Throwable $error The exception raised while applying the move.
	 *
	 * @return string|null The lifecycle refusal code, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * Re-read the object as stored, or null when it is gone or soft-deleted.
	 *
	 * @param string $uuid The object's uuid.
	 * @param string $register The object's register reference.
	 * @param string $schema The object's schema reference.
	 *
	 * @return ObjectEntity|null The stored object, or null.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function resolveObject(string $uuid, string $register, string $schema): ?ObjectEntity {
		try {
			$resolver = $this->container->get(DeferredEntryObjectResolver::class);
			return $resolver->resolve(entry: ['uuid' => $uuid, 'register' => $register, 'schema' => $schema]);
		} catch (Throwable $e) {
			$this->logger->warning(
				'[AutoTransitionRunner] Could not re-read the object to decide an automatic transition.',
				['uuid' => $uuid, 'schema' => $schema, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end resolveObject()

	/**
	 * The schema's lifecycle annotation and the schema's slug.
	 *
	 * @param string $schema The object's schema reference.
	 *
	 * @return array{0: array<string, mixed>, 1: string}|null The annotation and slug, or null when there is none.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	private function annotationFor(string $schema): ?array {
		try {
			$entity = $this->schemaMapper->find($schema, _multitenancy: false);
		} catch (Throwable $e) {
			return null;
		}

		$annotation = (($entity->getConfiguration() ?? [])['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === false || $annotation === []) {
			return null;
		}

		return [$annotation, (string)($entity->getSlug() ?? '')];
	}//end annotationFor()

	/**
	 * The named-transition engine, resolved at the moment a move is made.
	 *
	 * @return TransitionEngine The engine every automatic move goes through.
	 *
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
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
	 * @spec openspec/changes/lifecycle-auto-transitions/specs/object-lifecycle/spec.md
	 */
	public function flushQueued(): void {
		$this->queue->flushQueued();
	}//end flushQueued()
}//end class
