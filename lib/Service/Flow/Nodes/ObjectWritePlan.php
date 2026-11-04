<?php

/**
 * What one object-write step resolved from its configuration, and what it wrote.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCP\IUser;

/**
 * The operation, the acting owner, the register and schema, the match pairs,
 * fields and policies an object-write step resolved once before its first
 * write, and the counts of what it wrote.
 *
 * @spec openspec/changes/or-flow-object-write-node/specs/flow-object-write-node/spec.md
 */
final class ObjectWritePlan
{

	/**
	 * The resolved operation.
	 *
	 * @var string
	 */
	public string $operation = '';




	/**
	 * The match pairs.
	 *
	 * @var array
	 */
	public array $pairs = [];

	/**
	 * The configured fields.
	 *
	 * @var array
	 */
	public array $fields = [];

	/**
	 * The missing-field policy.
	 *
	 * @var string
	 */
	public string $onMissing = '';

	/**
	 * The conflict policy.
	 *
	 * @var string
	 */
	public string $onConflict = '';

	/**
	 * Whether an update replaces rather than patches.
	 *
	 * @var boolean
	 */
	public bool $replace = false;

	/**
	 * The write cap.
	 *
	 * @var integer
	 */
	public int $cap = 0;

	/**
	 * The path whose truthy value skips an item, or empty.
	 *
	 * @var string
	 */
	public string $skipWhen = '';

	/**
	 * The path whose value is the whole payload, or empty.
	 *
	 * @var string
	 */
	public string $payloadFrom = '';

	/**
	 * Writes performed so far, against the cap.
	 *
	 * @var integer
	 */
	public int $writes = 0;

	/**
	 * Objects created, updated and deleted.
	 *
	 * @var array{created: int, updated: int, deleted: int}
	 */
	public array $counts = ['created' => 0, 'updated' => 0, 'deleted' => 0];

	/**
	 * The run context.
	 *
	 * @var array
	 */
	public array $context = [];

	/**
	 * Constructor.
	 *
	 * @param array    $config   The step configuration.
	 * @param IUser    $owner    The run owner, as the acting user.
	 * @param Register $register The resolved register.
	 * @param Schema   $schema   The resolved schema.
	 */
	public function __construct(
		public readonly array $config,
		public readonly IUser $owner,
		public readonly Register $register,
		public readonly Schema $schema,
	) {
	}//end __construct()
}//end class
