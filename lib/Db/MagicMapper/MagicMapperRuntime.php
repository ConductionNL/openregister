<?php

/**
 * MagicMapperRuntime: the configuration, events and logging of MagicMapper.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * The system and app configuration, event dispatcher and logger MagicMapper runs with.
 *
 * One of the constructor bundles of MagicMapper, built by MapperWiring.
 *
 * @spec openspec/specs/magic-table-reads/spec.md
 */
final class MagicMapperRuntime {
	/**
	 * Constructor.
	 *
	 * @param IConfig          $config          Nextcloud config for settings.
	 * @param IAppConfig       $appConfig       App configuration for feature flags.
	 * @param IEventDispatcher $eventDispatcher Event dispatcher for audit trail events.
	 * @param LoggerInterface  $logger          Logger for debugging and monitoring.
	 */
	public function __construct(
		public readonly IConfig $config,
		public readonly IAppConfig $appConfig,
		public readonly IEventDispatcher $eventDispatcher,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
