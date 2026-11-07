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

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Lifecycle\KeepLifecycleStatesScope;
use OCA\OpenRegister\Service\SystemOperationContext;
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
	 * @param LifecycleEditReader $edits Reads the object's lifecycle declaration.
	 * @param ReferenceResolver $references Cross-object reference resolver (dynamic initial).
	 * @param LoggerInterface $logger PSR logger for warnings.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly LifecycleEditReader $edits,
		private readonly ReferenceResolver $references,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply the schema-declared initial lifecycle value when missing, and refuse a create in another state.
	 *
	 * @param Event $event Inbound dispatcher event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectCreatingEvent) === true) {
			$this->initialise(event: $event);
		}
	}//end handle()

	/**
	 * Apply the initial state when the object's schema declares a lifecycle.
	 *
	 * @param ObjectCreatingEvent $event The create.
	 *
	 * @return void
	 */
	private function initialise(ObjectCreatingEvent $event): void {
		$declaration = $this->edits->declarationOf(object: $event->getObject(), logAs: 'Lifecycle initial-state listener');
		if ($declaration !== null) {
			$this->applyInitial(create: new LifecycleCreate(event: $event, declaration: $declaration));
		}
	}//end initialise()

	/**
	 * Fill an empty lifecycle field with the resolved initial state, or refuse a create in another state.
	 *
	 * A value the caller sets must equal the resolved initial state; when the initial
	 * cannot be resolved, a value the caller sets is refused. A write inside
	 * SystemOperationContext (a seed, an example set, a configuration import) may
	 * create in any state, as it may write declared state on an update.
	 *
	 * @param LifecycleCreate $create The create.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-create-starts-in-the-declared-initial-state
	 */
	private function applyInitial(LifecycleCreate $create): void {
		if ($create->declaresInitial() === false) {
			return;
		}

		$initial = $this->resolveInitial(create: $create);
		if ($create->hasChosen() === false) {
			$create->fill(initial: $initial);
			return;
		}

		$allowed = $this->createStates(annotation: $create->declaration->annotation);
		if ($this->mayStartIn(chosen: $create->chosen(), states: [$initial, ...$allowed]) === false) {
			$create->refuse(error: $this->refusal(create: $create, initial: $initial));
		}
	}//end applyInitial()

	/**
	 * The states a create may start in besides the initial one: the string entries of `createStates`.
	 *
	 * A value that is not a list allows nothing extra; the annotation validator reports it.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-lifecycle` block.
	 *
	 * @return list<string>
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-create-starts-in-the-declared-initial-state
	 */
	private function createStates(array $annotation): array {
		$declared = ($annotation['createStates'] ?? []);
		if (is_array($declared) === false || array_is_list($declared) === false) {
			return [];
		}

		return array_values(array_filter($declared, static fn (mixed $state): bool => is_string($state) === true && $state !== ''));
	}//end createStates()

	/**
	 * Whether a create may start in the chosen state: a declared one, or any when the system writes it or a historic import keeps it.
	 *
	 * @param mixed             $chosen The caller's value.
	 * @param array<int, mixed> $states The resolved initial state and the declared `createStates`.
	 *
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) SystemOperationContext and KeepLifecycleStatesScope are ambient markers; a static read is
	 *   the whole point of them.
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-create-starts-in-the-declared-initial-state
	 */
	private function mayStartIn(mixed $chosen, array $states): bool {
		return in_array($chosen, $states, true) === true
			|| SystemOperationContext::isActive() === true
			|| KeepLifecycleStatesScope::isActive() === true;
	}//end mayStartIn()

	/**
	 * The refusal of a create in a state it may not start in.
	 *
	 * @param LifecycleCreate $create  The create.
	 * @param string|null     $initial The resolved initial state.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/lifecycle-guards-see-the-stored-object/specs/object-lifecycle/spec.md#requirement-a-create-starts-in-the-declared-initial-state
	 */
	private function refusal(LifecycleCreate $create, ?string $initial): array {
		$chosen = 'a non-text value';
		if (is_scalar($create->chosen()) === true) {
			$chosen = (string)$create->chosen();
		}

		return [
			'code' => 'lifecycle-invalid-initial',
			'field' => $create->declaration->field(),
			'attempted' => $create->chosen(),
			'initial' => $initial,
			'allowed' => $this->createStates(annotation: $create->declaration->annotation),
			'message' => sprintf('A new object starts in "%s"; "%s" cannot be set when it is created.', (string)$initial, $chosen),
		];
	}//end refusal()

	/**
	 * Resolve the `initial` annotation to a concrete state string.
	 *
	 * Static string: returned as-is. A `{ "from": <ref>, "field": <field> }` dict or a
	 * `"@ref.<ref>.<field>"` token: resolved from the related object declared in
	 * `x-openregister-references`. Anything unresolvable yields null (a logged no-op
	 * upstream).
	 *
	 * @param LifecycleCreate $create The create.
	 *
	 * @return string|null The resolved initial state, or null when unresolvable.
	 */
	private function resolveInitial(LifecycleCreate $create): ?string {
		$initial = $create->declaration->annotation['initial'];
		if (is_string($initial) === true && str_starts_with($initial, '@ref.') === false) {
			return $initial;
		}

		$reference = $this->referenceIn(initial: $initial);
		if ($reference->isComplete() === false) {
			return null;
		}

		return $this->resolveFromReference(reference: $reference, create: $create);
	}//end resolveInitial()

	/**
	 * The reference a dynamic `initial` declares; an incomplete one when it has neither form.
	 *
	 * @param mixed $initial A `@ref.` token or a reference dict.
	 *
	 * @return LifecycleInitialReference
	 */
	private function referenceIn(mixed $initial): LifecycleInitialReference {
		if (is_string($initial) === true) {
			$parts = explode('.', substr($initial, 5), 2);
			return new LifecycleInitialReference(name: ($parts[0] ?? ''), field: ($parts[1] ?? ''));
		}

		if (is_array($initial) === true) {
			return new LifecycleInitialReference(name: (string)($initial['from'] ?? ''), field: (string)($initial['field'] ?? ''));
		}

		return new LifecycleInitialReference(name: '', field: '');
	}//end referenceIn()

	/**
	 * Resolve `<field>` from the related object behind a declared reference name.
	 *
	 * Resolves the schema's `x-openregister-references` map (the same FK
	 * plumbing the calculation engine uses) and reads `<field>` off the
	 * named reference's resolved data. RBAC + tenant scoped; never throws.
	 *
	 * @param LifecycleInitialReference $reference The declared reference and the field to read.
	 * @param LifecycleCreate           $create    The create.
	 *
	 * @return string|null The related field value as a string, or null when unresolvable.
	 */
	private function resolveFromReference(LifecycleInitialReference $reference, LifecycleCreate $create): ?string {
		$references = $this->getReferences(schema: $create->declaration->schema);
		if (array_key_exists($reference->name, $references) === false) {
			$this->logger->warning(
				sprintf('Lifecycle dynamic initial: reference "%s" is not declared on the schema.', $reference->name)
			);
			return null;
		}

		return $this->relatedValue(reference: $reference, related: $this->related(reference: $reference, create: $create));
	}//end resolveFromReference()

	/**
	 * The resolved data of the related object behind a declared reference.
	 *
	 * @param LifecycleInitialReference $reference The declared reference.
	 * @param LifecycleCreate           $create    The create.
	 *
	 * @return mixed
	 */
	private function related(LifecycleInitialReference $reference, LifecycleCreate $create): mixed {
		$declared = $this->getReferences(schema: $create->declaration->schema)[$reference->name];
		$resolved = $this->references->resolveAll(
			payload: $create->data(),
			references: [$reference->name => $declared],
			register: $create->object()->getRegister(),
			organisation: $create->object()->getOrganisation()
		);

		return ($resolved[$reference->name] ?? null);
	}//end related()

	/**
	 * Read the referenced field off the related object.
	 *
	 * @param LifecycleInitialReference $reference The declared reference and the field to read.
	 * @param mixed                     $related   The related object's resolved data.
	 *
	 * @return string|null The value as text, or null when it is missing or empty.
	 */
	private function relatedValue(LifecycleInitialReference $reference, mixed $related): ?string {
		if (is_array($related) === false || array_key_exists($reference->field, $related) === false) {
			$this->logger->warning(
				sprintf('Lifecycle dynamic initial: reference "%s" resolved no "%s" field.', $reference->name, $reference->field)
			);
			return null;
		}

		return $this->stateText(value: $related[$reference->field]);
	}//end relatedValue()

	/**
	 * A related value as a state, or null when it is empty.
	 *
	 * @param mixed $value The related value.
	 *
	 * @return string|null
	 */
	private function stateText(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end stateText()

	/**
	 * Read the `x-openregister-references` configuration block.
	 *
	 * @param Schema $schema Schema to inspect.
	 *
	 * @return array<string, mixed> References map, empty when absent.
	 */
	private function getReferences(Schema $schema): array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config['x-openregister-references'] ?? null);
		if (is_array($value) === true) {
			return $value;
		}

		return [];
	}//end getReferences()
}//end class
