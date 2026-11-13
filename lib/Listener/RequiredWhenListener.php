<?php

/**
 * OpenRegister RequiredWhenListener
 *
 * Refuses an object that leaves a conditionally required property empty
 * while its condition holds.
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
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\Support\EventSchemaLookup;
use OCA\OpenRegister\Service\Schemas\RequiredWhenDeclaration;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Throwable;

/**
 * `x-openregister-required-when`, enforced on the one save pipeline.
 *
 * The form shows and checks the rule, but a client that skips the form would
 * skip it too. This is a listener on the two write events, beside
 * {@see DependentValueListener} and for its reason: the API create, update and
 * patch, an import, a flow node write and a bulk job write all dispatch these
 * events, so one subscription covers every path.
 *
 * FAIL-SOFT ON ITS OWN FAILURE, FAIL-CLOSED ON THE RULE. A schema that cannot
 * be read logs a warning and lets the save through; a readable rule that is
 * broken refuses. Every broken rule of the object is refused at once, so the
 * client fixes them in one round trip.
 *
 * @template-implements IEventListener<ObjectCreatingEvent|ObjectUpdatingEvent>
 *
 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
 */
class RequiredWhenListener implements IEventListener {

	/**
	 * The error code a refusal carries, so a client can branch on it.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'required_when';

	/**
	 * Constructor.
	 *
	 * @param EventSchemaLookup $lookup Resolves the object's schema and logs a failure.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly EventSchemaLookup $lookup,
	) {
	}//end __construct()

	/**
	 * Refuse a write that leaves a conditionally required property empty.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/modelling-required-when-enforced/specs/object-lifecycle/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent || $event instanceof ObjectUpdatingEvent) {
			$this->validate(event: $event);
		}
	}//end handle()

	/**
	 * Validate one write against every required-when rule of its schema.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return void
	 */
	private function validate(ObjectCreatingEvent|ObjectUpdatingEvent $event): void {
		try {
			$refusals = $this->refusalsIn(event: $event);
		} catch (Throwable $failure) {
			$this->lookup->logger->warning(
				message: '[RequiredWhenListener] The guard itself failed, allowing the save: ' . $failure->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__, 'exception' => get_class($failure)]
			);
			return;
		}

		if ($refusals !== []) {
			$this->refuse(event: $event, refusals: $refusals);
		}
	}//end validate()

	/**
	 * What the rules refuse in a write, judged on the object as it will be saved.
	 *
	 * On an update that is the stored object with the write laid over it, so
	 * a patch that touches neither field passes or fails as the whole object
	 * would.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The write event.
	 *
	 * @return array<int, array{property: string, message: string}> The refusals, empty when the write may go ahead.
	 */
	private function refusalsIn(ObjectCreatingEvent|ObjectUpdatingEvent $event): array {
		if ($event instanceof ObjectUpdatingEvent) {
			$object = $event->getNewObject();
			return $this->refusalsOf(object: $object, data: array_merge($this->dataOf(object: $event->getOldObject()), $this->dataOf(object: $object)));
		}

		$object = $event->getObject();
		return $this->refusalsOf(object: $object, data: $this->dataOf(object: $object));
	}//end refusalsIn()

	/**
	 * Every property whose conditions all hold while it is empty.
	 *
	 * The whole cost for a schema that declares no rule is one scan of its
	 * properties.
	 *
	 * @param ObjectEntity         $object The object the write carries.
	 * @param array<string, mixed> $data   Its values as they will be saved.
	 *
	 * @return array<int, array{property: string, message: string}> The refusals.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) `fromProperty()` and `isEmpty()` are named constructors and checks on a value object.
	 */
	private function refusalsOf(ObjectEntity $object, array $data): array {
		$refusals = [];
		foreach ($this->propertiesOf(object: $object) as $name => $property) {
			if (is_array($property) === false) {
				continue;
			}

			$declaration = RequiredWhenDeclaration::fromProperty(property: $property, path: '/' . $name);
			if ($declaration === null || $declaration->holds(data: $data) === false) {
				continue;
			}

			if (RequiredWhenDeclaration::isEmpty(value: ($data[$name] ?? null)) === true) {
				$refusals[] = ['property' => (string)$name, 'message' => $declaration->describe()];
			}
		}

		return $refusals;
	}//end refusalsOf()

	/**
	 * The schema's properties, or none when the object names no schema.
	 *
	 * @param ObjectEntity $object The object the write carries.
	 *
	 * @return array<string, mixed> The properties.
	 */
	private function propertiesOf(ObjectEntity $object): array {
		$reference = $object->getSchema();
		if ((string)$reference === '') {
			return [];
		}

		$schema = $this->lookup->catalog->schemas()->find(id: $reference, _rbac: false, _multitenancy: false);
		$properties = ($schema->getProperties() ?? []);
		if (is_array($properties) === false) {
			return [];
		}

		return $properties;
	}//end propertiesOf()

	/**
	 * An object's values, or none.
	 *
	 * @param ObjectEntity|null $object The object.
	 *
	 * @return array<string, mixed> Its values.
	 */
	private function dataOf(?ObjectEntity $object): array {
		$data = $object?->getObject();
		if (is_array($data) === false) {
			return [];
		}

		return $data;
	}//end dataOf()

	/**
	 * Stop the write, naming each property and its condition.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent              $event    The write event.
	 * @param array<int, array{property: string, message: string}> $refusals The refusals.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $refusals): void {
		$sentences = [];
		foreach ($refusals as $refusal) {
			$sentences[] = $refusal['property'] . ': ' . $refusal['message'] . '.';
		}

		$event->setErrors(['code' => self::ERROR_CODE, 'message' => implode(' ', $sentences), 'errors' => $refusals]);
		$event->stopPropagation();
	}//end refuse()
}//end class
