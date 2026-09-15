<?php

/**
 * AccessLink entity: a scoped, expiring link that grants a named right to
 * somebody without an account.
 *
 * An externe adviseur and a bezwaarmaker both need one record and neither has
 * a login. A link is how they get it. The link is not a user and does not
 * borrow a user's rights: it is a principal of its own, carrying exactly the
 * capabilities it declared when it was minted. That is also what makes the
 * audit trail readable afterwards, because the act was the link's and the
 * trail says so.
 *
 * A row carries a random anchor (the secret in the URL), the subject it opens,
 * the capabilities it declares, a required expiry, an optional password hash,
 * and a lifecycle of created / disabled / revoked. Everything the public
 * endpoint needs to fail closed lives here, so the decision is one row read.
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
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class AccessLink
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getAnchor()
 * @method void setAnchor(?string $anchor)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getSubjectId()
 * @method void setSubjectId(?string $subjectId)
 * @method string|null getCapabilities()
 * @method void setCapabilities(?string $capabilities)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string|null getPasswordHash()
 * @method void setPasswordHash(?string $passwordHash)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getExpiresAt()
 * @method void setExpiresAt(?DateTime $expiresAt)
 * @method DateTime|null getRevokedAt()
 * @method void setRevokedAt(?DateTime $revokedAt)
 * @method bool getDisabled()
 * @method void setDisabled(bool $disabled)
 * @method DateTime|null getLastUsedAt()
 * @method void setLastUsedAt(?DateTime $lastUsedAt)
 * @method int getUseCount()
 * @method void setUseCount(int $useCount)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
 */
class AccessLink extends Entity implements JsonSerializable {

	/**
	 * A link over one object.
	 *
	 * @var string
	 */
	public const SUBJECT_OBJECT = 'object';

	/**
	 * A link over one saved view.
	 *
	 * @var string
	 */
	public const SUBJECT_VIEW = 'view';

	/**
	 * A link over one file the object carries.
	 *
	 * @var string
	 */
	public const SUBJECT_FILE = 'file';

	/**
	 * The subject kinds a link may open.
	 *
	 * @var array<int, string>
	 */
	public const SUBJECT_TYPES = [self::SUBJECT_OBJECT, self::SUBJECT_VIEW, self::SUBJECT_FILE];

	/**
	 * Read the subject.
	 *
	 * @var string
	 */
	public const CAP_READ = 'read';

	/**
	 * Leave a comment on the subject.
	 *
	 * @var string
	 */
	public const CAP_COMMENT = 'comment';

	/**
	 * Add a file to the subject.
	 *
	 * @var string
	 */
	public const CAP_UPLOAD = 'upload';

	/**
	 * Every capability a link may declare.
	 *
	 * A capability this list does not name cannot be declared, and a capability
	 * a link did not declare is refused. Adding a member here therefore widens
	 * nothing that already exists: an old link keeps the set it was minted with.
	 *
	 * @var array<int, string>
	 */
	public const CAPABILITIES = [self::CAP_READ, self::CAP_COMMENT, self::CAP_UPLOAD];

	/**
	 * The prefix that marks a link principal in the audit trail.
	 *
	 * @var string
	 */
	public const PRINCIPAL_PREFIX = 'link:';

	/**
	 * The link's own identity, used as the audit actor.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The random anchor that addresses the link, and which is the whole secret.
	 *
	 * @var string|null
	 */
	protected ?string $anchor = null;

	/**
	 * What the link opens: `object`, `view` or `file`.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * Which one: an object uuid, a view uuid, or a file id.
	 *
	 * @var string|null
	 */
	protected ?string $subjectId = null;

	/**
	 * The declared capabilities, comma separated.
	 *
	 * @var string|null
	 */
	protected ?string $capabilities = null;

	/**
	 * Optional human label, so an owner can tell two links apart.
	 *
	 * @var string|null
	 */
	protected ?string $label = null;

	/**
	 * The hashed password, when the link carries one.
	 *
	 * @var string|null
	 */
	protected ?string $passwordHash = null;

	/**
	 * The principal that minted the link.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * When the link was minted.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the link stops answering. Never null on a stored row.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $expiresAt = null;

	/**
	 * When the link was revoked, when it was.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $revokedAt = null;

	/**
	 * Whether the owner switched the link off without revoking it.
	 *
	 * @var bool
	 */
	protected bool $disabled = false;

	/**
	 * When the link was last used.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $lastUsedAt = null;

	/**
	 * How often the link was used.
	 *
	 * @var int
	 */
	protected int $useCount = 0;

