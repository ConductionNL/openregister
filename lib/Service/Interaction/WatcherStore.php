<?php

/**
 * OpenRegister watcher store
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Interaction
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

namespace OCA\OpenRegister\Service\Interaction;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use Psr\Log\LoggerInterface;

/**
 * Where watchers are stored and their schemas looked up.
 *
 * @spec openspec/specs/object-interactions/spec.md
 */
final class WatcherStore {
	/**
	 * Bundle the collaborators.
	 *
	 * @param WatcherMapperInterface $mapper       The watcher rows.
	 * @param SchemaMapperInterface  $schemaMapper Resolves the object's schema for the `update` posture.
	 * @param LoggerInterface        $logger       Logger.
	 */
	public function __construct(
		public readonly WatcherMapperInterface $mapper,
		public readonly SchemaMapperInterface $schemaMapper,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
