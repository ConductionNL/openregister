<?php

/**
 * ContactLink entity for linking CardDAV contacts to OpenRegister objects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ContactLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string getContactUid()
 * @method void setContactUid(string $contactUid)
 * @method int getAddressbookId()
 * @method void setAddressbookId(int $addressbookId)
 * @method string getContactUri()
 * @method void setContactUri(string $contactUri)
 * @method string|null getDisplayName()
 * @method void setDisplayName(?string $displayName)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string|null getPhone()
 * @method void setPhone(?string $phone)
 * @method string|null getOrg()
 * @method void setOrg(?string $org)
 * @method string|null getAvatarUrl()
 * @method void setAvatarUrl(?string $avatarUrl)
 * @method string|null getRole()
 * @method void setRole(?string $role)
 * @method string|null getMetadata()
 * @method void setMetadata(?string $metadata)
 * @method string getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ContactLink extends Entity implements JsonSerializable {

	/**
	 * The object uuid.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register id.
	 *
	 * @var integer|null
	 */
	protected ?int $registerId = null;

	/**
	 * The schema id. Tier-2 addition — lets the consumer-side picker /
	 * Tab figure out which register/schema scope it's in without an
	 * extra round-trip.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The contact uid.
	 *
	 * @var string|null
	 */
	protected ?string $contactUid = null;

	/**
	 * The addressbook id.
	 *
	 * @var integer|null
	 */
	protected ?int $addressbookId = null;

	/**
	 * The contact uri.
	 *
	 * @var string|null
	 */
	protected ?string $contactUri = null;

	/**
	 * The display name.
	 *
	 * @var string|null
	 */
	protected ?string $displayName = null;

	/**
	 * The email.
	 *
	 * @var string|null
	 */
	protected ?string $email = null;

	/**
	 * The cached primary phone number. Tier-2 — populated at link time
	 * + refreshed by `ContactService::getContactsForObject()` when the
	 * link is older than the 24h enrichment TTL.
	 *
	 * @var string|null
	 */
	protected ?string $phone = null;

	/**
	 * The cached primary organisation. Tier-2.
	 *
	 * @var string|null
	 */
	protected ?string $org = null;

	/**
	 * The cached avatar URL (PHOTO from the vCard, or the per-uid
	 * Contacts route as a fallback). Tier-2.
	 *
	 * @var string|null
	 */
	protected ?string $avatarUrl = null;

	/**
	 * The role.
	 *
	 * @var string|null
	 */
	protected ?string $role = null;

	/**
	 * Free-form JSON-encoded extension bag for provider-specific
	 * payloads (per ADR-019 §AD-6). Tier-2.
	 *
	 * @var string|null
	 */
	protected ?string $metadata = null;

	/**
	 * The linked by.
	 *
	 * @var string|null
	 */
	protected ?string $linkedBy = null;

	/**
	 * The linked at.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $linkedAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'registerId', type: 'integer');
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'contactUid', type: 'string');
		$this->addType(fieldName: 'addressbookId', type: 'integer');
		$this->addType(fieldName: 'contactUri', type: 'string');
		$this->addType(fieldName: 'displayName', type: 'string');
		$this->addType(fieldName: 'email', type: 'string');
		$this->addType(fieldName: 'phone', type: 'string');
		$this->addType(fieldName: 'org', type: 'string');
		$this->addType(fieldName: 'avatarUrl', type: 'string');
		$this->addType(fieldName: 'role', type: 'string');
		$this->addType(fieldName: 'metadata', type: 'string');
		$this->addType(fieldName: 'linkedBy', type: 'string');
		$this->addType(fieldName: 'linkedAt', type: 'datetime');
	}//end __construct()

	/**
	 * JSON serialization.
	 *
	 * The Tier-2 widened payload (phone / org / avatarUrl) is emitted
	 * here directly; the `ContactService` keeps these fields fresh by
	 * re-enriching from the vCard when the cached row is older than
	 * 24 hours.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		// `metadata` is stored as JSON-encoded text — decode it for the
		// consumer so the registry / Tab can iterate keys naturally.
		$metadata = null;
		if ($this->metadata !== null && $this->metadata !== '') {
			try {
				$decoded = json_decode($this->metadata, true, 512, JSON_THROW_ON_ERROR);
				if (is_array($decoded) === true) {
					$metadata = $decoded;
				}
			} catch (\JsonException $e) {
				// Corrupt row — surface as null rather than blowing up.
				$metadata = null;
			}
		}

		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'registerId' => $this->registerId,
			'schemaId' => $this->schemaId,
			'contactUid' => $this->contactUid,
			'addressbookId' => $this->addressbookId,
			'contactUri' => $this->contactUri,
			'displayName' => $this->displayName,
			'email' => $this->email,
			'phone' => $this->phone,
			'org' => $this->org,
			'avatarUrl' => $this->avatarUrl,
			'role' => $this->role,
			'metadata' => $metadata,
			'linkedBy' => $this->linkedBy,
			'linkedAt' => $this->linkedAt?->format(DateTime::ATOM),
		];
	}//end jsonSerialize()
}//end class
