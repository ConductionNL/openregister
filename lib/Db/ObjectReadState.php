<?php

/**
 * ObjectReadState entity: what one person has already seen of one object.
 *
 * A read state is not a view history. `favourites-and-recent` records that you
 * looked at something; this row records that nothing has changed since you
 * did. The difference is what happens on a write: a view history entry survives
 * it, this row does not.
 *
 * That is the whole derivation. Unread is the ABSENCE of a row, so the filter
 * is one `NOT EXISTS` against this table rather than a timestamp comparison
 * against a column the object table does not have. A substantive change deletes
 * every row for the object except the actor's, and marking something back to
 * unread deletes your own. `lastSeenAt` and `subSeen` are therefore display and
 * sub-resource facts, never the unread decision itself.
 *
 * The row lives outside the object, so reading an object writes no audit entry
 * and cuts no version on it.
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
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * ObjectReadState.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method DateTime|null getLastSeenAt()
 * @method void setLastSeenAt(DateTime $lastSeenAt)
 * @method array|null getSubSeen()
 * @method void setSubSeen(?array $subSeen)
 * @method DateTime|null getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ObjectReadState extends Entity implements JsonSerializable {

	/**
	 * The reading user's uid.
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
	 * Carried on the row so a narrowed unread lens and a purge by register both
	 * stay one statement, without joining the per-schema object table.
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
	 * When the user last saw the object itself.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastSeenAt = null;

	/**
	 * When the user last saw each sub-resource, keyed by its declared name.
	 *
	 * One map on one row, because the tab badges are read together: a page
	 * renders every tab at once, so a row per tab would be a join per tab.
	 *
	 * @var array<string, string>|null
	 */
	protected ?array $subSeen = null;

	/**
	 * When the row was first written.
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
		$this->addType(fieldName: 'lastSeenAt', type: 'datetime');
		$this->addType(fieldName: 'subSeen', type: 'json');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * The row is only ever returned to the user it belongs to, so it says what
	 * that user saw and when, and carries no object body.
	 *
	 * @return array<string, mixed> The row as the API returns it.
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'lastSeenAt' => $this->lastSeenAt?->format(DateTime::ATOM),
			'subSeen' => ($this->subSeen ?? []),
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
