<?php

/**
 * One pre-destruction notification pass: who was already warned and who is warned now.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob\Support;

use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * The notified set of one DestructionCheckJob pre-destruction pass.
 *
 * It reads the set persisted by earlier runs, tracks which of those UUIDs are
 * still inside the window this run and which are warned about for the first
 * time, and writes back only what is still relevant, so the appconfig blob
 * stays bounded over time (OPS-7).
 *
 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
 */
final class PreDestructionPass {

	/**
	 * App config key for tracking notified objects.
	 */
	private const NOTIFIED_KEY = 'retention_notified_objects';

	/**
	 * The UUIDs earlier runs already warned about.
	 *
	 * @var array<int, string>
	 */
	private readonly array $notified;

	/**
	 * Already-notified UUIDs seen inside the window again this run.
	 *
	 * @var array<string, bool>
	 */
	private array $stillRelevant = [];

	/**
	 * UUIDs warned about for the first time this run.
	 *
	 * @var array<string, bool>
	 */
	private array $newNotified = [];

	/**
	 * How many notifications this run sent.
	 *
	 * @var integer
	 */
	private int $newCount = 0;

	/**
	 * Read the notified set earlier runs persisted.
	 *
	 * @param IAppConfig      $appConfig Where the notified set is persisted.
	 * @param LoggerInterface $logger    The run's logger.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		public readonly LoggerInterface $logger,
	) {
		$notifiedJson   = $this->appConfig->getValueString('openregister', self::NOTIFIED_KEY, '[]');
		$this->notified = (json_decode($notifiedJson, true) ?? []);
	}//end __construct()

	/**
	 * Keep an already-notified object in the set: it is still inside the window.
	 *
	 * @param string|null $uuid The object's uuid.
	 *
	 * @return bool True when the object was already notified about.
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	public function keepIfNotified(?string $uuid): bool {
		if (in_array($uuid, $this->notified, true) === true) {
			$this->stillRelevant[$uuid] = true;
			return true;
		}

		return false;
	}//end keepIfNotified()

	/**
	 * Record that an object was warned about this run.
	 *
	 * @param string|null $uuid The object's uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	public function markNotified(?string $uuid): void {
		$this->newNotified[$uuid] = true;
		$this->newCount++;
	}//end markNotified()

	/**
	 * Persist the rebuilt set when it changed, and log how many were sent.
	 *
	 * @param bool $truncated Whether the scan stopped before seeing every object.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/retention-management/spec.md#requirement-the-system-must-send-pre-destruction-notifications
	 */
	public function save(bool $truncated): void {
		$rebuilt = $this->rebuilt(truncated: $truncated);
		if ($this->newCount > 0 || count($rebuilt) !== count($this->notified)) {
			$this->appConfig->setValueString('openregister', self::NOTIFIED_KEY, json_encode($rebuilt));
			$this->logger->info('[DestructionCheckJob] Sent ' . $this->newCount . ' pre-destruction notifications');
		}
	}//end save()

	/**
	 * The set to persist.
	 *
	 * Only the still-in-window UUIDs plus the freshly notified ones, dropping any
	 * whose destruction date has passed or moved away. A TRUNCATED RUN PRUNES
	 * NOTHING: it did not see every in-window object, so treating what it missed
	 * as "no longer in the window" would re-notify those records on a later run.
	 *
	 * @param bool $truncated Whether the scan stopped before seeing every object.
	 *
	 * @return array<int, string>
	 */
	private function rebuilt(bool $truncated): array {
		if ($truncated === true) {
			return array_values(array_unique(array_merge($this->notified, array_keys($this->newNotified))));
		}

		return array_keys($this->stillRelevant + $this->newNotified);
	}//end rebuilt()
}//end class
