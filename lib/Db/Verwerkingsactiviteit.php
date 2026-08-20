<?php

/**
 * AVG Verwerkingsactiviteit entity (GDPR Art 30 processing activity).
 *
 * One row in `oc_openregister_verwerkingsactiviteiten` describes a
 * single processing activity: legal basis, purpose, data subject and
 * data categories, retention rule, recipients, third-country transfer
 * details, technical/organisational measures, controller and DPO
 * contact. Audit-trail rows reference this entity by `uuid` via the
 * pre-existing `processing_activity_id` column on
 * `oc_openregister_audit_trails`.
 *
 * Field names were renamed from Dutch to English by the
 * `verwerkingsregister-i18n` change; the class name and table name
 * are unchanged (only the entity's fields moved).
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity class describing a single AVG / GDPR Art 30 processing activity.
 *
 * Fields mirror the AVG Art 30 §1 catalogue requirements; audit-trail
 * rows reference the entity's `uuid` via
 * `oc_openregister_audit_trails.processing_activity_id`.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getCode()
 * @method void setCode(?string $code)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getPurpose()
 * @method void setPurpose(?string $purpose)
 * @method string|null getLegalBasis()
 * @method void setLegalBasis(?string $legalBasis)
 * @method array|null getDataSubjectCategories()
 * @method void setDataSubjectCategories(?array $dataSubjectCategories)
 * @method array|null getPersonalDataCategories()
 * @method void setPersonalDataCategories(?array $personalDataCategories)
 * @method string|null getRetentionPeriod()
 * @method void setRetentionPeriod(?string $retentionPeriod)
 * @method array|null getRecipients()
 * @method void setRecipients(?array $recipients)
 * @method array|null getInternationalTransfers()
 * @method void setInternationalTransfers(?array $internationalTransfers)
 * @method string|null getTechnicalMeasures()
 * @method void setTechnicalMeasures(?string $technicalMeasures)
 * @method string|null getOrganisationalMeasures()
 * @method void setOrganisationalMeasures(?string $organisationalMeasures)
 * @method array|null getController()
 * @method void setController(?array $controller)
 * @method array|null getDpoContactDetails()
 * @method void setDpoContactDetails(?array $dpoContactDetails)
 * @method string|null getOrganisationId()
 * @method void setOrganisationId(?string $organisationId)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 * @SuppressWarnings(PHPMD.LongVariable)
 */
class Verwerkingsactiviteit extends Entity implements JsonSerializable {

	/**
	 * Article 6 GDPR legal-basis vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const LEGAL_BASIS_VOCABULARY = [
		'consent',
		'contract',
		'legal_obligation',
		'vital_interests',
		'public_task',
		'legitimate_interest',
	];

	/**
	 * Lifecycle status vocabulary.
	 *
	 * @var array<int, string>
	 */
	public const STATUS_VOCABULARY = ['concept', 'published', 'archived'];

	/**
	 * Natural key used as the soft FK target on audit rows.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * Optional short readable identifier (e.g. `v-2026-001`).
	 *
	 * @var string|null
	 */
	protected ?string $code = null;

	/**
	 * Human-readable name (Art 30 §1(a)).
	 *
	 * @var string|null
	 */
	protected ?string $name = null;

	/**
	 * Free-form description of the processing activity.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * Purpose-limitation statement (Art 5(1)(b)).
	 *
	 * @var string|null
	 */
	protected ?string $purpose = null;

	/**
	 * Article 6 GDPR legal basis identifier (vocabulary above).
	 *
	 * @var string|null
	 */
	protected ?string $legalBasis = null;

	/**
	 * Categories of data subjects (Art 30 §1(c)).
	 *
	 * @var array<int, mixed>|null
	 */
	protected ?array $dataSubjectCategories = null;

	/**
	 * Categories of personal data (Art 30 §1(c)).
	 *
	 * @var array<int, mixed>|null
	 */
	protected ?array $personalDataCategories = null;

