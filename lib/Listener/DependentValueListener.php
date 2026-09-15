<?php

/**
 * OpenRegister DependentValueListener
 *
 * Refuses an object whose value for a property falls outside the pairs its
 * dependent value table allows for the controlling property's value.
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
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Service\Rules\DependentValueTable;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The dependent value table, enforced on the one save pipeline.
 *
 * It is a listener on the two write events rather than a branch in the
 * controller, for the reason REQ-REO-004 names: the API create, the API
 * update, the patch, the import, a flow node write and a bulk job write all
 * dispatch these events, so one subscription covers every path and a path
 * added later cannot quietly skip the table.
 *
 * FAIL-SOFT ON ITS OWN FAILURE, FAIL-CLOSED ON THE RULE. A value outside the
 * table refuses the save. The guard being unable to read the schema at all
 * does not: a table that cannot be read must not become the reason nothing in
 * the register can be written. That is the same split
 * {@see CodedValueValidationListener} makes, and the log line says which
 * happened.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
 */
class DependentValueListener implements IEventListener {

	/**
	 * The error code a refused dependent value carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = DependentValueTable::CODE_VALUE_NOT_ALLOWED;

	/**
	 * Constructor.
	 *
	 * @param DependentValueTable $tables Reads the tables and names what they refuse.
	 * @param SchemaMapper $schemas Resolves the schema an object belongs to.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DependentValueTable $tables,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse a write the table does not allow.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent) {
			$this->validate(event: $event, object: $event->getObject(), stored: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent) {
			$this->validate(event: $event, object: $event->getNewObject(), stored: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Validate one write against every table its schema declares.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param ObjectEntity $object The object the write carries.
	 * @param ObjectEntity|null $stored The object as it stands, on an update.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	private function validate(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $object,
		?ObjectEntity $stored,
	): void {
		try {
			$reference = $object->getSchema();
			if ($reference === null || $reference === '') {
				return;
			}

			$data = $object->getObject();
			if (is_array($data) === false) {
				return;
			}

			$schema = $this->schemas->find(id: $reference, _rbac: false, _multitenancy: false);
			$properties = ($schema->getProperties() ?? []);
			if (is_array($properties) === false || $this->tables->declaresAny(properties: $properties) === false) {
				// The whole cost for a schema that declares no table: one array
				// scan, no lookup of the stored object, no refusal path. That
				// is the "behaves exactly as before" of task 5.3.
				return;
			}

			$previous = [];
			if ($stored !== null && is_array($stored->getObject()) === true) {
				$previous = $stored->getObject();
			}

			$violations = $this->tables->violations(
				properties: $properties,
				data: $data,
				stored: $previous
			);
			if ($violations === []) {
				return;
			}

			$this->refuse(event: $event, violations: $violations);
		} catch (Throwable $failure) {
			$this->logger->warning(
				message: '[DependentValueListener] The guard itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
		}//end try
	}//end validate()

	/**
	 * Stop the write, naming both properties in every refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 * @param array<int, array{property: string, controlledBy: string, value: string, controllingValue: string, message: string}> $violations The refusals.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/rules-engine-operability/specs/object-lifecycle/spec.md
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $violations): void {
		$byProperty = [];
		foreach ($violations as $violation) {
			$byProperty[$violation['property']] = $violation['message'];
		}

		$event->setErrors(
			array_merge(
				[
					'code' => self::ERROR_CODE,
					'message' => implode(' ', array_values($byProperty)),
				],
				$byProperty
			)
		);
		$event->stopPropagation();
	}//end refuse()
}//end class
