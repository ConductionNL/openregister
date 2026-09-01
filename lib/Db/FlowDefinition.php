<?php

/**
 * One immutable, hash-addressed flow definition.
 *
 * A row here is what a run walks. It is written once and never updated: the
 * hash is derived from the content, so "changing" a definition produces a
 * different row rather than mutating this one. That immutability is the whole
 * safety property — a run pinned to a hash cannot have its graph edited under
 * it, which is what ADR-098 Decision 6 requires before a human task node may
 * suspend a run for days.
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
 * A pinned flow definition.
 *
 * @method string|null   getHash()
 * @method void          setHash(?string $hash)
 * @method string|null   getFlowUuid()
 * @method void          setFlowUuid(?string $flowUuid)
 * @method string|null   getDefinition()
 * @method void          setDefinition(?string $definition)
 * @method DateTime|null getCreated()
 * @method void          setCreated(?DateTime $created)
 *
 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
 */
class FlowDefinition extends Entity implements \JsonSerializable {
	/**
	 * The sha256 of the canonical definition.
	 *
	 * @var string|null
	 */
	protected ?string $hash = null;

	/**
	 * The flow this definition was captured from.
	 *
	 * Provenance only. A definition stays readable after its flow is deleted,
	 * which is exactly what an in-flight run needs.
	 *
	 * @var string|null
	 */
	protected ?string $flowUuid = null;

	/**
	 * The canonical definition, JSON-encoded.
	 *
	 * @var string|null
	 */
	protected ?string $definition = null;

	/**
	 * When this definition was first seen.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $created = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->addType(fieldName: 'hash', type: 'string');
		$this->addType(fieldName: 'flowUuid', type: 'string');
		$this->addType(fieldName: 'definition', type: 'string');
		$this->addType(fieldName: 'created', type: 'datetime');

	}//end __construct()

	/**
	 * Serialise for the API.
	 *
	 * @return array<string, mixed> The definition as an array.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'hash' => $this->hash,
			'flowUuid' => $this->flowUuid,
			'definition' => $this->decoded(),
			'created' => $this->created?->format('c'),
		];

	}//end jsonSerialize()

	/**
	 * The definition as an array.
	 *
	 * Returns an empty array rather than null on unreadable JSON. A caller
	 * that gets `[]` walks a flow with no nodes and stops immediately, which
	 * is the safe end of the two — returning null would put a type error in
	 * the advancer instead.
	 *
	 * @return array<string, mixed> The decoded definition.
	 *
	 * @spec openspec/changes/flow-definition-versioning/specs/flow-definition-versioning/spec.md
	 */
	public function decoded(): array {
		if ($this->definition === null || trim($this->definition) === '') {
			return [];
		}

		$decoded = json_decode($this->definition, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;

	}//end decoded()
}//end class
