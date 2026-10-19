<?php

/**
 * The save-time contract of a stored decision table, for the bulk writer.
 *
 * A bulk save dispatches no ObjectCreatingEvent, so {@see DecisionTableValidationListener} never sees its rows
 * and a table with a key another table already carries (or a table the engine cannot run) would be stored. This
 * guard asks the listener's own rule per row, and in addition refuses a key that two rows of the same batch both
 * claim, which no stored-table lookup can see yet.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Dmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Dmn;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Listener\DecisionTableValidationListener;
use Psr\Container\ContainerInterface;

/**
 * Refuses the decision-table rows of a bulk save that the single save would have refused.
 *
 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
 */
class DecisionTableBulkGuard {

	/**
	 * The single-save rule, resolved on first use.
	 *
	 * @var DecisionTableValidationListener|null
	 */
	private ?DecisionTableValidationListener $listener = null;

	/**
	 * Constructor.
	 *
	 * The listener is not injected directly: it needs ObjectService, which
	 * needs SaveObjects, which needs this guard. Resolving it when the first
	 * row is checked breaks that cycle; ObjectService exists by then.
	 *
	 * @param ContainerInterface $container Hands out the single-save rule.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
	) {
	}//end __construct()

	/**
	 * The single-save rule.
	 *
	 * @return DecisionTableValidationListener The listener.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	private function listener(): DecisionTableValidationListener {
		if ($this->listener === null) {
			$this->listener = $this->container->get(DecisionTableValidationListener::class);
		}

		return $this->listener;
	}//end listener()

	/**
	 * The rows that must not be stored, by their position in the batch.
	 *
	 * @param array<int|string, array<string, mixed>> $objects  The raw rows.
	 * @param Register|string|int|null                $register The batch's default register.
	 * @param Schema|string|int|null                  $schema   The batch's default schema.
	 *
	 * @return array<int|string, string> The refusal reason per refused row.
	 *
	 * @spec openspec/changes/stored-decision-tables/specs/flow-decision-tables/spec.md
	 */
	public function refusals(array $objects, Register|string|int|null $register, Schema|string|int|null $schema): array {
		$defaultSchema   = $this->reference(value: $schema);
		$defaultRegister = $this->reference(value: $register);
		$isTable         = [];
		$claimed         = [];
		$refused         = [];

		foreach ($objects as $position => $row) {
			$self = [];
			if (is_array($row['@self'] ?? null) === true) {
				$self = $row['@self'];
			}

			$schemaRef   = ($this->reference(value: ($self['schema'] ?? null)) ?? $defaultSchema);
			$registerRef = ($this->reference(value: ($self['register'] ?? null)) ?? $defaultRegister);

			$memo = ($schemaRef . '|' . $registerRef);
			if (array_key_exists($memo, $isTable) === false) {
				$isTable[$memo] = $this->listener()->isDecisionTableRef(schema: $schemaRef, register: $registerRef);
			}

			if ($isTable[$memo] === false) {
				continue;
			}

			$data = $row;
			unset($data['@self']);
			$uuid     = $this->uuidOf(row: $row, self: $self);
			$problems = ($this->listener()->problemsFor(schema: $schemaRef, register: $registerRef, data: $data, uuid: $uuid) ?? []);

			$clash = $this->claim(claimed: $claimed, key: trim((string)($data['key'] ?? '')), uuid: $uuid);
			if ($clash !== null) {
				$problems[] = $clash;
			}

			if ($problems !== []) {
				$refused[$position] = 'The decision table cannot be saved: ' . implode('; ', $problems) . '.';
			}
		}//end foreach

		return $refused;
	}//end refusals()

	/**
	 * Claim a key for a row, or say that an earlier row of the batch holds it.
	 *
	 * @param array<string, string> $claimed The keys claimed so far, with the uuid of the claiming row.
	 * @param string                $key     The row's key.
	 * @param string                $uuid    The row's uuid, '' for a new row.
	 *
	 * @return string|null The problem, or null when the key is free or the same row claims it again.
	 */
	private function claim(array &$claimed, string $key, string $uuid): ?string {
		if ($key === '') {
			return null;
		}

		$other = ($claimed[$key] ?? null);
		$claimed[$key] = ($other ?? $uuid);
		if ($other !== null && ($other !== $uuid || $uuid === '')) {
			return sprintf('the key "%s" is claimed by another row of the same save', $key);
		}

		return null;
	}//end claim()

	/**
	 * A register or schema value as the reference string the listener resolves.
	 *
	 * @param mixed $value An entity, an id or a slug.
	 *
	 * @return string|null The reference, or null when there is none.
	 */
	private function reference(mixed $value): ?string {
		if ($value instanceof Schema === true || $value instanceof Register === true) {
			$value = $value->getId();
		}

		if ($value === null || $value === '' || $value === 0 || $value === '0') {
			return null;
		}

		return (string)$value;
	}//end reference()

	/**
	 * The uuid a row carries, '' for a new one.
	 *
	 * @param array<string, mixed> $row  The row.
	 * @param array<string, mixed> $self The row's `@self`.
	 *
	 * @return string
	 */
	private function uuidOf(array $row, array $self): string {
		$uuid = ($self['uuid'] ?? $self['id'] ?? $row['id'] ?? '');

		if (is_string($uuid) === true) {
			return $uuid;
		}

		return '';
	}//end uuidOf()
}//end class
