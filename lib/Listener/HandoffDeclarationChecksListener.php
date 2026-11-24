<?php
/**
 * Contributes the hand-off declaration checks to the schema save.
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

use Closure;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\HandoffDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Adds the hand-off annotation and hand-off contract binding checks.
 *
 * Each runs in its slot of SchemaDeclarationOrder, so the schema save keeps
 * the order it had when the mapper ran these checks itself.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/semantic-object-handoff/spec.md
 */
final class HandoffDeclarationChecksListener implements IEventListener {

	/**
	 * Contribute the checks.
	 *
	 * @param Event $event The collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/semantic-object-handoff/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof SchemaDeclarationChecksEvent) === false) {
			return;
		}

		foreach ($this->checks() as $position => $check) {
			$event->add(check: new PositionedDeclarationCheck(position: $position, check: $check));
		}
	}//end handle()

	/**
	 * The checks by slot, each taking the schema.
	 *
	 * @return array<int, Closure>
	 */
	private function checks(): array {
		$refusals = new HandoffDeclarationRefusals();
		return [
			SchemaDeclarationOrder::HANDOFF => static fn (Schema $schema) => $refusals->checkHandoff(schema: $schema),
			SchemaDeclarationOrder::HANDOFF_CONTRACT => static fn (Schema $schema) => $refusals->checkHandoffContract(schema: $schema),
		];
	}//end checks()
}//end class