	/**
	 * Retention rule expressed as an ISO-8601 duration (e.g. `P10Y`).
	 *
	 * @var string|null
	 */
	protected ?string $retentionPeriod = null;

	/**
	 * Recipients (Art 30 §1(d)).
	 *
	 * @var array<int, mixed>|null
	 */
	protected ?array $recipients = null;

	/**
	 * Third-country transfer details (Art 30 §1(e), Art 44).
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $internationalTransfers = null;

	/**
	 * Technical security measures (Art 30 §1(g), Art 32).
	 *
	 * @var string|null
	 */
	protected ?string $technicalMeasures = null;

	/**
	 * Organisational security measures (Art 30 §1(g), Art 32).
	 *
	 * @var string|null
	 */
	protected ?string $organisationalMeasures = null;

	/**
	 * Controller details (Art 30 §1(a)).
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $controller = null;

	/**
	 * Data Protection Officer contact details.
	 *
	 * @var array<string, mixed>|null
	 */
	protected ?array $dpoContactDetails = null;

	/**
	 * Tenant identifier for multi-tenant isolation.
	 *
	 * @var string|null
	 */
	protected ?string $organisationId = null;

	/**
	 * Lifecycle status (concept | published | archived).
	 *
	 * @var string|null
	 */
	protected ?string $status = 'concept';

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Last-update timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Register the entity's typed columns.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'code', type: 'string');
		$this->addType(fieldName: 'name', type: 'string');
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'purpose', type: 'string');
		$this->addType(fieldName: 'legalBasis', type: 'string');
		$this->addType(fieldName: 'dataSubjectCategories', type: 'json');
		$this->addType(fieldName: 'personalDataCategories', type: 'json');
		$this->addType(fieldName: 'retentionPeriod', type: 'string');
		$this->addType(fieldName: 'recipients', type: 'json');
		$this->addType(fieldName: 'internationalTransfers', type: 'json');
		$this->addType(fieldName: 'technicalMeasures', type: 'string');
		$this->addType(fieldName: 'organisationalMeasures', type: 'string');
		$this->addType(fieldName: 'controller', type: 'json');
		$this->addType(fieldName: 'dpoContactDetails', type: 'json');
		$this->addType(fieldName: 'organisationId', type: 'string');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Whether the supplied legal-basis string is in the Art 6 vocabulary.
	 *
	 * @param string|null $legalBasis Candidate legal-basis string.
	 *
	 * @return bool
	 */
	public static function isValidLegalBasis(?string $legalBasis): bool {
		if ($legalBasis === null || $legalBasis === '') {
			return false;
		}

		return in_array(needle: $legalBasis, haystack: self::LEGAL_BASIS_VOCABULARY, strict: true);
	}//end isValidLegalBasis()

	/**
	 * Whether the supplied status string is in the lifecycle vocabulary.
	 *
	 * @param string|null $status Candidate status string.
	 *
	 * @return bool
	 */
	public static function isValidStatus(?string $status): bool {
		if ($status === null || $status === '') {
			return false;
		}

		return in_array(needle: $status, haystack: self::STATUS_VOCABULARY, strict: true);
	}//end isValidStatus()

	/**
	 * Render the entity as the canonical AVG Art 30 JSON shape.
	 *
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'code' => $this->code,
			'name' => $this->name,
			'description' => $this->description,
			'purpose' => $this->purpose,
			'legalBasis' => $this->legalBasis,
			'dataSubjectCategories' => $this->dataSubjectCategories,
			'personalDataCategories' => $this->personalDataCategories,
			'retentionPeriod' => $this->retentionPeriod,
			'recipients' => $this->recipients,
			'internationalTransfers' => $this->internationalTransfers,
			'technicalMeasures' => $this->technicalMeasures,
			'organisationalMeasures' => $this->organisationalMeasures,
			'controller' => $this->controller,
			'dpoContactDetails' => $this->dpoContactDetails,
			'organisationId' => $this->organisationId,
			'status' => $this->status,
			'created' => $this->created?->format('c'),
			'updated' => $this->updated?->format('c'),
		];

	}//end jsonSerialize()
}//end class
