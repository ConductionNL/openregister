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
 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Service\Vocabulary\ConceptDeleteGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptRepository;
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
	 * @param ConceptDeleteGuard $guard Recognises a concept and counts its holders.
	 * @param ConceptRepository $concepts Resolves the scheme a concept belongs to.
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly ConceptDeleteGuard $guard,
		private readonly ConceptRepository $concepts,
		private readonly SchemaMapper $schemas,
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
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectDeletingEvent === false) {
			return;
		}

		try {
			$object = $event->getObject();
			$reference = $object->getSchema();
			if ($reference === null || $reference === '') {
				return;
			}

			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
			if ($this->guard->isConcept(schema: $schema) === false) {
				return;
			}

			$concept = $object->getObject();
			if (is_array($concept) === false) {
				return;
			}

			if ($this->guard->isSystemDefined(concept: $concept) === true) {
				$this->refuse(
					event: $event,
					code: self::ERROR_CODE_SYSTEM,
					message: $this->guard->systemDefinedMessage(concept: $concept),
					detail: ['concept' => (string)($concept['uri'] ?? ''), 'systemDefined' => true]
				);
				return;
			}

			$schemeUri = $this->schemeUriOf(concept: $concept);
			if ($schemeUri === null) {
				return;
			}

			$usage = $this->guard->usage(concept: $concept, schemeUri: $schemeUri);
			if ($usage['count'] === 0) {
				return;
			}

			$this->refuse(
				event: $event,
				code: self::ERROR_CODE_IN_USE,
				message: $this->guard->inUseMessage(concept: $concept, usage: $usage),
				detail: [
					'concept' => (string)($concept['uri'] ?? ''),
					'inUse' => $usage['count'],
					'holders' => $usage['holders'],
				]
			);
		} catch (Throwable $failure) {
			// A guard that cannot read the holding tables must not also become
			// the reason nothing can be deleted. The delete proceeds and the
			// failure is named in the log.
			$this->logger->warning(
				message: '[ConceptDeleteGuardListener] The guard itself failed, allowing the delete: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end handle()

	/**
	 * Stop the delete, carrying the refusal and its 409.
	 *
	 * @param ObjectDeletingEvent $event The delete event.
	 * @param string $code The machine-readable refusal code.
	 * @param string $message The refusal in words.
	 * @param array<string,mixed> $detail The figures behind the refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectDeletingEvent $event, string $code, string $message, array $detail): void {
		$event->setErrors(
			array_merge(
				[
					'code' => $code,
					'message' => $message,
					// A referenced row refused is a conflict, not a malformed
					// body; the delete handler reads this and answers 409.
					'status' => 409,
				],
				$detail
			)
		);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The uri of the scheme a concept belongs to, or null.
	 *
	 * @param array<string,mixed> $concept The concept's decoded object data.
	 *
	 * @return string|null The scheme uri.
	 */
	private function schemeUriOf(array $concept): ?string {
		$reference = ($concept['inScheme'] ?? null);
		if (is_array($reference) === true) {
			$reference = ($reference['uri'] ?? ($reference['id'] ?? ($reference['uuid'] ?? null)));
		}

		if (is_string($reference) === false) {
			return null;
		}

		$reference = trim($reference);
		if ($reference === '') {
			return null;
		}

		if (str_contains($reference, '://') === true) {
			return $reference;
		}

		$scheme = $this->concepts->schemeByReference(reference: $reference);
		if ($scheme === null) {
			return null;
		}

		$uri = trim((string)($scheme['uri'] ?? ''));

		if ($uri === '') {
			return null;
		}

		return $uri;
	}//end schemeUriOf()
}//end class
