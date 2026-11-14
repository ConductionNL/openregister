<?php

/**
 * ForeignIdentifier entity — an identifier another system gave an object.
 *
 * Each row names the issuing system, so the same value from two senders
 * stays two distinct identifiers and a lookup by issuer and value is exact.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <dev@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One foreign identifier of one object.
 *
 * @method string|null getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method string|null getIssuer()
 * @method void setIssuer(string $issuer)
 * @method string|null getValue()
 * @method void setValue(string $value)
 * @method DateTime|null getCreated()
 * @method void setCreated(?DateTime $created)
 */
class ForeignIdentifier extends Entity implements JsonSerializable {

	/**
	 * The object this identifier belongs to.
	 *
	 * @var string|null
	 */
	protected ?string $objectUuid = null;

	/**
	 * The system that issued the value.
	 *
	 * @var string|null
	 */
	protected ?string $issuer = null;

	/**
	 * The value as the issuer wrote it.
	 *
	 * @var string|null
	 */
	protected ?string $value = null;

	/**
	 * When the identifier was recorded.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Declare the column types.
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function __construct() {
		$this->addType(fieldName: 'objectUuid', type: 'string');
		$this->addType(fieldName: 'issuer', type: 'string');
		$this->addType(fieldName: 'value', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');
	}//end __construct()

	/**
	 * The identifier as JSON.
	 *
	 * @return array{issuer: string|null, value: string|null, object: string|null, created: string|null}
	 *
	 * @spec openspec/changes/generated-identifier/specs/computed-fields/spec.md#requirement-an-object-carries-foreign-identifiers-that-name-their-issuer-req-gid-005
	 */
	public function jsonSerialize(): array {
		return [
			'issuer'  => $this->issuer,
			'value'   => $this->value,
			'object'  => $this->objectUuid,
			'created' => $this->created?->format(DATE_ATOM),
		];
	}//end jsonSerialize()
}//end class
