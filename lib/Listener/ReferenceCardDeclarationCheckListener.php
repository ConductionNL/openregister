<?php
/**
 * Contributes the reference-card declaration check to the schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Service\Reference\ReferenceCardDeclaration;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the `x-openregister-card` check, in the slot it held in the mapper.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
 */
final class ReferenceCardDeclarationCheckListener implements IEventListener {

	/**
	 * Add the checks to the schema save.
	 *
	 * @param Event $event The collect event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/runtime-schema-api/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$checks = [
			SchemaDeclarationOrder::REFERENCE_CARD => fn (Schema $schema) => $this->checkReferenceCard(schema: $schema),
		];
		foreach ($checks as $position => $check) {
			$event->add(check: new PositionedDeclarationCheck(position: $position, check: $check));
		}
	}//end handle()

	/**
	 * Refuse a save whose `x-openregister-card` names a property this schema does not have.
	 *
	 * A card that names a property that is not there would render an empty row
	 * in a chat window, where nobody can see why.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When the card names something the schema does not have.
	 *
	 * @spec openspec/changes/platform-reference-provider/specs/schema-scoped-reference-providers/spec.md#requirement-a-schema-declares-what-its-reference-card-shows-req-prp-002
	 */
	public function checkReferenceCard(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: ReferenceCardDeclaration::ANNOTATION);
		$annotation->faults()->refuseWith(
			messages: ReferenceCardDeclaration::faults(
				block: $annotation->value(),
				properties: ($schema->getProperties() ?? [])
			)
		);
	}//end checkReferenceCard()
}//end class
