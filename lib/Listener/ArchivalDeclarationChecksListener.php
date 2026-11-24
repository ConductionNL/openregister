<?php
/**
 * Contributes the archival declaration checks to the schema save.
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
use OCA\OpenRegister\Db\SchemaMapper\ArchivalDeclarationRefusals;
use OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Adds the MDTO element-mapping, archival annotation and retention declaration checks.
 *
 * Each runs in its slot of SchemaDeclarationOrder, so the schema save keeps
 * the order it had when the mapper ran these checks itself.
 *
 * @template-implements IEventListener<SchemaDeclarationChecksEvent>
 *
 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
 */
final class ArchivalDeclarationChecksListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger the archival check reports unknown keys to.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Contribute the checks.
	 *
	 * @param Event $event The collection event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archival-annotation-vocabulary/spec.md
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
		$refusals = new ArchivalDeclarationRefusals(logger: $this->logger);
		return [
			SchemaDeclarationOrder::MDTO_MAPPING => static fn (Schema $schema) => $refusals->checkMdtoMapping(schema: $schema),
			SchemaDeclarationOrder::ARCHIVAL => static function (Schema $schema) use ($refusals): void {
				$refusals->checkArchival(schema: $schema);
				$refusals->checkAnonymisationProfile(schema: $schema);
				$refusals->checkExpiry(schema: $schema);
			},
			SchemaDeclarationOrder::RETENTION => static fn (Schema $schema) => $refusals->checkRetention(schema: $schema),
		];
	}//end checks()
}//end class
