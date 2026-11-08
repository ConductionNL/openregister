<?php

/**
 * ErasureDestroyGuard — the two per-object refusals in front of every destruction.
 *
 * An approval is not a permission, and a hold placed after the approval has to
 * win. So before an erasure destroys an object the destroy right is asked, and
 * only when it allows are the retention clocks asked. The first refusal is the
 * answer; neither is skipped and their order does not change.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Gdpr\Erasure
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Erasure;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Deletion\DestroyRightService;
use OCA\OpenRegister\Service\Deletion\DestructionRefusedException;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;

/**
 * Asks the destroy right, then the retention clocks, for one object.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Erasure
 *
 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
 */
class ErasureDestroyGuard {
	/**
	 * Wire the guard.
	 *
	 * @param DestroyRightService   $destroyRight Whether this caller may destroy.
	 * @param RetentionClockService $clockService Whether the clocks allow it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DestroyRightService $destroyRight,
		private readonly RetentionClockService $clockService,
	) {
	}//end __construct()

	/**
	 * The refusal that stops this object's destruction, or null when none does.
	 *
	 * @param ObjectEntity $object The object about to be destroyed.
	 * @param Schema|null  $schema Its schema.
	 *
	 * @return DestructionRefusedException|null The first refusal.
	 *
	 * @spec openspec/specs/gdpr-data-subject-rights/spec.md
	 */
	public function refusalFor(ObjectEntity $object, ?Schema $schema): ?DestructionRefusedException {
		return ($this->destroyRight->refusalFor(object: $object, schema: $schema)
			?? $this->clockService->refusalFor(object: $object));
	}//end refusalFor()
}//end class
