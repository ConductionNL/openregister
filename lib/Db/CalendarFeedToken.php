<?php

/**
 * CalendarFeedToken entity: a revocable, principal-bound subscription to an
 * OpenRegister calendar feed.
 *
 * A calendar client sends no session, so the feed is addressed by an opaque
 * token that names the principal. The token only addresses the feed: the
 * objects it answers are resolved as that principal, through the same access
 * path the object list uses, deny included. A token row carries its scope
 * (one calendar-enabled schema, or one saved view) plus a lifecycle
 * (created / expires / revoked) so the public feed endpoint fails closed on
 * an unknown, expired or revoked token rather than answering a reduced
 * calendar.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class CalendarFeedToken
 *
 * @method string|null getToken()
 * @method void setToken(?string $token)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
 * @method string|null getScopeType()
 * @method void setScopeType(?string $scopeType)
 * @method string|null getScopeId()
 * @method void setScopeId(?string $scopeId)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 * @method DateTime|null getLastReadAt()
 * @method void setLastReadAt(?DateTime $lastReadAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/specs/calendar-provider/spec.md#requirement-schema-calendar-configuration
 */
class CalendarFeedToken extends Entity implements JsonSerializable {

	/**
	 * Scope type for a feed over one calendar-enabled schema.
	 *
	 * @var string
	 */
	public const SCOPE_SCHEMA = 'schema';

	/**
	 * Scope type for a feed over one saved view.
	 *
	 * @var string
	 */
	public const SCOPE_VIEW = 'view';

	/**
	 * The scope types a feed token may carry.
	 *
	 * @var array<int, string>
	 */
	public const SCOPE_TYPES = [self::SCOPE_SCHEMA, self::SCOPE_VIEW];

	/**
	 * The opaque feed token (URL-safe, high entropy).
	 *
	 * @var string|null
	 */
	protected ?string $token = null;

	/**
	 * The principal this feed answers for.
	 *
	 * @var string|null
	 */
	protected ?string $userId = null;

	/**
	 * The scope type: `schema` or `view`.
	 *
	 * @var string|null
	 */
	protected ?string $scopeType = null;

	/**
	 * The scope identifier: a schema id, or a view uuid.
	 *
	 * @var string|null
	 */
	protected ?string $scopeId = null;

	/**
	 * Optional human label, so a holder can tell two feeds apart.
	 *
	 * @var string|null
	 */
	protected ?string $label = null;

	/**
	 * When the token was minted.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * When the token stops answering, when it ever does.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When the token was revoked, when it was.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $revokedAt = null;

	/**
	 * When a client last read the feed, so a holder can see a dead subscription.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastReadAt = null;

	/**
	 * Constructor: declare the field types Nextcloud's Entity hydrates.
	 */
	public function __construct() {
		$this->addType(fieldName: 'token', type: 'string');
		$this->addType(fieldName: 'userId', type: 'string');
		$this->addType(fieldName: 'scopeType', type: 'string');
		$this->addType(fieldName: 'scopeId', type: 'string');
		$this->addType(fieldName: 'label', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'revokedAt', type: 'datetime');
		$this->addType(fieldName: 'lastReadAt', type: 'datetime');
	}//end __construct()

	/**
	 * Whether this token still answers at the given moment.
	 *
	 * A revoked or expired token is dead. The caller turns that into a 404,
	 * never into a smaller calendar: a partial calendar looks like an empty
	 * day, and an empty day is believed.
	 *
	 * @param DateTime|null $now The moment to judge against; defaults to now.
	 *
	 * @return bool True when the token may answer.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function isLive(?DateTime $now = null): bool {
		$moment = ($now ?? new DateTime());

		if ($this->revokedAt !== null) {
			return false;
		}

		if ($this->expiresAt !== null && $this->expiresAt <= $moment) {
			return false;
		}

		return true;
	}//end isLive()

	/**
	 * Serialise the token row for an API response.
	 *
	 * The opaque token itself is included: this row is only ever returned to
	 * the principal that owns it, and the holder needs the value to subscribe.
	 *
	 * @return array<string, mixed> The serialised token.
	 *
	 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'token' => $this->token,
			'userId' => $this->userId,
			'scopeType' => $this->scopeType,
			'scopeId' => $this->scopeId,
			'label' => $this->label,
			'createdAt' => $this->createdAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'revokedAt' => $this->revokedAt?->format('c'),
			'lastReadAt' => $this->lastReadAt?->format('c'),
			'live' => $this->isLive(),
		];
	}//end jsonSerialize()
}//end class
