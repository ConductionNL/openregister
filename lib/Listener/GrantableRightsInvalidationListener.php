<?php

/**
 * OpenRegister GrantableRightsInvalidationListener
 *
 * Drops the grantable-rights index whenever a schema is created, updated or
 * deleted.
 *
 * ⚠️ This is the WRITE half of the index's correctness, and it is the half that
 * fails silently. There is no TTL to fall back on by design: if this listener
 * does not fire, a right removed from a schema keeps being offered, and nothing
 * about the stale answer looks wrong to the person reading it.
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
 * @spec openspec/changes/declared-actions-and-mcp-scope/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Service\Authorization\GrantableRightsIndex;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Invalidates the grantable-rights index on any schema write.
 *
 * @template-implements IEventListener<Event>
 */
class GrantableRightsInvalidationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param GrantableRightsIndex $index  The index to invalidate.
	 * @param LoggerInterface      $logger Diagnostics.
	 */
	public function __construct(
		private readonly GrantableRightsIndex $index,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Invalidate on any schema write.
	 *
	 * Deliberately blind to WHICH schema changed: the index is a single cache
	 * entry covering every schema, so there is no partial invalidation to do,
	 * and inspecting the event to decide would only add a way to get it wrong.
	 *
	 * @param Event $event The schema create/update/delete event.
	 *
	 * @return void
	 */
	public function handle(Event $event): void {
		try {
			$this->index->invalidate();
		} catch (\Throwable $e) {
			// Log loudly. A failed invalidation leaves a stale permission menu
			// behind, and this line is the only trace it ever happened.
			$this->logger->error(
				message: '[GrantableRightsInvalidationListener] invalidation FAILED — the rights index may now be stale',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'event' => $event::class,
					'error' => $e->getMessage(),
				]
			);
		}//end try
	}//end handle()
}//end class
