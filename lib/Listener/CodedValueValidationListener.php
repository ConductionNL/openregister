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
 * @spec openspec/specs/skos-concept-registers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Exception\CodedValueException;
use OCA\OpenRegister\Service\Vocabulary\CodedValueGuardInterface;
use OCA\OpenRegister\Service\Vocabulary\CodedValueWrite;
use OCA\OpenRegister\Service\Vocabulary\ConceptShapeGuardInterface;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Validates an object's coded values before it is persisted.
 *
 * @spec openspec/specs/skos-concept-registers/spec.md
 * @spec openspec/specs/skos-concept-registers/spec.md
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 */
class CodedValueValidationListener implements IEventListener {
	use ValidatesPreWriteObject;

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
	 * @param CodedValueGuardInterface $guard Answers which coded values are refused.
	 * @param ConceptShapeGuardInterface $shapeGuard Validates a concept against its scheme's shape.
	 * @param SchemaMapperInterface $schemas Resolves the schema an object belongs to.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly CodedValueGuardInterface $guard,
		private readonly ConceptShapeGuardInterface $shapeGuard,
		private readonly SchemaMapperInterface $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Validate one write, and refuse it when the guard says so.
	 *
	 * The guard is a guard, not a gate on unrelated saves. When it fails for a
	 * reason of its own the save proceeds and the failure is named in the log:
	 * a vocabulary that cannot be read must not become the reason nothing can
	 * be written.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param ObjectEntity $object The object the write carries.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	protected function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		try {
			$this->check(event: $event, object: $object);
		} catch (CodedValueException $refused) {
			$this->refuse(event: $event, refusal: self::refusal(code: self::ERROR_CODE, errors: $refused->getErrors()));
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[CodedValueValidationListener] The guard itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try

	}//end validate()

	/**
	 * Refuse a concept failing its scheme's shape, then let the guard enforce the coded values.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param ObjectEntity $object The object the write carries.
	 *
	 * @return void
	 *
	 * @throws CodedValueException When a coded value is refused.
	 *
	 * @spec openspec/specs/skos-concept-registers/spec.md
	 */
	private function check(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $object): void {
		$reference = $object->getSchema();
		if ((string)$reference === '') {
			return;
		}

		$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
		$data = $this->writtenData(object: $object);
		$shapeErrors = $this->shapeGuard->violations(object: $data, schema: $schema);
		if ($shapeErrors !== []) {
			$this->refuse(event: $event, refusal: self::refusal(code: self::ERROR_CODE_SHAPE, errors: $shapeErrors));
			return;
		}

		$this->guard->enforce(new CodedValueWrite(object: $data, schema: $schema));
	}//end check()

	/**
	 * Stop the write, carrying the refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The write event.
	 * @param array<string,string>                    $refusal The refusal payload, see {@see self::refusal()}.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $refusal): void {
		$event->setErrors($refusal);
		$event->stopPropagation();
	}//end refuse()

	/**
	 * The refusal payload: the code, one message joining every refusal, then the refusals by property.
	 *
	 * @param string               $code   The machine-readable refusal code.
	 * @param array<string,string> $errors The refusals keyed by property name.
	 *
	 * @return array<string,string> The payload the write event carries.
	 */
	private static function refusal(string $code, array $errors): array {
		return array_merge(
			[
				'code' => $code,
				'message' => implode(' ', array_values($errors)),
			],
			$errors
		);
	}//end refusal()
}//end class
