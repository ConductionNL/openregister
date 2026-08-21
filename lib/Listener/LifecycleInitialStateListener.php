<?php

/**
 * OpenRegister LifecycleInitialStateListener
 *
 * Subscribes to ObjectCreatingEvent and force-sets the lifecycle field
 * to the schema's declared `initial` value when the caller did not
 * supply a value. Apps don't need to remember to set it.
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
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Force the lifecycle field to its declared initial state on create.
 *
 * Matches the principle that lifecycle is a declarative property of the
 * schema — apps shouldn't need to know the initial state.
 *
 * The declared `initial` may be EITHER:
 * - a static string (e.g. `"open"`), or
 * - a dynamic reference resolved from a related object — declared as
 *   `{ "from": "<refName>", "field": "<field>" }` or the equivalent token
 *   string `"@ref.<refName>.<field>"`. The `<refName>` must be declared in the
 *   schema's `x-openregister-references` block; the related object is resolved
 *   via the same FK plumbing the calculation engine uses (ReferenceResolver),
 *   and the named field's value becomes the initial state.
 *
 * Either way the value is force-set ONLY when the caller left the lifecycle
 * field empty; a caller-supplied value is never overridden. An unresolvable
 * dynamic reference is a logged no-op.
 *
 * @template-implements IEventListener<ObjectCreatingEvent>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The listener wires the schema mapper,
 *   the cross-object reference resolver and the logger; each is a distinct collaborator in
 *   the create-time lifecycle-initialisation path and none can be folded away.
 */
class LifecycleInitialStateListener implements IEventListener {
	/**
	 * Wire collaborators used to look up schema lifecycle metadata.
	 *
	 * @param SchemaMapper $schemaMapper Schema lookup mapper.
	 * @param ReferenceResolver $references Cross-object reference resolver (dynamic initial).
	 * @param LoggerInterface $logger PSR logger for warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly ReferenceResolver $references,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the schema-declared initial lifecycle value when missing.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === false) {
			return;
		}

		$object = $event->getObject();
		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return;
		}

		$annotation = $this->getLifecycleAnnotation(schema: $schema);
		if ($annotation === null) {
			return;
		}

		$this->applyInitial(object: $object, schema: $schema, annotation: $annotation);
	}//end handle()

	/**
	 * Apply the resolved initial value to the lifecycle field when it is empty.
	 *
	 * @param ObjectEntity $object The object being created.
	 * @param Schema $schema The resolved schema.
	 * @param array<string, mixed> $annotation The `x-openregister-lifecycle` block.
	 *
	 * @return void
	 */
	private function applyInitial(ObjectEntity $object, Schema $schema, array $annotation): void {
		$field = (string)($annotation['field'] ?? '');
		if ($field === '' || array_key_exists('initial', $annotation) === false) {
			return;
		}

		$data = $object->getObject() ?? [];

		// Caller already set a value — leave it alone (validator covers it).
		// Resolve the (possibly dynamic) initial value only AFTER this guard so
		// we never read a related object when the caller already chose.
		if (isset($data[$field]) === true && $data[$field] !== '') {
			return;
		}

		$initial = $this->resolveInitial(
			initial: $annotation['initial'],
			object: $object,
			schema: $schema,
			data: $data
		);
		if ($initial === null || $initial === '') {
			return;
		}

		$data[$field] = $initial;
		$object->setObject($data);
	}//end applyInitial()

