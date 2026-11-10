<?php

/**
 * OpenRegister ReferenceExistence
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */

namespace OCA\OpenRegister\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Exception\BrokenReference;
use OCA\OpenRegister\Exception\CircularReferenceException;
use OCA\OpenRegister\Exception\ReferenceCycle;
use OCA\OpenRegister\Exception\ReferenceValidationException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Refuses a reference that points at no object or closes a cycle, and remembers each verdict for the request.
 *
 * @spec openspec/specs/reference-existence-validation/spec.md
 */
final class ReferenceExistence {

	/**
	 * The verdict per (target schema, uuid): true found, false refused.
	 *
	 * @var array<string, bool>
	 */
	private array $verdicts = [];

	/**
	 * @param SaveLookups          $lookups   Schema and register lookups.
	 * @param SaveCallStack        $saveCalls The saves in flight.
	 * @param MagicObjectFinderInterface $mapper    Reads the referenced object.
	 * @param ReferenceEvents      $events    Announces the verdicts.
	 */
	public function __construct(
		private readonly SaveLookups $lookups,
		private readonly SaveCallStack $saveCalls,
		private readonly MagicObjectFinderInterface $mapper,
		private readonly ReferenceEvents $events,
	) {
	}//end __construct()

	/**
	 * Refuse a reference that points at no object, or at a cycle.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @throws CircularReferenceException When the reference closes a cycle back onto an object being saved.
	 * @throws ReferenceValidationException When the reference resolves to no stored object.
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function assertExists(ReferenceCheck $check): void {
		$this->refuseCycle(check: $check);

		$targetSchemaId = $this->targetSchemaId(check: $check);
		if ($targetSchemaId === null) {
			return;
		}

		$check->cacheKey = $targetSchemaId.':'.$check->uuid;
		if ($this->replay(check: $check) === false) {
			$this->resolveTarget(check: $check, targetSchemaId: $targetSchemaId);
			$this->lookUp(check: $check);
		}
	}//end assertExists()

	/**
	 * The id of the schema a reference declares; an unknown schema skips the check.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return string|null The id, or null when it does not resolve.
	 */
	private function targetSchemaId(ReferenceCheck $check): ?string {
		$targetSchemaId = $this->lookups->schemaId(reference: $check->schemaRef);
		if ($targetSchemaId === null) {
			$this->events->logger->warning(
				message: '[SaveObject] Could not resolve schema reference for reference validation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $check->propertyName, 'ref' => $check->schemaRef]
			);
		}

		return $targetSchemaId;
	}//end targetSchemaId()

	/**
	 * The number of verdicts remembered.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function verdictCount(): int {
		return count($this->verdicts);
	}//end verdictCount()

	/**
	 * Forget every verdict, so none leaks across logical request boundaries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/reference-existence-validation/spec.md
	 */
	public function forget(): void {
		$this->verdicts = [];
	}//end forget()

	/**
	 * Refuse a reference whose UUID is already on the in-flight save call stack.
	 *
	 * A cycle (A -> B -> A) on otherwise valid UUIDs would pass the existence
	 * check, but cascading the save would recurse forever, so it is refused
	 * before the database is asked.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @throws CircularReferenceException When the reference closes a cycle.
	 */
	private function refuseCycle(ReferenceCheck $check): void {
		$cycle = $this->saveCalls->cycleThrough(uuid: $check->uuid);
		if ($cycle === null) {
			return;
		}

		$context = ['property' => $check->propertyName, 'uuid' => $check->uuid, 'targetSchema' => $check->schemaRef, 'cycleDepth' => count($cycle)];
		$this->events->logger->warning(message: '[SaveObject] Circular reference detected during validation', context: $context);

		throw new CircularReferenceException(
			referenceCycle: new ReferenceCycle(
				referencedUuid: $check->uuid,
				targetSchemaSlug: $check->schemaRef,
				cycle: array_map(self::frameTrace(...), $cycle)
			),
			code: 422
		);
	}//end refuseCycle()

	/**
	 * One frame of a cycle, as the refusal reports it.
	 *
	 * @param SaveCallFrame $frame The frame.
	 *
	 * @return array{schema: string, uuid: string, register: string|null}
	 */
	private static function frameTrace(SaveCallFrame $frame): array {
		return ['schema' => $frame->schemaSlug, 'uuid' => $frame->uuid, 'register' => $frame->register];
	}//end frameTrace()

