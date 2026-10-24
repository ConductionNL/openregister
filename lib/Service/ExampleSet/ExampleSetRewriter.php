<?php

/**
 * OpenRegister Example Set Rewriter
 *
 * `example-set:install --refresh`: an object the set wrote in an earlier run
 * is rewritten to the values its declaration gives today. Dates count from
 * the install day, cast, lookups and objects are read as they are now, and
 * every declared field goes over the stored row; a field the declaration does
 * not name stays as it is. Only objects the set's record holds come here, so
 * nothing the set did not write is touched.
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
 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ExampleSet;

/**
 * Writes a kept object's declared values over its stored row.
 *
 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
 */
class ExampleSetRewriter {

	/**
	 * A kept object: on `--refresh` (the plan's `refresh`) rewritten to today's declared values, otherwise pointed
	 * at the objects it references now.
	 *
	 * @param array<string, mixed>                $plan       The plan.
	 * @param array<string, mixed>                $run        The `store`, the `fragment`, the declared `object` and the stored `row`.
	 * @param callable(): array<string, mixed>    $resolve    Resolves the declaration today.
	 * @param callable(): array<string, mixed>    $references Refreshes only the references, when not refreshing.
	 * @param array<string, mixed>                $report     The report.
	 * @param array<string, array<string, mixed>> $written    Collects what this run sent, by record key.
	 *
	 * @return array<string, mixed> The object as stored afterwards.
	 *
	 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function keep(array $plan, array $run, callable $resolve, callable $references, array &$report, array &$written): array {
		if (($plan['refresh'] ?? false) !== true) {
			return $references();
		}

		return $this->rewrite(
			store: $run['store'],
			fragment: $run['fragment'],
			object: $run['object'],
			row: $run['row'],
			resolved: $resolve(),
			report: $report,
			written: $written
		);
	}//end keep()

	/**
	 * Rewrite one kept object; the object as stored afterwards, or the row as it was when it could not be written.
	 *
	 * @param ExampleSetStore                     $store    Register access.
	 * @param array<string, mixed>                $fragment The fragment.
	 * @param array<string, mixed>                $object   The declared object.
	 * @param array<string, mixed>                $row      The object as stored.
	 * @param array{refusal: string, data: array<string, mixed>, sent: array<string, mixed>} $resolved The declaration resolved today.
	 * @param array<string, mixed>                $report   The report; `types` counts it as refreshed, `dropped` names a failure.
	 * @param array<string, array<string, mixed>> $written  Collects what this run sent, by record key, for the proof.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/example-set-refresh/specs/example-sets/spec.md#requirement-a-refresh-rewrites-every-object-the-set-declares
	 */
	public function rewrite(
		ExampleSetStore $store,
		array $fragment,
		array $object,
		array $row,
		array $resolved,
		array &$report,
		array &$written
	): array {
		$where = $fragment['file'].': object "'.$object['key'].'"';
		if ($resolved['refusal'] !== '') {
			$report['dropped'][] = $where.': not refreshed: '.$resolved['refusal'];
			return $row;
		}

		$id    = (string) ($row['id'] ?? ($row['@self']['id'] ?? ''));
		$error = $store->update(register: $object['register'], schema: $object['schema'], id: $id, data: array_merge($row, $resolved['sent']));
		if ($error !== '') {
			$report['dropped'][] = $where.': not refreshed: '.$error;
			return $row;
		}

		$type = $object['register'].' '.$object['schema'];
		$report['types'][$type]['refreshed'] = (($report['types'][$type]['refreshed'] ?? 0) + 1);
		$written[$fragment['app'].'/'.$object['key']] = $resolved['data'];

		return ($store->get(register: $object['register'], schema: $object['schema'], id: $id) ?? $row);
	}//end rewrite()
}//end class
