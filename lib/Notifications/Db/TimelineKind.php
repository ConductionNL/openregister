<?php

/**
 * TimelineKind entity: an administered kind of timeline entry.
 *
 * A contactmoment needs a channel and a direction. Modelling it as its own
 * object splits the timeline in two: some things are entries and some are
 * objects that look like entries. A kind keeps one timeline and still lets an
 * entry carry declared fields (D-2).
 *
 * The properties are a JSON Schema `properties` map, validated the way object
 * properties are validated, so a kind introduces no second schema language.
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
 * TimelineKind.
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string getSlug()
 * @method void setSlug(string $slug)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method array|null getProperties()
 * @method void setProperties(?array $properties)
 * @method array|null getRequired()
 * @method void setRequired(?array $required)
 * @method boolean|null getFollowUp()
 * @method void setFollowUp(?bool $followUp)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
 */
class TimelineKind extends AdministeredDeclaration {

	/**
	 * What the kind is for.
	 *
	 * @var string|null
	 */
	protected ?string $description = null;

	/**
	 * A JSON Schema `properties` map.
	 *
	 * @var array<string,mixed>|null
	 */
	protected ?array $properties = null;

	/**
	 * The property names an entry of this kind must carry.
	 *
	 * @var array<int,string>|null
	 */
	protected ?array $required = null;

	/**
	 * Whether entries of this kind carry an open or done follow-up state.
	 *
	 * @var boolean|null
	 */
	protected ?bool $followUp = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addNameTypes();
		$this->addType(fieldName: 'description', type: 'string');
		$this->addType(fieldName: 'properties', type: 'json');
		$this->addType(fieldName: 'required', type: 'json');
		$this->addType(fieldName: 'followUp', type: 'boolean');
		$this->addScopeTypes();
		$this->addTimestampTypes();

	}//end __construct()

	/**
	 * The kind as the API returns it.
	 *
	 * @return array<string, mixed> The declaration.
	 *
	 * @spec openspec/changes/timeline-entries-are-records/specs/object-interactions/spec.md
	 */
	public function jsonSerialize(): array {
		return array_merge(
			$this->nameFields(),
			[
				'description' => $this->description,
				'properties' => ($this->properties ?? []),
				'required' => ($this->required ?? []),
				'followUp' => ($this->followUp === true),
			],
			$this->scopeFields(),
			$this->timestampFields()
		);

	}//end jsonSerialize()
}//end class
