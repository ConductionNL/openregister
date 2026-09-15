<?php

/**
 * ObjectView entity: the last time one person opened one object.
 *
 * A view is a fact about a user, not about the object, so it lives beside the
 * object rather than in it: opening a case must not cut a version on that case.
 *
 * One row per (user, object), refreshed rather than appended. That is what
 * makes "recent" a list of OBJECTS: a hundred rows are a hundred distinct
 * things this person looked at, not one thing they opened a hundred times.
 * The refresh is throttled to once a minute in `ViewHistoryService`, which is
 * why `viewedAt` reads as the first open of a burst rather than the last.
 *
 * `ObjectReadState` is the sibling that answers a different question. A view
 * records that you looked; a read state records that nothing has changed since
 * you did. A write destroys the read state and leaves the view alone.
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
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * ObjectView.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method DateTime|null getViewedAt()
 * @method void setViewedAt(DateTime $viewedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ObjectView extends Entity implements JsonSerializable {

	/**
	 * The viewing user's uid.
	 *
	 * @var string|null
	 */
	protected ?string $userId = null;

	/**
	 * The object's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The object's register, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The object's schema, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * When the user last opened the object, subject to the throttle.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $viewedAt = null;

	/**
	 * When the user first opened the object.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'viewedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * @return array<string, mixed> The row as the API returns it.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-opening-an-object-records-a-per-user-view
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'viewedAt' => $this->viewedAt?->format(DateTime::ATOM),
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
