<?php
/**
 * Contributes the team-resources declaration check to the schema save.
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
use OCA\OpenRegister\Reference\TeamResourceDeclaration;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the `x-openregister-team-resources` check, in its slot of the schema save.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
 */
final class TeamResourcesDeclarationCheckListener implements IEventListener {

	/**
	 * Add the check to the schema save.
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

		$event->add(
			check: new PositionedDeclarationCheck(
				position: SchemaDeclarationOrder::TEAM_RESOURCES,
				check: fn (Schema $schema) => $this->checkTeamResources(schema: $schema)
			)
		);
	}//end handle()

	/**
	 * Refuse a save whose `x-openregister-team-resources` names no property, or one this schema does not have.
	 *
	 * A team page reading a property that is not there would list nothing, and
	 * the schema would look opted in while no team ever sees its objects.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 *
	 * @throws \Exception When the declaration names no property of this schema.
	 *
	 * @spec openspec/changes/platform-team-resource-provider/specs/platform-team-resources/spec.md#requirement-a-teams-page-lists-the-objects-that-team-owns-req-ptr-001
	 */
	public function checkTeamResources(Schema $schema): void {
		$annotation = new SchemaAnnotation(schema: $schema, key: TeamResourceDeclaration::ANNOTATION);
		$annotation->faults()->refuseWith(
			messages: TeamResourceDeclaration::faults(
				block: $annotation->value(),
				properties: ($schema->getProperties() ?? [])
			)
		);
	}//end checkTeamResources()
}//end class
