<?php

/**
 * ReferencePattern entity: an administered short code and what it resolves to.
 *
 * ZAAK-2026-0412 typed in a sentence should reach the zaak. The pattern that
 * recognises it, the register and schema it is looked up in, the property it
 * is matched on and the url it links to are administered, not coded: an
 * instance that declares no pattern renders code-shaped text unchanged.
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

namespace OCA\OpenRegister\Notifications\Db;

use DateTime;

/**
 * ReferencePattern.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string getPattern()
 * @method void setPattern(string $pattern)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getTargetProperty()
 * @method void setTargetProperty(?string $targetProperty)
 * @method string|null getUrlTemplate()
 * @method void setUrlTemplate(?string $urlTemplate)
 * @method boolean|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class ReferencePattern extends AdministeredDeclaration {

	/**
	 * The body of the expression, without delimiters or flags. The resolver
	 * adds both, so an administrator cannot smuggle in `/e` or a flag that
	 * changes what the expression means.
	 *
	 * @var string|null
	 */
	protected ?string $pattern = null;

	/**
	 * The property the matched code is matched on.
	 *
	 * @var string|null
	 */
	protected ?string $targetProperty = null;

	/**
	 * The link target, with `{code}` substituted.
	 *
	 * @var string|null
	 */
	protected ?string $urlTemplate = null;

	/**
	 * Whether the pattern is applied at all.
	 *
	 * @var boolean|null
	 */
	protected ?bool $enabled = true;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addNameTypes();
		$this->addType(fieldName: 'pattern', type: 'string');
		$this->addScopeTypes();
		$this->addType(fieldName: 'targetProperty', type: 'string');
		$this->addType(fieldName: 'urlTemplate', type: 'string');
		$this->addType(fieldName: 'enabled', type: 'boolean');
		$this->addTimestampTypes();

	}//end __construct()

	/**
	 * The pattern as the API returns it.
	 *
	 * @return array<string, mixed> The declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return array_merge(
			$this->nameFields(),
			[
				'pattern' => $this->pattern,
			],
			$this->scopeFields(),
			[
				'targetProperty' => $this->targetProperty,
				'urlTemplate' => $this->urlTemplate,
				'enabled' => ($this->enabled !== false),
			],
			$this->timestampFields()
		);

	}//end jsonSerialize()
}//end class
