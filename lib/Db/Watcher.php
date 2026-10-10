<?php

/**
 * Watcher entity: one person's subscription to one object.
 *
 * A watcher is a follow. Since `merge-follow-and-favourites` it is also what a
 * favourite was: one row per (user, object), visible to the object's editors,
 * with a `notify` switch. With `notify` on, the `{"watchers": true}` recipient
 * block resolves to the row; with it off the row is a quiet follow, which is
 * exactly what a star used to be.
 *
 * The row lives outside the object, so subscribing writes no audit entry and
 * no version on the object it follows.
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
 * @spec openspec/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Watcher.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method bool|null getNotify()
 * @method void setNotify(?bool $notify)
 * @method DateTime getCreated()
 * @method void setCreated(DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class Watcher extends Entity implements JsonSerializable {

	/**
	 * The subscribing user's uid.
	 *
	 * @var string|null
	 */
	protected ?string $userId = null;

	/**
	 * The watched object's uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The watched object's register, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The watched object's schema, as the caller addressed it.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * Whether the follower hears about changes. Null (a row written before
	 * the column existed) reads as true.
	 *
	 * @var boolean|null
	 */
	protected ?bool $notify = true;

	/**
	 * When the subscription was taken.
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
		$this->addType(fieldName: 'notify', type: 'boolean');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this follow notifies, with a null from an older row read as on.
	 *
	 * @return boolean True when the follower hears about changes.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-a-user-can-watch-an-object-they-may-read
	 */
	public function notifies(): bool {
		return ($this->notify !== false);

	}//end notifies()

	/**
	 * JSON serialization.
	 *
	 * The watcher list is read by an object's editors, so it says WHO and
	 * WHEN and nothing else. The row's own id is included so a `manage`
	 * caller has a handle, but the payload carries no object body. `notify` is
	 * the follower's own preference: the watcher LIST removes it before it
	 * reaches an editor (ObjectWatchersController::index()).
	 *
	 * @return array<string, mixed> The row as the API returns it.
	 *
	 * @spec openspec/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'userId' => $this->userId,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'notify' => $this->notifies(),
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
