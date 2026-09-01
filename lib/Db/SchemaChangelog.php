<?php

/**
 * SchemaChangelog entity — one classified entry per schema-version change.
 *
 * Records, for each schema definition update, the resulting version, the
 * change classification (`compatible` | `breaking`), the typed change list
 * (from {@see \OCA\OpenRegister\Service\Schema\SchemaDiffService}), the
 * acting user, and — when the change was breaking — the acknowledging
 * actor and timestamp. The changelog is written in the same transaction as
 * the schema update so version and changelog can never drift.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class SchemaChangelog
 *
 * @method int getSchemaId()
 * @method void setSchemaId(int $schemaId)
 * @method string|null getVersion()
 * @method void setVersion(?string $version)
 * @method string|null getClassification()
 * @method void setClassification(?string $classification)
 * @method array|null getChanges()
 * @method void setChanges(?array $changes)
 * @method string|null getActor()
 * @method void setActor(?string $actor)
 * @method string|null getAcknowledgedBy()
 * @method void setAcknowledgedBy(?string $acknowledgedBy)
 * @method DateTime|null getAcknowledgedAt()
 * @method void setAcknowledgedAt(?DateTime $acknowledgedAt)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class SchemaChangelog extends Entity implements JsonSerializable {

	/**
	 * The schema this changelog entry belongs to.
	 *
	 * @var integer|null
	 */
	protected ?int $schemaId = null;

	/**
	 * The resulting schema version for this change.
	 *
	 * @var string|null
	 */
	protected ?string $version = null;

	/**
	 * The change classification (compatible | breaking | none).
	 *
	 * @var string|null
	 */
	protected ?string $classification = null;

	/**
	 * The typed change list.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	protected ?array $changes = null;

	/**
	 * The acting user id.
	 *
	 * @var string|null
	 */
	protected ?string $actor = null;

	/**
	 * The user who acknowledged a breaking change.
	 *
	 * @var string|null
	 */
	protected ?string $acknowledgedBy = null;

	/**
	 * When the breaking change was acknowledged.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $acknowledgedAt = null;

	/**
	 * Creation timestamp.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor — registers field types for hydration.
	 */
	public function __construct() {
		$this->addType(fieldName: 'schemaId', type: 'integer');
		$this->addType(fieldName: 'version', type: 'string');
		$this->addType(fieldName: 'classification', type: 'string');
		$this->addType(fieldName: 'changes', type: 'json');
		$this->addType(fieldName: 'actor', type: 'string');
		$this->addType(fieldName: 'acknowledgedBy', type: 'string');
		$this->addType(fieldName: 'acknowledgedAt', type: 'datetime');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * The field names registered with the 'json' type.
	 *
	 * @return array<int, string> The json-typed field names.
	 */
	public function getJsonFields(): array {
		return array_keys(
			array_filter(
				$this->getFieldTypes(),
				static function ($field) {
					return $field === 'json';
				}
			)
		);
	}//end getJsonFields()

	/**
	 * Hydrate the entity from an array.
	 *
	 * Without this, SchemaChangelogMapper::createFromArray()'s `$entry->hydrate()`
	 * call hit Entity::__call and threw "hydrate does not exist", so every schema
	 * changelog write failed silently (swallowed by the caller's try/catch) and
	 * the schema-change audit trail was never recorded.
	 *
	 * @param array<string, mixed> $object The source data.
	 *
	 * @return static This entity, hydrated.
	 *
	 * @spec openspec/specs/schema-migration/spec.md
	 */
	public function hydrate(array $object): static {
		$jsonFields = $this->getJsonFields();

		foreach ($object as $key => $value) {
			if (in_array($key, $jsonFields, true) === true && $value === []) {
				$value = null;
			}

			$method = 'set' . ucfirst($key);

			try {
				$this->$method($value);
			} catch (\Exception $exception) {
				// Silently ignore invalid properties.
			}
		}

		return $this;
	}//end hydrate()

	/**
	 * JSON serialisation.
	 *
	 * @return array<string, mixed> The serialised changelog entry.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'schemaId' => $this->schemaId,
			'version' => $this->version,
			'classification' => $this->classification,
			'changes' => ($this->changes ?? []),
			'actor' => $this->actor,
			'acknowledgedBy' => $this->acknowledgedBy,
			'acknowledgedAt' => $this->acknowledgedAt?->format(DateTime::ATOM),
			'created' => $this->created?->format(DateTime::ATOM),
		];

	}//end jsonSerialize()
}//end class
