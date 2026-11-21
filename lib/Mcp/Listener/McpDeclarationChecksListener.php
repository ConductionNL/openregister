<?php

/**
 * McpDeclarationChecksListener: contributes the MCP declaration check to the schema save.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Mcp\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\McpDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the `x-openregister-mcp` check in its slot of SchemaDeclarationOrder.
 *
 * The slot is the one the schema mapper ran this check in itself, so a schema
 * with several faults is still refused for the same one.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class McpDeclarationChecksListener implements IEventListener {

	/**
	 * Contribute the check.
	 *
	 * @param Event $event The collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		$check = new McpDeclarationCheck();
		$event->add(
			check: new PositionedDeclarationCheck(
				position: SchemaDeclarationOrder::MCP,
				check: static fn (Schema $schema) => $check->check(schema: $schema)
			)
		);
	}//end handle()
}//end class
