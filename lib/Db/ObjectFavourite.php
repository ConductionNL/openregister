<?php

/**
 * ObjectFavourite entity: one person's star on one object.
 *
 * A star is a fact about a user, not about the object. It lives here rather
 * than in the object's body so that starring writes no audit entry and cuts no
 * version, which is the whole point: a hundred people starring a case would
 * otherwise give that case a hundred revisions saying nothing about the case.
 *
 * Sibling of `ObjectWatcher` and `ObjectReadState`, and deliberately the
 * simplest of the three: a star has no state beyond existing. Unstarring
 * deletes the row rather than flipping a flag, so "starred" is the presence of
 * a row and there is no second way to express it.
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
 * ObjectFavourite.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method DateTime|null getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ObjectFavourite extends Entity implements JsonSerializable {

	/**
	 * The starring user's uid.
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
	 * Carried on the row so a narrowed favourites lens and a purge by register
	 * both stay one statement, without joining the per-schema object table.
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
	 * When the star was placed.
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
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * The row is only ever returned to the user it belongs to, so it says what
	 * that user starred and when, and carries no object body.
	 *
	 * @return array<string, mixed> The row as the API returns it.
	 *
	 * @spec openspec/changes/favourites-and-recent/specs/object-interactions/spec.md#requirement-a-user-can-star-an-object-without-changing-it
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