	/**
	 * Resolve the `initial` annotation to a concrete state string.
	 *
	 * Static string → returned as-is. A `{ "from": <ref>, "field": <field> }`
	 * dict OR a `"@ref.<ref>.<field>"` token → resolved from the related object
	 * declared in `x-openregister-references`. Anything unresolvable yields
	 * null (a logged no-op upstream).
	 *
	 * @param mixed $initial The declared initial value (string or reference dict).
	 * @param ObjectEntity $object The object being created.
	 * @param Schema $schema The resolved schema (for its references block).
	 * @param array<string, mixed> $data The object's current data payload.
	 *
	 * @return string|null The resolved initial state, or null when unresolvable.
	 */
	private function resolveInitial(mixed $initial, ObjectEntity $object, Schema $schema, array $data): ?string {
		// Static string form — but recognise the "@ref.<ref>.<field>" token.
		if (is_string($initial) === true) {
			if (str_starts_with($initial, '@ref.') === true) {
				$parts = explode('.', substr($initial, 5), 2);
				$refName = ($parts[0] ?? '');
				$refField = ($parts[1] ?? '');
				return $this->resolveFromReference(
					refName: $refName,
					field: $refField,
					object: $object,
					schema: $schema,
					data: $data
				);
			}

			return $initial;
		}

		// Dynamic reference dict { "from": <ref>, "field": <field> }.
		if (is_array($initial) === true) {
			$refName = (string)($initial['from'] ?? '');
			$field = (string)($initial['field'] ?? '');
			if ($refName === '' || $field === '') {
				return null;
			}

			return $this->resolveFromReference(
				refName: $refName,
				field: $field,
				object: $object,
				schema: $schema,
				data: $data
			);
		}

		return null;
	}//end resolveInitial()

	/**
	 * Resolve `<field>` from the related object behind a declared reference name.
	 *
	 * Resolves the schema's `x-openregister-references` map (the same FK
	 * plumbing the calculation engine uses) and reads `<field>` off the
	 * named reference's resolved data. RBAC + tenant scoped; never throws.
	 *
	 * @param string $refName The declared reference name.
	 * @param string $field The field on the related object to read.
	 * @param ObjectEntity $object The object being created.
	 * @param Schema $schema The resolved schema.
	 * @param array<string, mixed> $data The object's current data payload.
	 *
	 * @return string|null The related field value as a string, or null when unresolvable.
	 */
	private function resolveFromReference(
		string $refName,
		string $field,
		ObjectEntity $object,
		Schema $schema,
		array $data,
	): ?string {
		if ($refName === '' || $field === '') {
			return null;
		}

		$references = $this->getReferences(schema: $schema);
		if ($references === null || array_key_exists($refName, $references) === false) {
			$this->logger->warning(
				sprintf('Lifecycle dynamic initial: reference "%s" is not declared on the schema.', $refName)
			);
			return null;
		}

		$resolved = $this->references->resolveAll(
			payload: $data,
			references: [$refName => $references[$refName]],
			register: $object->getRegister()
		);

		$related = ($resolved[$refName] ?? null);
		if (is_array($related) === false || array_key_exists($field, $related) === false) {
			$this->logger->warning(
				sprintf('Lifecycle dynamic initial: reference "%s" resolved no "%s" field.', $refName, $field)
			);
			return null;
		}

		$value = $related[$field];
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end resolveFromReference()

	/**
	 * Read the `x-openregister-references` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null References map, or null when absent.
	 */
	private function getReferences(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config['x-openregister-references'] ?? null);
		if (is_array($value) === true && count($value) > 0) {
			return $value;
		}

		return null;
	}//end getReferences()

	/**
	 * Look up the schema referenced by an object instance.
	 *
	 * @param ObjectEntity $object Object whose schema reference to resolve.
	 *
	 * @return Schema|null Resolved schema, or null on lookup failure.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$schemaRef = $object->getSchema();
		if ($schemaRef === null || $schemaRef === '') {
			return null;
		}

		try {
			return $this->schemaMapper->find($schemaRef, _multitenancy: false);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'Lifecycle initial-state listener could not load schema "%s": %s',
					(string)$schemaRef,
					$e->getMessage()
				)
			);
			return null;
		}
	}//end loadSchema()

	/**
	 * Read the `x-openregister-lifecycle` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed>|null Lifecycle annotation, or null when missing.
	 */
	private function getLifecycleAnnotation(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$annotation = ($config['x-openregister-lifecycle'] ?? null);
		if (is_array($annotation) === true) {
			return $annotation;
		}

		return null;
	}//end getLifecycleAnnotation()
}//end class
