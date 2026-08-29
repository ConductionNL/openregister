<?php

/**
 * One version of a flow, and the lifecycle state that decides what it may back.
 *
 * A version row is a NAME for a graph, not a copy of one: it points at an
 * immutable row in `openregister_flow_defs` by hash. That split is what makes
 * "version 3 is immutable" structural rather than a rule somebody has to keep
 * — the content is addressed by its own hash, so editing it in place is not an
 * operation the storage offers.
 *
 * 🔴 EXACTLY ONE PUBLISHED VERSION PER FLOW. Every dispatch resolves "the
 * published version of this flow"; two of them would make that question
 * ambiguous and the answer a race. `FlowVersionService` enforces it inside one
 * transaction, and the flow-and-status index is what makes the read cheap.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Database
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;

/**
 * A single version of a flow definition.
 *
 * @method string|null   getFlowUuid()
 * @method void          setFlowUuid(?string $flowUuid)
 * @method integer|null  getVersion()
 * @method void          setVersion(?int $version)
 * @method string|null   getStatus()
 * @method void          setStatus(?string $status)
 * @method string|null   getDefinitionHash()
 * @method void          setDefinitionHash(?string $definitionHash)
 * @method string|null   getOwner()
 * @method void          setOwner(?string $owner)
 * @method string|null   getOrganisation()
 * @method void          setOrganisation(?string $organisation)
 * @method DateTime|null getPublishedAt()
 * @method void          setPublishedAt(?DateTime $publishedAt)
 * @method string|null   getPublishedBy()
 * @method void          setPublishedBy(?string $publishedBy)
 * @method DateTime|null getDeprecatedAt()
 * @method void          setDeprecatedAt(?DateTime $deprecatedAt)
 * @method DateTime|null getCreated()
 * @method void          setCreated(?DateTime $created)
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowVersion extends Entity implements \JsonSerializable {
	/**
	 * Editable. Must not back a triggered, scheduled or sub-flow run.
	 *
	 * @var string
	 */
	public const STATUS_DRAFT = 'draft';

	/**
	 * Immutable, and the only status a new run may be queued against.
	 *
	 * @var string
	 */
	public const STATUS_PUBLISHED = 'published';

	/**
	 * Immutable and retired. Backs no new run; runs already pinned to it finish.
	 *
	 * @var string
	 */
	public const STATUS_DEPRECATED = 'deprecated';

	/**
	 * Every status a version may hold.
	 *
	 * 🔑 A CLOSED LIST, checked on write. An unrecognised status would be read
	 * as "not published" by every dispatch path and as "not draft" by the
	 * editor, so a typo would silently make a flow unrunnable AND uneditable.
	 *
	 * @var string[]
	 */
	public const STATUSES = [
		self::STATUS_DRAFT,
		self::STATUS_PUBLISHED,
		self::STATUS_DEPRECATED,
	];

	/**
	 * The flow this is a version of.
	 *
	 * @var string|null
	 */
	protected ?string $flowUuid = null;

	/**
	 * The version number, starting at 1 and rising by one per draft.
	 *
	 * @var integer|null
	 */
	protected ?int $version = null;

	/**
	 * The lifecycle status; one of self::STATUSES.
	 *
	 * @var string|null
	 */
	protected ?string $status = null;

	/**
	 * The sha256 of the graph this version names.
	 *
	 * @var string|null
	 */
	protected ?string $definitionHash = null;

	/**
	 * Who owned the flow when this version was cut.
	 *
	 * @var string|null
	 */
	protected ?string $owner = null;

	/**
	 * Which tenant owned the flow when this version was cut.
	 *
	 * @var string|null
	 */
	protected ?string $organisation = null;

	/**
	 * When this version was published.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $publishedAt = null;

	/**
	 * Who published it.
	 *
	 * @var string|null
	 */
	protected ?string $publishedBy = null;

	/**
	 * When it was deprecated.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $deprecatedAt = null;

	/**
	 * When the row was created.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'flowUuid', type: 'string');
		$this->addType(fieldName: 'version', type: 'integer');
		$this->addType(fieldName: 'status', type: 'string');
		$this->addType(fieldName: 'definitionHash', type: 'string');
		$this->addType(fieldName: 'owner', type: 'string');
		$this->addType(fieldName: 'organisation', type: 'string');
		$this->addType(fieldName: 'publishedAt', type: 'datetime');
		$this->addType(fieldName: 'publishedBy', type: 'string');
		$this->addType(fieldName: 'deprecatedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Whether this version may back a newly queued run.
	 *
	 * Asked rather than compared, because "may this back a run" is the
	 * question every dispatch path actually has, and spelling it as
	 * `getStatus() === 'published'` at six call sites is six chances to write
	 * a different comparison.
	 *
	 * @return boolean True when this version is published.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function isPublished(): bool {
		return $this->status === self::STATUS_PUBLISHED;

	}//end isPublished()

	/**
	 * Whether this version may still be edited.
	 *
	 * @return boolean True when this version is a draft.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function isDraft(): bool {
		return $this->status === self::STATUS_DRAFT;

	}//end isDraft()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The version as an array.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'flowUuid' => $this->flowUuid,
			'version' => $this->version,
			'status' => $this->status,
			'definitionHash' => $this->definitionHash,
			'owner' => $this->owner,
			'organisation' => $this->organisation,
			'publishedAt' => $this->publishedAt?->format('c'),
			'publishedBy' => $this->publishedBy,
			'deprecatedAt' => $this->deprecatedAt?->format('c'),
			'created' => $this->created?->format('c'),
		];

	}//end jsonSerialize()
}//end class
