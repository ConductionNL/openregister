<?php

/**
 * MagicMapperInterface: the public surface of MagicMapper, for callers to type against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Db\MagicMapper\MagicAccessPredicateInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicColumnDriftInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectDeletionInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectLockInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectSearchInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicObjectWriterInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicSlugStoreInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicStatisticsInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableLocatorInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableMaintenanceInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicTableRowReaderInterface;

/**
 * Every public method of MagicMapper, as the union of its role interfaces.
 *
 * A caller that needs one role types against that role interface; a caller that
 * needs several types against this one.
 */
interface MagicMapperInterface extends
	MagicTableLocatorInterface,
	MagicTableMaintenanceInterface,
	MagicColumnDriftInterface,
	MagicTableRowReaderInterface,
	MagicCrossTableReaderInterface,
	MagicRelationReaderInterface,
	MagicSlugStoreInterface,
	MagicObjectFinderInterface,
	MagicObjectWriterInterface,
	MagicObjectDeletionInterface,
	MagicObjectLockInterface,
	MagicAccessPredicateInterface,
	MagicObjectSearchInterface,
	MagicStatisticsInterface {
	/**
	 * Table name prefix for register+schema-specific tables.
	 *
	 * NOTE: Does NOT include 'oc_' prefix as Nextcloud's QueryBuilder adds that automatically.
	 *
	 * @internal Used by MagicTableHandler
	 */
	public const TABLE_PREFIX = 'openregister_table_';
}//end interface
