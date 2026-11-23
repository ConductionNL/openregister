<?php

/**
 * SearchDeclarationChecksListener: Search's check of the widgets a schema declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Search\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidatorInterface;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Contributes the `x-openregister-widgets` check to the schema save, in the
 * WIDGETS slot of SchemaDeclarationOrder, where the mapper ran it itself.
 *
 * Schemas that pre-declare dashboard widgets (e.g. the `dashboard` schema in
 * the `reports` register) carry the array under
 * `configuration['x-openregister-widgets']`. The save refuses a malformed block,
 * so operators see shape errors at schema-save time rather than at first render.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
 */
final class SearchDeclarationChecksListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param WidgetAnnotationValidatorInterface $widgets Validates the widget annotation; it keeps no state.
	 */
	public function __construct(
		private readonly WidgetAnnotationValidatorInterface $widgets,
	) {
	}//end __construct()

	/**
	 * Add the widgets check to the schema save.
	 *
	 * @param Event $event The schema declaration checks being collected.
	 *
	 * @return void
	 *
	 * @spec exclude schema save plumbing moved out of SchemaMapper, not a capability of its own
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$event->add(
			check: new PositionedDeclarationCheck(
				position: SchemaDeclarationOrder::WIDGETS,
				check: fn (Schema $schema) => $this->checkWidgets(schema: $schema)
			)
		);
	}//end handle()

	/**
	 * Refuse a malformed `x-openregister-widgets` annotation.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @throws \Exception When the annotation is malformed.
	 *
	 * @return void
	 *
	 * @spec exclude moved unchanged from RecordAnnotationRefusals, which named no spec for it
	 */
	public function checkWidgets(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: 'x-openregister-widgets');
		if ($annotation->isBlock() === true) {
			$annotation->faults()->refuse(
				errors: $this->widgets->validate(['x-openregister-widgets' => $annotation->value()])
			);
		}
	}//end checkWidgets()
}//end class