	/**
	 * Replay a verdict already reached for this (target schema, uuid) in this request.
	 *
	 * A remembered refusal is raised again without dispatching the failure event a
	 * second time, so listeners see each unique reference once per request.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return bool True when a remembered acceptance settles the check, false when nothing is remembered.
	 *
	 * @throws ReferenceValidationException When the remembered verdict is a refusal.
	 */
	private function replay(ReferenceCheck $check): bool {
		if (array_key_exists($check->cacheKey, $this->verdicts) === false) {
			return false;
		}

		if ($this->verdicts[$check->cacheKey] === true) {
			return true;
		}

		throw new ReferenceValidationException(
			reference: new BrokenReference(
				propertyName: $check->propertyName,
				referencedUuid: $check->uuid,
				targetSchemaSlug: $check->schemaRef,
				targetRegister: $check->register
			),
			code: 422
		);
	}//end replay()

	/**
	 * Record the target schema of a reference and the slug its refusal names.
	 *
	 * @param ReferenceCheck $check          The reference.
	 * @param int|string     $targetSchemaId The resolved target schema id.
	 *
	 * @return void
	 */
	private function resolveTarget(ReferenceCheck $check, int|string $targetSchemaId): void {
		$check->targetSchemaSlug = $check->schemaRef;
		try {
			$check->targetSchema = $this->lookups->schema(schemaId: $targetSchemaId);
			$check->targetSchemaSlug = $check->targetSchema->getSlug() ?? $check->schemaRef;
		} catch (DoesNotExistException $e) {
			// The raw reference stays the slug in the error message.
		}
	}//end resolveTarget()

	/**
	 * Ask the store, and remember and announce an acceptance.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @throws ReferenceValidationException When the reference resolves to no stored object.
	 */
	private function lookUp(ReferenceCheck $check): void {
		if ($check->register !== null && $this->resolveRegister(check: $check) === false) {
			return;
		}

		if ($this->objectFound(check: $check) === true) {
			$this->verdicts[$check->cacheKey] = true;
			$this->events->accepted(check: $check);
		}
	}//end lookUp()

	/**
	 * Resolve the register a reference names; an unknown register skips the check.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return bool False when the register does not resolve.
	 */
	private function resolveRegister(ReferenceCheck $check): bool {
		try {
			$check->registerEntity = $this->lookups->register(registerId: $check->register);
			return true;
		} catch (DoesNotExistException $e) {
			$this->events->logger->warning(
				message: '[SaveObject] Could not resolve register for reference validation',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $check->propertyName, 'register' => $check->register]
			);
			return false;
		}
	}//end resolveRegister()

	/**
	 * Whether the referenced object exists; a missing one is refused, a failing lookup skips the check.
	 *
	 * Soft-deleted objects count as nonexistent (`includeDeleted: false` is
	 * pinned here, not left to the mapper default).
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return bool True when found, false when the lookup itself failed.
	 *
	 * @throws ReferenceValidationException When the reference resolves to no stored object.
	 */
	private function objectFound(ReferenceCheck $check): bool {
		try {
			$this->find(check: $check);
			return true;
		} catch (DoesNotExistException $e) {
			$this->refuseMissing(check: $check, cause: $e);
		} catch (Exception $e) {
			// Errors other than non-existence (e.g. database errors) are logged, never blocking.
			$this->events->logger->warning(
				message: '[SaveObject] Reference validation lookup failed',
				context: ['file' => __FILE__, 'line' => __LINE__, 'property' => $check->propertyName, 'uuid' => $check->uuid, 'error' => $e->getMessage()]
			);
			return false;
		}
	}//end objectFound()

	/**
	 * Ask the store for the referenced object, unfiltered.
	 *
	 * @param ReferenceCheck $check The reference.
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When there is no such object.
	 * @throws Exception When the lookup itself fails.
	 */
	private function find(ReferenceCheck $check): void {
		$this->mapper->find(
			identifier: $check->uuid,
			register: $check->registerEntity,
			schema: $check->targetSchema,
			includeDeleted: false,
			_rbac: false,
			_multitenancy: false
		);
	}//end find()

	/**
	 * Remember, announce and raise the refusal of a reference to nothing.
	 *
	 * @param ReferenceCheck        $check The reference.
	 * @param DoesNotExistException $cause The store's answer.
	 *
	 * @return never
	 *
	 * @throws ReferenceValidationException Always, routed by the existing 422 handlers.
	 */
	private function refuseMissing(ReferenceCheck $check, DoesNotExistException $cause): never {
		$this->verdicts[$check->cacheKey] = false;
		$this->events->refused(check: $check);

		throw new ReferenceValidationException(
			reference: new BrokenReference(
				propertyName: $check->propertyName,
				referencedUuid: $check->uuid,
				targetSchemaSlug: $check->targetSchemaSlug,
				targetRegister: $check->register
			),
			code: 422,
			previous: $cause
		);
	}//end refuseMissing()
}//end class
