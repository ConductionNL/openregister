<?php

/**
 * A record that one principal may act with another user's rights.
 *
 * The answer to the question `or-delegated-identity` deliberately left open. That
 * change made every run STATE whose rights it uses and made that identity
 * impossible to forge from a payload; it did not ask whether the person who named
 * the identity was entitled to name it. This entity is that entitlement.
 *
 * WHAT THIS IS NOT
 *
 * It is not a permission. Delegation is a property of the CALLER's identity, not
 * an action performed on an object, and ADR-010 keeps the permission vocabulary
 * closed to core's bitmask. A grant lets a principal act AS somebody; it never
 * raises what that somebody may do.
 *
 * It is also not a capability grant. `Agent.tools` answers "may this agent use
 * tool T" and is a different axis with its own grammar (ADR-095). The two are
 * deliberately never merged: a user approving a tool must not thereby widen whose
 * identity the work wears, and a dialog reading "allow sendMail?" must not be the
 * thing that hands over an identity.
 *
 * WHY SELF-DELEGATION IS ABSENT BY DESIGN
 *
 * Acting as yourself is not delegation. No grant is created for it and none is
 * consulted. A store that recorded every self-action could not answer "who can
 * act as the mayor?" without first filtering out the noise, which is the one
 * question it exists to answer.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One delegation: principal may act as actingAs, on a scope, until an expiry.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getPrincipal()
 * @method void setPrincipal(?string $principal)
 * @method string|null getActingAs()
 * @method void setActingAs(?string $actingAs)
 * @method array|null getScope()
 * @method void setScope(?array $scope)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getGrantedBy()
 * @method void setGrantedBy(?string $grantedBy)
 * @method string|null getReason()
 * @method void setReason(?string $reason)
 * @method string|null getOrganisation()
 * @method void setOrganisation(?string $organisation)
 * @method \DateTime|null getRequestedAt()
 * @method void setRequestedAt(?\DateTime $requestedAt)
 * @method \DateTime|null getAnsweredAt()
 * @method void setAnsweredAt(?\DateTime $answeredAt)
 * @method \DateTime|null getExpiresAt()
 * @method void setExpiresAt(?\DateTime $expiresAt)
 * @method \DateTime|null getRevokedAt()
 * @method void setRevokedAt(?\DateTime $revokedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @SuppressWarnings(PHPMD.TooManyFields) One property per column of
 * `openregister_delegation_grants`. An entity's field count IS its table's
 * column count.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationGrant extends Entity implements JsonSerializable {

	/**
	 * Asked for, but not yet delivered to the person who must answer.
	 *
	 * @var string
	 */
	public const STATUS_REQUESTED = 'requested';

	/**
	 * Delivered and awaiting an answer.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Answered yes. The only status that permits anything.
	 *
	 * @var string
	 */
	public const STATUS_GRANTED = 'granted';

	/**
	 * Answered no.
	 *
	 * 🔴 Distinct from EXPIRED on purpose. "They said no" and "nobody looked" are
	 * different facts about the same absent permission, and collapsing them loses
	 * the one that should stop the requester from asking again.
	 *
	 * @var string
	 */
	public const STATUS_DENIED = 'denied';

	/**
	 * Reached its expiry without being answered, or while granted.
	 *
	 * @var string
	 */
	public const STATUS_EXPIRED = 'expired';

	/**
	 * Withdrawn after having been granted.
	 *
	 * @var string
	 */
	public const STATUS_REVOKED = 'revoked';

	/**
	 * Every status a grant may hold.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		self::STATUS_REQUESTED,
		self::STATUS_PENDING,
		self::STATUS_GRANTED,
		self::STATUS_DENIED,
		self::STATUS_EXPIRED,
		self::STATUS_REVOKED,
	];

	/**
	 * Public identifier.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The uid permitted to act.
	 *
	 * @var string|null
	 */
	protected ?string $principal = null;

	/**
	 * The uid whose rights the principal may use.
	 *
	 * @var string|null
	 */
	protected ?string $actingAs = null;

	/**
	 * What the grant covers.
	 *
	 * Bounded by default. An unscoped grant is a standing, unbounded privilege,
	 * and the moment at which it should have ended is never revisited.
	 *
	 * @var array|null
	 */
	protected ?array $scope = null;

	/**
	 * One of {@see self::STATUSES}.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * Who answered, when the answer was yes.
	 *
	 * @var string|null
	 */
	protected ?string $grantedBy = null;

	/**
	 * Why it was asked for, in the requester's words.
	 *
	 * Recorded because "who did this, and who allowed them to" is only half
	 * answerable without it. A grant reading "needed for automation" answers
	 * nothing later, which is a UX problem this entity cannot solve alone.
	 *
	 * @var string|null
	 */
	protected ?string $reason = null;

	/**
	 * The tenant this grant belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * When it was asked for.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $requestedAt = null;

	/**
	 * When it was granted or denied.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $answeredAt = null;

	/**
	 * When it stops permitting anything.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When it was withdrawn.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $revokedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'principal', type: 'string');
		$this->addType(fieldName: 'actingAs', type: 'string');
		$this->addType(fieldName: 'scope', type: 'json');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'grantedBy', type: 'string');
		$this->addType(fieldName: 'reason', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'requestedAt', type: 'datetime');
		$this->addType(fieldName: 'answeredAt', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'revokedAt', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this grant permits anything AT THIS MOMENT.
	 *
	 * 🔴 The only method that should decide whether work may proceed, and it takes
	 * the time as an argument rather than reading the clock. A grant check that
	 * consults `new DateTime()` internally cannot be tested at a boundary, and the
	 * boundary is the whole point: the difference between a grant that expired one
	 * second ago and one that has not is exactly the case that must not be
	 * decided by whichever machine happens to ask.
	 *
	 * Status alone is not enough. A grant can be `granted` and expired, and a
	 * reader checking only the status would let it through — which is how a
	 * time-boxed delegation becomes a permanent one.
	 *
	 * @param DateTime $now The moment to evaluate against.
	 *
	 * @return boolean Whether it permits anything now.
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function isLiveAt(DateTime $now): bool {
		if ($this->status !== self::STATUS_GRANTED) {
			return false;
		}

		if ($this->revokedAt !== null) {
			return false;
		}

		if ($this->expiresAt !== null && $this->expiresAt <= $now) {
			return false;
		}

		return true;
	}//end isLiveAt()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The serialised grant.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'principal' => $this->principal,
			'actingAs' => $this->actingAs,
			'scope' => ($this->scope ?? []),
			'status' => $this->status,
			'grantedBy' => $this->grantedBy,
			'reason' => $this->reason,
			'organisation' => $this->organisation,
			'requestedAt' => $this->requestedAt?->format('c'),
			'answeredAt' => $this->answeredAt?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'revokedAt' => $this->revokedAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
