<?php

/**
 * OpenRegister DetectionPatternValidationListener
 *
 * Refuses, at write time, a detection pattern that would not run safely (REQ-RPD-004).
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
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Kernel\Contracts\Processes\ObjectWriteValidatorInterface;
use OCA\OpenRegister\Service\RedactionPolicy\DetectionPatternValidator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\Event;
use Throwable;

/**
 * Runs {@see DetectionPatternValidator} on every write of a `detectionPattern`
 * in the `redaction-policy` register, so the objects API, the policy pages and
 * an import refuse the same pattern with the same message. Registered through
 * ObjectWriteValidatorInterface, which ContractBindings aliases to this class.
 *
 * @template-implements ObjectWriteValidatorInterface<Event>
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
 */
class DetectionPatternValidationListener implements ObjectWriteValidatorInterface {

	public const ERROR_CODE = 'detection-pattern-invalid';

	public const REGISTER = 'redaction-policy';

	public const SCHEMA = 'detectionPattern';

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface   $schemas   Resolves the written object's schema.
	 * @param RegisterMapperInterface $registers Resolves its register.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemas,
		private readonly RegisterMapperInterface $registers,
	) {
	}//end __construct()

	/**
	 * Check the object a create or an update is about to write.
	 *
	 * @param Event $event The pre-write event; any other event is ignored.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->validate(event: $event, object: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->validate(event: $event, object: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the write when the object is a detection pattern with problems.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-write event.
	 * @param ObjectEntity                            $object The object about to be written.
	 *
	 * @return void
	 *
	 * @listener-placement inline cheap-bounded — one schema lookup, and for a pattern one register lookup and a
	 *   bounded regex check; deferred, the bad pattern would already be stored.
	 *
	 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#requirement-an-officers-own-pattern-runs-beside-the-model-req-rpd-004
	 */
	private function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		$isPattern = $this->isDetectionPattern(schema: (string)$object->getSchema(), register: (string)$object->getRegister());
		if ($isPattern === false) {
			return;
		}

		$problems = ['its schema or register could not be read, so it could not be checked'];
		if ($isPattern === true) {
			$problems = (new DetectionPatternValidator())->problemsOf(pattern: (array)($object->getObject() ?? []));
		}

		if ($problems === []) {
			return;
		}

		$event->setErrors(['code' => self::ERROR_CODE, 'message' => 'The detection pattern cannot be saved: '.implode('; ', $problems).'.']);
		$event->stopPropagation();
	}//end validate()

	/**
	 * Whether the object is a detection pattern of the redaction policy register.
	 *
	 * @param string $schema   The schema reference.
	 * @param string $register The register reference.
	 *
	 * @return bool|null True or false, or null when a reference that might be one cannot be read.
	 */
	private function isDetectionPattern(string $schema, string $register): ?bool {
		$schemaSlug = $this->slugOf(find: fn (string $ref) => $this->schemas->find($ref, _rbac: false, _multitenancy: false), reference: $schema);
		if ($schemaSlug !== self::SCHEMA) {
			return ($schemaSlug === null) ? null : false;
		}

		// Another app may hold a schema of the same slug in its own register; its rows are not this policy.
		$registerSlug = $this->slugOf(find: fn (string $ref) => $this->registers->find($ref, _rbac: false, _multitenancy: false), reference: $register);
		if ($registerSlug === null) {
			return null;
		}

		return $registerSlug === self::REGISTER;
	}//end isDetectionPattern()

	/**
	 * The slug a reference resolves to: '' when there is none, null when it cannot be read.
	 *
	 * @param callable $find      Finds the entity by reference.
	 * @param string   $reference The id, uuid or slug.
	 *
	 * @return string|null The slug.
	 */
	private function slugOf(callable $find, string $reference): ?string {
		if ($reference === '') {
			return '';
		}

		try {
			return (string)$find($reference)->getSlug();
		} catch (DoesNotExistException $unresolved) {
			return '';
		} catch (Throwable $unreadable) {
			return null;
		}
	}//end slugOf()
}//end class
