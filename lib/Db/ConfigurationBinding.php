<?php

/**
 * ConfigurationBinding entity — one subject bound to one configuration bundle.
 *
 * D-5: a bundle is a binding, not a template. A template is copied and then
 * drifts, which is the two hundred zaaktypen problem restated. So there is no
 * bundle model here and no copy of a bundle's values per subject. A bundle IS
 * its name plus the values recorded against it at the bundle layer, and this
 * row is the only thing that says a subject follows it.
 *
 * A subject is bound to at most one bundle, which is why the table's unique
 * index is on the subject and not on the pair. The explainer's chain has one
 * bundle slot between register and subject; two bundles for one subject would
 * mean the chain had to pick, and a precedence rule nobody wrote is a rule
 * every reader guesses differently.
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
 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ConfigurationBinding
 *
 * @method string|null getUuid()
 * @method void setUuid(?string $uuid)
 * @method string|null getBundle()
 * @method void setBundle(?string $bundle)
 * @method string|null getSubject()
 * @method void setSubject(?string $subject)
 * @method string|null getSubjectType()
 * @method void setSubjectType(?string $subjectType)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 * @method DateTime|null getUpdated()
 * @method void setUpdated(?DateTime $updated)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class ConfigurationBinding extends Entity implements JsonSerializable {

	/**
	 * The subject is a schema, which is what a case type is here.
	 *
	 * @var string
	 */
	public const TYPE_SCHEMA = 'schema';

	/**
	 * The subject is a role, which is what a permission matrix hangs off.
	 *
	 * @var string
	 */
	public const TYPE_ROLE = 'role';

	/**
	 * Stable UUID.
	 *
	 * @var string|null
	 */
	protected ?string $uuid = null;

	/**
	 * The bundle name. It is the bundle's identity: the bundle-layer values
	 * are keyed by it, and so is every binding that follows it.
	 *
	 * @var string|null
	 */
	protected ?string $bundle = null;

	/**
	 * The subject following the bundle.
	 *
	 * @var string|null
	 */
	protected ?string $subject = null;

	/**
	 * What kind of subject it is.
	 *
	 * @var string|null
	 */
	protected ?string $subjectType = null;

	/**
	 * Who bound it.
	 *
	 * @var string|null
	 */
	protected ?string $createdBy = null;

	/**
	 * When the binding was made.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * When the binding last changed.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $updated = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'uuid', type: 'string');
		$this->addType(fieldName: 'bundle', type: 'string');
		$this->addType(fieldName: 'subject', type: 'string');
		$this->addType(fieldName: 'subjectType', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
		$this->addType(fieldName: 'updated', type: 'datetime');

	}//end __construct()

	/**
	 * Hydrate the entity from an array.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/specs/configuration-deployment/spec.md
	 */
	public function hydrate(array $object): static {
		foreach ($object as $key => $value) {
			$method = 'set'.ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore properties this entity does not carry.
			}
		}

		return $this;

	}//end hydrate()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised binding.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'uuid' => $this->uuid,
			'bundle' => $this->bundle,
			'subject' => $this->subject,
			'subjectType' => $this->subjectType,
			'createdBy' => $this->createdBy,
			'created' => $this->created?->format(DateTime::ATOM),
			'updated' => $this->updated?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
