<?php

/**
 * EntryReference entity: one recorded reference, readable from both ends.
 *
 * Rendering a link is presentation. Recording the reference is what makes
 * "which zaken mention this besluit" answerable, and it is what survives
 * somebody rewriting the sentence around the code (D-6).
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
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * EntryReference.
 *
 * @method string|null getEntryUuid()
 * @method void setEntryUuid(?string $entryUuid)
 * @method string|null getSourceUuid()
 * @method void setSourceUuid(?string $sourceUuid)
 * @method string|null getTargetUuid()
 * @method void setTargetUuid(?string $targetUuid)
 * @method string|null getCode()
 * @method void setCode(?string $code)
 * @method string|null getPatternSlug()
 * @method void setPatternSlug(?string $patternSlug)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class EntryReference extends Entity implements JsonSerializable {

	/**
	 * The entry whose text carried the code.
	 *
	 * @var string|null
	 */
	protected ?string $entryUuid = null;

	/**
	 * The object the entry hangs on: the writing end.
	 *
	 * @var string|null
	 */
	protected ?string $sourceUuid = null;

	/**
	 * The object the code resolved to: the referenced end.
	 *
	 * @var string|null
	 */
	protected ?string $targetUuid = null;

	/**
	 * The code as it was written.
	 *
	 * @var string|null
	 */
	protected ?string $code = null;

	/**
	 * The pattern that recognised it.
	 *
	 * @var string|null
	 */
	protected ?string $patternSlug = null;

	/**
	 * Where the rendered link points.
	 *
	 * @var string|null
	 */
	protected ?string $url = null;

	/**
	 * When the reference was recorded.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'entryUuid', type: 'string');
		$this->addType(fieldName: 'sourceUuid', type: 'string');
		$this->addType(fieldName: 'targetUuid', type: 'string');
		$this->addType(fieldName: 'code', type: 'string');
		$this->addType(fieldName: 'patternSlug', type: 'string');
		$this->addType(fieldName: 'url', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * The reference as the API returns it.
	 *
	 * @return array<string, mixed> The recorded reference.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'entryUuid' => $this->entryUuid,
			'sourceUuid' => $this->sourceUuid,
			'targetUuid' => $this->targetUuid,
			'code' => $this->code,
			'patternSlug' => $this->patternSlug,
			'url' => $this->url,
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
