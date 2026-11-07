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
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function ensureRegisters(array $plan, array &$record): array {
		$result = ['refusals' => [], 'outcome' => []];
		foreach ((array) ($plan['requires'] ?? []) as $slug => $required) {
			$register = new ExampleSetRequiredRegister(
				set: (string) ($plan['set'] ?? ''),
				slug: (string) $slug,
				mock: (($required['mock'] ?? false) === true)
			);
			$why      = $this->ensureRegister(register: $register, record: $record);
			$result['outcome'][$register->slug] = $this->outcomeOf(why: $why);
			if ($why !== null && $why !== '') {
				$result['refusals'][] = 'register "'.$register->slug.'" is required and could not be imported from OpenRegister\'s descriptor: '.$why;
			}
		}

		return $result;
	}//end ensureRegisters()

	/**
	 * Import one required register when it needs it, and record the import.
	 *
	 * @param ExampleSetRequiredRegister $register The required register.
	 * @param array<string, mixed>       $record   The record; gains the import's jobs.
	 *
	 * @return string|null Null when the register is kept, '' when it was imported, else why the import failed.
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function ensureRegister(ExampleSetRequiredRegister $register, array &$record): ?string {
		if ($this->needsImport(register: $register, record: $record) === false) {
			return null;
		}

		return $this->importRegister(register: $register, record: $record);
	}//end ensureRegister()

	/**
	 * Whether a required register is not on the instance, had its data removed by this set, or is behind its
	 * descriptor.
	 *
	 * @param ExampleSetRequiredRegister $register The required register.
	 * @param array<string, mixed>       $record   The record.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function needsImport(ExampleSetRequiredRegister $register, array $record): bool {
		// A register this set imported whose import was removed again is on the instance without its data.
		$dataRemoved = (array_key_exists($register->slug, $record['registers']) === true && $record['registers'][$register->slug] === []);

		return $this->registers->exists(slug: $register->slug) === false
			|| $dataRemoved === true
			|| $this->registers->isBehind(slug: $register->slug, mock: $register->mock) === true;
	}//end needsImport()

	/**
	 * Import a required register, record the jobs it ran, and say why it is still missing, or ''.
	 *
	 * @param ExampleSetRequiredRegister $register The required register.
	 * @param array<string, mixed>       $record   The record; gains the import's jobs.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/example-sets/spec.md#requirement-a-set-imports-the-openregister-registers-it-requires-req-exs-009
	 */
	private function importRegister(ExampleSetRequiredRegister $register, array &$record): string {
		$import = $this->importOf(register: $register);
		if ($import['jobs'] !== []) {
			$record['registers'][$register->slug] = array_merge(($record['registers'][$register->slug] ?? []), $import['jobs']);
			$this->record->write(set: $register->set, record: $record);
		}

		if ($import['error'] === '' && $this->registers->exists(slug: $register->slug) === false) {
			return 'it is still not on this instance';
		}

		return $import['error'];
	}//end importRegister()

	/**
	 * Import a required register from its descriptor, with its mock data when the set asks for it.
	 *
	 * @param ExampleSetRequiredRegister $register The required register.
	 *
	 * @return array{error: string, jobs: array<int, array{appId: string, jobId: string}>}
	 */
	private function importOf(ExampleSetRequiredRegister $register): array {
		if ($register->mock === true) {
			return $this->registers->importMock(slug: $register->slug);
		}

		return $this->registers->import(slug: $register->slug);
	}//end importOf()

	/**
	 * A required register's outcome: `kept`, `imported` or `failed`.
	 *
	 * @param string|null $why Null when kept, '' when imported, else why the import failed.
	 *
	 * @return string
	 */
	private function outcomeOf(?string $why): string {
		return match ($why) {
			null    => 'kept',
			''      => 'imported',
			default => 'failed',
		};
	}//end outcomeOf()

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
