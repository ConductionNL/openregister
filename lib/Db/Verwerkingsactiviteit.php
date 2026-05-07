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
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author  Conduction Development Team <dev@conduction.nl>
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
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
 * @method string|null getNaam()
 * @method void setNaam(?string $naam)
 * @method string|null getBeschrijving()
 * @method void setBeschrijving(?string $beschrijving)
 * @method string|null getDoelbinding()
 * @method void setDoelbinding(?string $doelbinding)
 * @method string|null getRechtsgrond()
 * @method void setRechtsgrond(?string $rechtsgrond)
 * @method array|null getCategorieenBetrokkenen()
 * @method void setCategorieenBetrokkenen(?array $categorieenBetrokkenen)
 * @method array|null getCategorieenPersoonsgegevens()
 * @method void setCategorieenPersoonsgegevens(?array $categorieenPersoonsgegevens)
 * @method string|null getBewaartermijn()
 * @method void setBewaartermijn(?string $bewaartermijn)
 * @method array|null getOntvangers()
 * @method void setOntvangers(?array $ontvangers)
 * @method array|null getDoorgifteBuitenEu()
 * @method void setDoorgifteBuitenEu(?array $doorgifteBuitenEu)
 * @method string|null getTechnischeMaatregelen()
 * @method void setTechnischeMaatregelen(?string $technischeMaatregelen)
 * @method string|null getOrganisatorischeMaatregelen()
 * @method void setOrganisatorischeMaatregelen(?string $organisatorischeMaatregelen)
 * @method array|null getVerwerkingsverantwoordelijke()
 * @method void setVerwerkingsverantwoordelijke(?array $verwerkingsverantwoordelijke)
 * @method array|null getContactgegevensFg()
 * @method void setContactgegevensFg(?array $contactgegevensFg)
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
class Verwerkingsactiviteit extends Entity implements JsonSerializable
{

    /**
     * Article 6 GDPR legal-basis vocabulary.
     *
     * @var array<int, string>
     */
    public const RECHTSGROND_VOCABULARY = [
        'toestemming',
        'overeenkomst',
        'wettelijke_verplichting',
        'vitaal_belang',
        'publieke_taak',
        'gerechtvaardigd_belang',
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
    protected ?string $naam = null;

    /**
     * Free-form description of the processing activity.
     *
     * @var string|null
     */
    protected ?string $beschrijving = null;

    /**
     * Purpose-limitation statement (Art 5(1)(b)).
     *
     * @var string|null
     */
    protected ?string $doelbinding = null;

    /**
     * Article 6 GDPR legal basis identifier (vocabulary above).
     *
     * @var string|null
     */
    protected ?string $rechtsgrond = null;

    /**
     * Categories of data subjects (Art 30 §1(c)).
     *
     * @var array<int, mixed>|null
     */
    protected ?array $categorieenBetrokkenen = null;

    /**
     * Categories of personal data (Art 30 §1(c)).
     *
     * @var array<int, mixed>|null
     */
    protected ?array $categorieenPersoonsgegevens = null;

    /**
     * Retention rule expressed as an ISO-8601 duration (e.g. `P10Y`).
     *
     * @var string|null
     */
    protected ?string $bewaartermijn = null;

    /**
     * Recipients (Art 30 §1(d)).
     *
     * @var array<int, mixed>|null
     */
    protected ?array $ontvangers = null;

    /**
     * Third-country transfer details (Art 30 §1(e), Art 44).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $doorgifteBuitenEu = null;

    /**
     * Technical security measures (Art 30 §1(g), Art 32).
     *
     * @var string|null
     */
    protected ?string $technischeMaatregelen = null;

    /**
     * Organisational security measures (Art 30 §1(g), Art 32).
     *
     * @var string|null
     */
    protected ?string $organisatorischeMaatregelen = null;

    /**
     * Controller details (Art 30 §1(a)).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $verwerkingsverantwoordelijke = null;

    /**
     * Data Protection Officer contact details.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $contactgegevensFg = null;

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
    public function __construct()
    {
        $this->addType(fieldName: 'uuid', type: 'string');
        $this->addType(fieldName: 'code', type: 'string');
        $this->addType(fieldName: 'naam', type: 'string');
        $this->addType(fieldName: 'beschrijving', type: 'string');
        $this->addType(fieldName: 'doelbinding', type: 'string');
        $this->addType(fieldName: 'rechtsgrond', type: 'string');
        $this->addType(fieldName: 'categorieenBetrokkenen', type: 'json');
        $this->addType(fieldName: 'categorieenPersoonsgegevens', type: 'json');
        $this->addType(fieldName: 'bewaartermijn', type: 'string');
        $this->addType(fieldName: 'ontvangers', type: 'json');
        $this->addType(fieldName: 'doorgifteBuitenEu', type: 'json');
        $this->addType(fieldName: 'technischeMaatregelen', type: 'string');
        $this->addType(fieldName: 'organisatorischeMaatregelen', type: 'string');
        $this->addType(fieldName: 'verwerkingsverantwoordelijke', type: 'json');
        $this->addType(fieldName: 'contactgegevensFg', type: 'json');
        $this->addType(fieldName: 'organisationId', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'created', type: 'datetime');
        $this->addType(fieldName: 'updated', type: 'datetime');

    }//end __construct()

    /**
     * Whether the supplied legal-basis string is in the Art 6 vocabulary.
     *
     * @param string|null $rechtsgrond Candidate legal-basis string.
     *
     * @return bool
     */
    public static function isValidRechtsgrond(?string $rechtsgrond): bool
    {
        if ($rechtsgrond === null || $rechtsgrond === '') {
            return false;
        }

        return in_array(needle: $rechtsgrond, haystack: self::RECHTSGROND_VOCABULARY, strict: true);

    }//end isValidRechtsgrond()

    /**
     * Whether the supplied status string is in the lifecycle vocabulary.
     *
     * @param string|null $status Candidate status string.
     *
     * @return bool
     */
    public static function isValidStatus(?string $status): bool
    {
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
    public function jsonSerialize(): array
    {
        return [
            'id'                           => $this->id,
            'uuid'                         => $this->uuid,
            'code'                         => $this->code,
            'naam'                         => $this->naam,
            'beschrijving'                 => $this->beschrijving,
            'doelbinding'                  => $this->doelbinding,
            'rechtsgrond'                  => $this->rechtsgrond,
            'categorieenBetrokkenen'       => $this->categorieenBetrokkenen,
            'categorieenPersoonsgegevens'  => $this->categorieenPersoonsgegevens,
            'bewaartermijn'                => $this->bewaartermijn,
            'ontvangers'                   => $this->ontvangers,
            'doorgifteBuitenEu'            => $this->doorgifteBuitenEu,
            'technischeMaatregelen'        => $this->technischeMaatregelen,
            'organisatorischeMaatregelen'  => $this->organisatorischeMaatregelen,
            'verwerkingsverantwoordelijke' => $this->verwerkingsverantwoordelijke,
            'contactgegevensFg'            => $this->contactgegevensFg,
            'organisationId'               => $this->organisationId,
            'status'                       => $this->status,
            'created'                      => $this->created?->format('c'),
            'updated'                      => $this->updated?->format('c'),
        ];

    }//end jsonSerialize()
}//end class