	/**
	 * Constructor: declare the field types Nextcloud's Entity hydrates.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'anchor', type: 'string');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'subjectId', type: 'string');
		$this->addType(fieldName: 'capabilities', type: 'string');
		$this->addType(fieldName: 'label', type: 'string');
		$this->addType(fieldName: 'passwordHash', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'expiresAt', type: 'datetime');
		$this->addType(fieldName: 'revokedAt', type: 'datetime');
		$this->addType(fieldName: 'disabled', type: 'boolean');
		$this->addType(fieldName: 'lastUsedAt', type: 'datetime');
		$this->addType(fieldName: 'useCount', type: 'integer');
	}//end __construct()

	/**
	 * Whether this link still answers at the given moment.
	 *
	 * A revoked, switched-off or expired link is dead, and the caller turns all
	 * three into the same 404. A row with no expiry is dead too: the expiry is
	 * required at mint, so a row without one is a row that should not exist,
	 * and reading it as live would make the requirement optional in practice.
	 *
	 * @param DateTime|null $now The moment to judge against; defaults to now.
	 *
	 * @return bool True when the link may answer.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function isLive(?DateTime $now = null): bool {
		$moment = ($now ?? new DateTime());

		if ($this->revokedAt !== null) {
			return false;
		}

		if ($this->disabled === true) {
			return false;
		}

		if ($this->expiresAt === null || $this->expiresAt <= $moment) {
			return false;
		}

		return true;
	}//end isLive()

	/**
	 * The capabilities this link declares, as a list.
	 *
	 * @return array<int, string> The declared capabilities.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function declaredCapabilities(): array {
		$raw = trim((string)$this->capabilities);
		if ($raw === '') {
			return [];
		}

		$declared = [];
		foreach (explode(',', $raw) as $candidate) {
			$name = strtolower(trim($candidate));
			if ($name !== '' && in_array($name, self::CAPABILITIES, true) === true
				&& in_array($name, $declared, true) === false
			) {
				$declared[] = $name;
			}
		}

		return $declared;
	}//end declaredCapabilities()

	/**
	 * Whether this link declares one capability.
	 *
	 * Undeclared is refused, which is why this asks the stored set rather than
	 * inferring anything: a capability introduced after a link was minted is
	 * absent from that link's set and therefore refused, without anybody having
	 * to remember to re-check old rows.
	 *
	 * @param string $capability The capability to test.
	 *
	 * @return bool True when the link declares it.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function allows(string $capability): bool {
		return in_array(strtolower(trim($capability)), $this->declaredCapabilities(), true);
	}//end allows()

	/**
	 * Whether the link is closed with a password.
	 *
	 * @return bool True when a password must be presented.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-link-declares-its-capabilities-carries-an-expiry-and-may-carry-a-password-req-abl-002
	 */
	public function hasPassword(): bool {
		return (trim((string)$this->passwordHash) !== '');
	}//end hasPassword()

	/**
	 * The identity this link writes into the audit trail.
	 *
	 * Never a user id. A reader of the trail must be able to tell an act by a
	 * person from an act by a link that a person handed out.
	 *
	 * @return string The audit actor, as `link:<uuid>`.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function principalId(): string {
		return self::PRINCIPAL_PREFIX . (string)$this->uuid;
	}//end principalId()

	/**
	 * The name shown beside the link's acts.
	 *
	 * @return string The label, or the principal id when there is none.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md#requirement-a-revoked-or-expired-link-answers-404-and-every-use-is-recorded-req-abl-003
	 */
	public function principalName(): string {
		$label = trim((string)$this->label);
		if ($label === '') {
			return $this->principalId();
		}

		return $label;
	}//end principalName()

	/**
	 * Serialise the link for its owner.
	 *
	 * The anchor is included because this row is only ever returned to the
	 * principal that minted it, and that principal needs the value to hand out.
	 * The password hash never is: an owner who forgot the password sets a new
	 * one rather than reading the old.
	 *
	 * @return array<string, mixed> The serialised link.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'anchor' => $this->anchor,
			'subjectType' => $this->subjectType,
			'subjectId' => $this->subjectId,
			'capabilities' => $this->declaredCapabilities(),
			'label' => $this->label,
			'hasPassword' => $this->hasPassword(),
			'createdBy' => $this->createdBy,
			'created' => $this->created?->format('c'),
			'expiresAt' => $this->expiresAt?->format('c'),
			'revokedAt' => $this->revokedAt?->format('c'),
			'disabled' => $this->disabled,
			'lastUsedAt' => $this->lastUsedAt?->format('c'),
			'useCount' => $this->useCount,
			'live' => $this->isLive(),
		];
	}//end jsonSerialize()

	/**
	 * What the holder of the link is told about it.
	 *
	 * Deliberately a different shape from {@see jsonSerialize()}. The holder
	 * needs to know what they may do and until when. They do not need to know
	 * who minted the link, how often it has been used, or from where.
	 *
	 * @return array<string, mixed> The holder-facing descriptor.
	 *
	 * @spec openspec/changes/access-by-link-not-by-account/specs/public-access-links/spec.md
	 */
	public function publicDescriptor(): array {
		return [
			'subjectType' => $this->subjectType,
			'capabilities' => $this->declaredCapabilities(),
			'label' => $this->label,
			'expiresAt' => $this->expiresAt?->format('c'),
		];
	}//end publicDescriptor()
}//end class
