<?php

/**
 * One interval an object's declared lifecycle property spent holding one value.
 *
 * 🔑 THE ROW IS DERIVED AND SAYS SO. It is written from the transition that
 * already happened and can be thrown away and rebuilt from the audit trail.
 * Nothing reads it as a source of truth about the object; it exists so a list
 * query has something to join, because ADR-009 forbids answering one by
 * scanning the trail.
 *
 * 🔑 `leftAt` NULL MEANS "STILL THERE". "Was ever in bezwaar" matches an
 * interval whether or not it has closed, so the current state is not a special
 * case: it is the same row with an open end.
 *
 * 🔴 `property` IS THE ONE THE SCHEMA DECLARES, resolved from
 * `x-openregister-lifecycle.field`, never a key read off the write. A
 * projection that recorded whatever the pipeline attached would let a filter
 * reach a value the schema never declared as a state, and a reader could learn
 * it from the result count alone.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/search-over-history-and-an-administered-dictionary/specs/zoeken-filteren/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One state interval.
 *
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(?string $objectUuid)
 * @method string|null getRegister()
 * @method void setRegister(?string $register)
 * @method string|null getSchema()
 * @method void setSchema(?string $schema)
 * @method string|null getProperty()
 * @method void setProperty(?string $property)
 * @method string|null getValue()
 * @method void setValue(?string $value)
 * @method DateTime|null getEnteredAt()
 * @method void setEnteredAt(?DateTime $enteredAt)
 * @method DateTime|null getLeftAt()
 * @method void setLeftAt(?DateTime $leftAt)
 */
class StateHistory extends Entity implements JsonSerializable {

	/**
	 * The object whose state this is.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The register slug, carried so a predicate can narrow without a join.
	 *
	 * @var string|null
	 */
	protected ?string $register = null;

	/**
	 * The schema slug, carried for the same reason.
	 *
	 * @var string|null
	 */
	protected ?string $schema = null;

	/**
	 * The DECLARED lifecycle property this interval belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $property = null;

	/**
	 * The value the property held for this interval.
	 *
	 * @var string|null
	 */
	protected ?string $value = null;

	/**
	 * When the object entered this value.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $enteredAt = null;

	/**
	 * When it left, or null while it is still there.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $leftAt = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'register', type: 'string');
		$this->addType(fieldName: 'schema', type: 'string');
		$this->addType(fieldName: 'property', type: 'string');
		$this->addType(fieldName: 'value', type: 'string');
		$this->addType(fieldName: 'enteredAt', type: 'datetime');
		$this->addType(fieldName: 'leftAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialise the interval.
	 *
	 * @return array<string, mixed> The interval.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'objectUuid' => $this->objectUuid,
			'register' => $this->register,
			'schema' => $this->schema,
			'property' => $this->property,
			'value' => $this->value,
			'enteredAt' => $this->enteredAt?->format('c'),
			'leftAt' => $this->leftAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
