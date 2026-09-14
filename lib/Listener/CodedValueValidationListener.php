<?php

/**
 * OpenRegister CodedValueValidationListener
 *
 * Refuses a write whose coded values the vocabulary no longer accepts: a
 * value outside its validity window, a broader value under a leaf-only
 * property, or two values of one exclusive group.
 *
 * It hangs off `ObjectCreatingEvent` / `ObjectUpdatingEvent` because those are
 * dispatched by `MagicMapper` for every persisted object, so the objects API,
 * the admin surfaces and the configuration importer all pass through here. A
 * refusal that only guarded the objects API would be bypassed by the door that
 * carries bulk edits, which is the door a code-list migration uses.
 *
 * Stopping propagation with errors is what `MagicMapper` turns into a
 * `HookStoppedException`, which the objects controller already answers as
 * HTTP 422. That is the status the spec asks for, and it is the honest one:
 * the body is well-formed and the vocabulary refuses it.
 *
 * The guard never refuses a READ. A record saved in 2019 with a value retired
 * in 2026 is read back unchanged and still resolves its label; only a new
 * write of that value is refused (design.md D-1).
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuard;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuard;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates an object's coded values before it is persisted.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class CodedValueValidationListener implements IEventListener {

	/**
	 * The error code a refused coded value carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'coded-value-refused';

	/**
	 * The error code a concept failing its scheme's declared shape carries.
	 *
	 * @var string
	 */
	public const ERROR_CODE_SHAPE = 'concept-shape-invalid';

	/**
	 * Constructor.
	 *
	 * @param CodedValueGuard $guard Answers which coded values are refused.
	 * @param ConceptShapeGuard $shapeGuard Validates a concept against its scheme's shape.
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly CodedValueGuard $guard,
		private readonly ConceptShapeGuard $shapeGuard,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Refuse a write the vocabulary does not accept.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
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
	 * Validate one write, and refuse it when the guard says so.
	 *
	 * The event type is a union rather than Event, because setErrors() is
	 * declared on each write event separately instead of on a shared parent.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param ObjectEntity $object The object the write carries.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/code-list-lifecycle-and-hierarchy/specs/skos-concept-registers/spec.md
	 */
	private function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		try {
			$reference = $object->getSchema();
			if ($reference === null || $reference === '') {
				return;
			}

			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);

			$data = $object->getObject();
			if (is_array($data) === false) {
				$data = [];
			}

			$shapeErrors = $this->shapeGuard->violations(object: $data, schema: $schema);
			if ($shapeErrors !== []) {
				$this->refuse(event: $event, code: self::ERROR_CODE_SHAPE, errors: $shapeErrors);
				return;
			}

			$this->guard->enforce(object: $data, schema: $schema);
		} catch (CodedValueException $refused) {
			$this->refuse(event: $event, code: self::ERROR_CODE, errors: $refused->getErrors());
		} catch (Throwable $failure) {
			// The guard is a guard, not a gate on unrelated saves. When it
			// fails for a reason of its own the save proceeds and the failure
			// is named in the log: a vocabulary that cannot be read must not
			// become the reason nothing can be written.
			$this->logger->warning(
				message: '[CodedValueValidationListener] The guard itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end validate()

	/**
	 * Stop the write, carrying the refusals.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param string $code The machine-readable refusal code.
	 * @param array<string,string> $errors The refusals keyed by property name.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, string $code, array $errors): void {
		$event->setErrors(
			array_merge(
				[
					'code' => $code,
					'message' => implode(' ', array_values($errors)),
				],
				$errors
			)
		);
		$event->stopPropagation();
	}//end refuse()
}//end class
