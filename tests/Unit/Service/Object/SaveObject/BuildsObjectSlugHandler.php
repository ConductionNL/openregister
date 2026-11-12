<?php

/**
 * Test helper: an ObjectSlugHandler built from its collaborators (#4037).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\Object\SaveObject\MetadataHydrationHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugHandler;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugLog;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugSource;
use OCA\OpenRegister\Service\Object\SaveObject\ObjectSlugStore;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Builds the real slug handler the way the DI container does.
 */
trait BuildsObjectSlugHandler {

	/**
	 * A real slug handler over the given mapper, hydrator, register mapper and logger.
	 *
	 * @param MagicMapper              $magicMapper    Slug queries.
	 * @param MetadataHydrationHandler $hydrator       Field extraction and slugify.
	 * @param RegisterMapper           $registerMapper Resolves an entity's register.
	 * @param LoggerInterface          $logger         Receives the [ObjectSlug] lines.
	 * @param ITimeFactory|null        $timeFactory    Clock for the suffix.
	 *
	 * @return ObjectSlugHandler The handler.
	 */
	private function buildSlugHandler(
		MagicMapper $magicMapper,
		MetadataHydrationHandler $hydrator,
		RegisterMapper $registerMapper,
		LoggerInterface $logger,
		?ITimeFactory $timeFactory=null,
	): ObjectSlugHandler {
		$log = new ObjectSlugLog(logger: $logger);
		return new ObjectSlugHandler(
			store: new ObjectSlugStore(magicMapper: $magicMapper, registerMapper: $registerMapper, log: $log),
			source: new ObjectSlugSource(hydrator: $hydrator, timeFactory: $timeFactory),
			log: $log
		);
	}//end buildSlugHandler()
}//end trait
