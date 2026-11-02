<?php

/**
 * OpenRegister Example Set Prerequisites
 *
What an example set needs before its check: the OpenRegister registers it
 * requires, imported when the instance lacks them, and the OpenRegister
 * organisations its cast declares, created when missing. Also the context
 * every placeholder may draw on: the cast, the service accounts, the
 * organisations and the instance's address.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ExampleSet
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

use DateTimeImmutable;

/**
 * Registers and organisations before the check, and the base context.
 *
 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
 */
class ExampleSetPrerequisites {
	/**
	 * Constructor.
	 *
	 * @param ExampleSetRegisters $registers Imports required registers.
	 * @param ExampleSetInstance  $instance  The instance's address and organisations.
	 * @param ExampleSetRecord    $record    What an install made.
	 * @param ExampleSetPeople    $people    Names the service accounts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ExampleSetRegisters $registers,
		private readonly ExampleSetInstance $instance,
		private readonly ExampleSetRecord $record,
		private readonly ExampleSetPeople $people,
	) {
	}//end __construct()

	/**
	 * Import the required registers and create the cast's organisations that the instance lacks, recording both.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record; gains what was imported and created.
	 *
	 * @return array{refusals: array<int, string>, registers: array<string, string>} Refusals, and per required register
	 *                                                                              `imported`, `kept` or `failed`.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	public function ensure(array $plan, array &$record): array {
		$registers = $this->ensureRegisters(plan: $plan, record: $record);

		return [
			'refusals'  => array_merge($registers['refusals'], $this->ensureOrganisations(plan: $plan, record: $record)),
			'registers' => $registers['outcome'],
		];
	}//end ensure()

	/**
	 * What every placeholder of the set may draw on, besides a fragment's own objects.
	 *
	 * @param array<string, mixed> $plan  The plan.
	 * @param DateTimeImmutable    $today The install day.
	 *
	 * @return array<string, mixed> `cast`, `today`, `accounts` (service accounts by key), `organisations` (uuid by
	 *                              slug and name) and `instance` (`baseUrl`).
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-every-placeholder-resolves-or-the-set-is-refused-req-exs-002
	 */
	public function context(array $plan, DateTimeImmutable $today): array {
		$accounts = [];
		foreach ($this->people->serviceAccounts(plan: $plan) as $account) {
			$accounts[$account['key']] = ['uid' => $account['uid'], 'displayName' => $account['displayName']];
		}

		return [
			'cast'          => $plan['cast']['entries'],
			'today'         => $today,
			'accounts'      => $accounts,
			'organisations' => $this->instance->uuids(),
			'instance'      => $this->instance->values(),
		];
	}//end context()

	/**
	 * Import every required register that is not on the instance, that this set imported and removed the data of, or
	 * whose descriptor ships a newer version than the instance holds, and record the import.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record; gains the imports.
	 *
	 * @return array{refusals: array<int, string>, outcome: array<string, string>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Missing, emptied and behind are three reasons to
	 *                                               import, then the jobs and the refusal follow.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function ensureRegisters(array $plan, array &$record): array {
		$result = ['refusals' => [], 'outcome' => []];
		foreach ((array) ($plan['requires'] ?? []) as $slug => $required) {
			$slug = (string) $slug;
			$result['outcome'][$slug] = 'kept';
			// A register this set imported whose import was removed again is on the instance without its data.
			$dataRemoved = (array_key_exists($slug, $record['registers']) === true && $record['registers'][$slug] === []);
			$mock        = (($required['mock'] ?? false) === true);
			if ($this->registers->exists(slug: $slug) === true && $dataRemoved === false
				&& $this->registers->isBehind(slug: $slug, mock: $mock) === false
			) {
				continue;
			}

			$import = match ($mock) {
				true  => $this->registers->importMock(slug: $slug),
				false => $this->registers->import(slug: $slug),
			};
			if ($import['jobs'] !== []) {
				$record['registers'][$slug] = array_merge(($record['registers'][$slug] ?? []), $import['jobs']);
				$this->record->write(set: (string) $plan['set'], record: $record);
			}

			$why = $import['error'];
			if ($why === '' && $this->registers->exists(slug: $slug) === false) {
				$why = 'it is still not on this instance';
			}

			if ($why !== '') {
				$result['refusals'][]     = 'register "'.$slug.'" is required and could not be imported from OpenRegister\'s descriptor: '.$why;
				$result['outcome'][$slug] = 'failed';
				continue;
			}

			$result['outcome'][$slug] = 'imported';
		}//end foreach

		return $result;
	}//end ensureRegisters()

	/**
	 * Create every OpenRegister organisation the cast declares and the instance lacks, and record it; one the set
	 * made earlier gains the quota it declares where it has none.
	 *
	 * @param array<string, mixed> $plan   The plan.
	 * @param array<string, mixed> $record The record; gains the organisations this set created.
	 *
	 * @return array<int, string> Refusals.
	 */
	private function ensureOrganisations(array $plan, array &$record): array {
		$refusals = [];
		foreach ((array) ($plan['organisations'] ?? []) as $declared) {
			$made = $this->instance->ensure(declared: $declared, made: (array) ($record['organisations'] ?? []));
			if ($made['error'] !== '') {
				$refusals[] = 'OpenRegister organisation "'.$declared['slug'].'" could not be created or completed: '.$made['error'];
				continue;
			}

			if ($made['created'] === true) {
				$record['organisations'][] = $made['uuid'];
				$this->record->write(set: (string) $plan['set'], record: $record);
			}
		}

		return $refusals;
	}//end ensureOrganisations()
}//end class
