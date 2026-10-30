<?php

/**
 * A schema write that is not a schema API request re-checks the forms that submit into it.
 *
 * "Saving a schema re-checks its forms" holds for the schema API
 * (`SchemasController::applyUpdate()`), which judges the proposed definition
 * before the write and can refuse it. The other writers (the configuration
 * importer, which is how an app upgrade ships a new required property, the
 * import endpoint, the AI `SchemaTool`, the MCP schema tools) all end in
 * `SchemaMapper::update()`, so this listens there. They have no response to
 * refuse, so a form whose owning app set `formDestinationBreak` to `refuse` is
 * unpublished here like any other: the form stops accepting submissions that
 * would 422 every resident, its author is told, and the log says the refusal
 * could not be honoured.
 *
 * Cheap on the ordinary save: only a write that changed `properties` or
 * `required` goes on, and the check is fetched from the container only then.
 * A save the schema API claimed is left to it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCA\OpenRegister\Service\Form\FormDependentsCheck;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Unpublishes the forms a schema write broke, for every writer but the schema API.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
class FormDestinationSchemaListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves the check once a definition changed.
	 * @param LoggerInterface    $logger    Records a check that could not run and a refusal that could not be honoured.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Judge the dependent forms of a schema whose definition changed.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof SchemaUpdatedEvent === false) {
			return;
		}

		$schema = $event->getNewSchema();
		if ($this->definitionChanged(new: $schema, old: $event->getOldSchema()) === false) {
			return;
		}

		try {
			$check = $this->container->get(FormDependentsCheck::class);
			if ($check instanceof FormDependentsCheck === false || $check->isClaimed(schemaId: (int)$schema->getId()) === true) {
				return;
			}

			$assessment = $check->assess(schema: $schema);
			if ($assessment['affected'] === []) {
				return;
			}

			if ($assessment['refused'] === true) {
				$this->logger->warning(
					message: '[FormDestinationSchemaListener] An owning app set formDestinationBreak to refuse, but this schema write '
					. 'has no response to refuse; the broken forms are unpublished instead',
					context: ['schema' => $assessment['schema'], 'forms' => count($assessment['affected'])]
				);
				$assessment['refused'] = false;
			}

			$check->apply(assessment: $assessment);
		} catch (Throwable $e) {
			// The write has happened. A check that fails must not undo it, but it must leave a trace.
			$this->logger->warning(
				message: '[FormDestinationSchemaListener] The published forms of a changed schema could not be re-checked',
				context: ['schema' => (int)$schema->getId(), 'error' => $e->getMessage()]
			);
		}
	}//end handle()

	/**
	 * Whether a write changed what a form is judged against: the properties or the required list.
	 *
	 * Compared by content, not by key order, so a re-import that only reorders
	 * the keys judges no form.
	 *
	 * @param Schema $new The schema after the write.
	 * @param Schema $old The schema before it.
	 *
	 * @return bool True when properties or required differ.
	 */
	private function definitionChanged(Schema $new, Schema $old): bool {
		return $this->canonical(value: ($new->getProperties() ?? [])) !== $this->canonical(value: ($old->getProperties() ?? []))
			|| $this->canonical(value: ($new->getRequired() ?? [])) !== $this->canonical(value: ($old->getRequired() ?? []));
	}//end definitionChanged()

	/**
	 * A value with every map's keys in the same order, recursively.
	 *
	 * @param array<int|string, mixed> $value The value.
	 *
	 * @return array<int|string, mixed> The value, maps sorted by key.
	 */
	private function canonical(array $value): array {
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->canonical(value: $item);
			}
		}

		ksort($value);

		return $value;
	}//end canonical()
}//end class
