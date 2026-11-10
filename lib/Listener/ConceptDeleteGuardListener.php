<?php

/**
 * OpenRegister ConceptDeleteGuardListener
 *
 * Refuses to delete a code-list value that the product defines, or that
 * objects are still holding.
 *
 * There is always a correct alternative, and it is the one this change is
 * built around: close the value's validity window. A retired value stops
 * being offered and keeps resolving, so the records that hold it stay
 * readable (design.md D-1). Deleting it is the only operation with no safe
 * version, which is why it is the only one refused.
 *
 * The refusal is a conflict, not a malformed request, so it carries 409 the
 * same way the working-calendar delete guard does, and it names the count so
 * the person reading it knows what to do next.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Listener\Support\ConflictVeto;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepositoryInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses the delete of a system-defined or in-use concept.
 *
 * @template-implements IEventListener<ObjectDeletingEvent>
 */
class ConceptDeleteGuardListener implements IEventListener {
	use ConflictVeto;

	/**
	 * The error code a refused delete of a system-defined value carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE_SYSTEM = 'concept-system-defined';

	/**
	 * The error code a refused delete of a value still in use carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE_IN_USE = 'concept-in-use';

	/**
	 * Constructor.
	 *
	 * @param ConceptDeleteGuardInterface $guard    Recognises a concept and counts its holders.
	 * @param ConceptRepositoryInterface  $concepts Resolves the scheme a concept belongs to.
	 * @param SchemaMapperInterface       $schemas  Resolves the schema an object belongs to.
	 * @param LoggerInterface             $logger   The logger.
	 */
	public function __construct(
		private readonly ConceptDeleteGuardInterface $guard,
		private readonly ConceptRepositoryInterface $concepts,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse the delete when the value is system-defined or still held.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent) {
			$this->guardFailingOpen(event: $event);
		}

	}//end handle()

	/**
	 * Run the guard, letting the delete through when the guard itself fails.
	 *
	 * @param ObjectDeletingEvent $event The delete event.
	 *
	 * @return void
	 */
	private function guardFailingOpen(ObjectDeletingEvent $event): void {
		try {
			$this->guardDelete(event: $event);
		} catch (Throwable $failure) {
			// A guard that cannot read the holding tables must not also become
			// the reason nothing can be deleted. The delete proceeds and the
			// failure is named in the log.
			$this->logger->warning(
				message: '[ConceptDeleteGuardListener] The guard itself failed, allowing the delete: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end guardFailingOpen()

	/**
	 * Refuse the delete when the concept is system-defined or still held.
	 *
	 * The checks live here so {@see handle()} is only the event guard and the
	 * fail-open try/catch: a guard that throws must not itself block deletes.
	 *
	 * @param ObjectDeletingEvent $event The delete event.
	 *
	 * @return void
	 */
	private function guardDelete(ObjectDeletingEvent $event): void {
		$concept = $this->conceptOf(object: $event->getObject());
		if ($concept === null) {
			return;
		}

		if ($this->guard->isSystemDefined(concept: $concept) === true) {
			$this->vetoAsConflict(
				event: $event,
				refusal: ['code' => self::ERROR_CODE_SYSTEM, 'message' => $this->guard->systemDefinedMessage(concept: $concept)],
				detail: ['concept' => (string)($concept['uri'] ?? ''), 'systemDefined' => true]
			);
			return;
		}

		$this->refuseWhenHeld(event: $event, concept: $concept);
	}//end guardDelete()

	/**
	 * The concept data of the object being deleted, or null when it is no concept.
	 *
	 * @param ObjectEntity $object The object being deleted.
	 *
	 * @return array<string,mixed>|null The concept's decoded object data.
	 */
	private function conceptOf(ObjectEntity $object): ?array {
		$reference = (string)$object->getSchema();
		if ($reference === '') {
			return null;
		}

		$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		if ($this->guard->isConcept(schema: $schema) === false) {
			return null;
		}

		$concept = $object->getObject();
		if (is_array($concept) === false) {
			return null;
		}

		return $concept;
	}//end conceptOf()

	/**
	 * Refuse the delete when objects in the concept's scheme still hold it.
	 *
	 * @param ObjectDeletingEvent $event   The delete event.
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return void
	 */
	private function refuseWhenHeld(ObjectDeletingEvent $event, array $concept): void {
		$usage = $this->usageOf(concept: $concept);
		if ($usage === null) {
			return;
		}

		$this->vetoAsConflict(
			event: $event,
			refusal: ['code' => self::ERROR_CODE_IN_USE, 'message' => $this->guard->inUseMessage(concept: $concept, usage: $usage)],
			detail: ['concept' => (string)($concept['uri'] ?? ''), 'inUse' => $usage['count'], 'holders' => $usage['holders']]
		);
	}//end refuseWhenHeld()

	/**
	 * Who holds the concept in its scheme, or null when its scheme is unknown or nothing holds it.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return array{count: int, holders: array<mixed>}|null The usage.
	 */
	private function usageOf(array $concept): ?array {
		$schemeUri = $this->schemeUriOf(concept: $concept);
		if ($schemeUri === null) {
			return null;
		}

		$usage = $this->guard->usage(concept: $concept, schemeUri: $schemeUri);
		if ($usage['count'] === 0) {
			return null;
		}

		return $usage;
	}//end usageOf()

	/**
	 * The uri of the scheme a concept belongs to, or null.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string|null The scheme uri.
	 */
	private function schemeUriOf(array $concept): ?string {
		$reference = $this->schemeReferenceOf(concept: $concept);
		if ($reference === null || str_contains($reference, '://') === true) {
			return $reference;
		}

		$scheme = $this->concepts->schemeByReference(reference: $reference);
		$uri = trim((string)($scheme['uri'] ?? ''));
		if ($uri === '') {
			return null;
		}

		return $uri;
	}//end schemeUriOf()

	/**
	 * The trimmed reference a concept's `inScheme` makes, or null when it names nothing.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string|null The uri, id or uuid of the scheme.
	 */
	private function schemeReferenceOf(array $concept): ?string {
		$reference = ($concept['inScheme'] ?? null);
		if (is_array($reference) === true) {
			$reference = ($reference['uri'] ?? ($reference['id'] ?? ($reference['uuid'] ?? null)));
		}

		if (is_string($reference) === false || trim($reference) === '') {
			return null;
		}

		return trim($reference);
	}//end schemeReferenceOf()
}//end class
