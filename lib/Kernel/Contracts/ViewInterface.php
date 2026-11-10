<?php
/**
 * ViewInterface: what callers outside Search read of a saved View row.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts;

use DateTime;
use JsonSerializable;

/**
 * A saved view: a stored query with its presentation, sharing and alert.
 *
 * Implemented by \OCA\OpenRegister\Search\Db\View. Events and listeners name
 * this read interface instead of the Search entity (ADR-012 Rule 2). The
 * column accessors are served by Nextcloud's Entity::__call, so they are
 * listed as tags here.
 *
 * @method int getId()
 * @method string|null getUuid()
 * @method string|null getName()
 * @method string|null getDescription()
 * @method string|null getOwner()
 * @method bool getIsPublic()
 * @method bool getIsDefault()
 * @method array|null getQuery()
 * @method array|null getPresentation()
 * @method array|null getFavoritedBy()
 * @method DateTime|null getCreated()
 * @method DateTime|null getUpdated()
 * @method array|null getAlert()
 * @method array|null getAlertState()
 * @method DateTime|null getAlertEvaluatedAt()
 */
interface ViewInterface extends JsonSerializable {

	/**
	 * The users who favoured this view.
	 *
	 * @return array Array of user IDs who favorited this view
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getFavoredBy(): array;

	/**
	 * The users and groups this view is shared with.
	 *
	 * @return array The share entries.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getSharedWith(): array;

	/**
	 * The access level of this view.
	 *
	 * @return string|null The access level, or null when unset.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getAccess(): ?string;

	/**
	 * The organisation this view belongs to.
	 *
	 * @return string|null The organisation UUID, or null when unset.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function getOrganisation(): ?string;
}//end interface
