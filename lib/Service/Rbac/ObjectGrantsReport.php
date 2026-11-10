<?php

/**
 * Who holds access to one object, one row per principal and origin.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCP\Share\IShare;

/**
 * Assembles an object's grant rows from the rules, its shares and its ancestors' shares.
 *
 * It decides nothing about access. The rules come from the same reader as
 * `/permissions` ({@see ObjectAccessReport}), a conditional rule is evaluated
 * with the matcher the single-object decision uses, and the shares come from
 * the guard-free reader. Its caller has already run {@see AccessReviewGate}.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each collaborator is one source of the rows
 *                                                 (rules, shares, ancestors, names) or the rule fit.
 *
 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
 */
class ObjectGrantsReport implements ObjectGrantsReportInterface {

	public const ORIGIN_OWNER = 'owner';

	public const ORIGIN_SHARED = 'shared';

	public const ORIGIN_INHERITED = 'inherited';

	public const ORIGIN_RULE = 'rule';

	/**
	 * The core permission bits a share's verbs are read from.
	 *
	 * @var string[]
	 */
	private const SHARE_BIT_VERBS = ['read', 'update', 'create', 'delete'];

	/**
	 * The owning group; stateless, so it is built in place.
	 *
	 * @var ObjectScopeResolver
	 */
	private readonly ObjectScopeResolver $scopeResolver;

	/**
	 * Extension verbs and the inheritable flag of a share; stateless, so it is built in place.
	 *
	 * @var ShareGrantAttributes
	 */
	private readonly ShareGrantAttributes $attributes;

	/**
	 * Constructor.
	 *
	 * @param GrantReportSources $sources   The access report, the object's shares, its ancestors' shares and the rule fit.
	 * @param PrincipalDirectory $directory User and group display names.
	 */
	public function __construct(
		private readonly GrantReportSources $sources,
		private readonly PrincipalDirectory $directory,
	) {
		$this->scopeResolver = new ObjectScopeResolver();
		$this->attributes = new ShareGrantAttributes();
	}//end __construct()

	/**
	 * The grant rows of one object.
	 *
	 * @param ObjectEntity   $object The object, already resolved and gated.
	 * @param GrantRowsQuery $query  The register and schema as the request named them, and the principal types to keep.
	 *
	 * @return array{object: string|null, results: array<int, array<string, mixed>>, denyEnforcement: string}
	 *         The rows.
	 *
	 * @throws \RuntimeException When a share source cannot be read.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function rowsFor(ObjectEntity $object, GrantRowsQuery $query): array {
		$set = $this->sources->accessReport->setFor(object: $object, table: $query->table);

		return [
			'object' => $object->getUuid(),
			'results' => $this->named(rows: $this->rowSetFor(object: $object, set: $set)->rows(), principalTypes: $query->principalTypes),
			'denyEnforcement' => $set['denyEnforcement'],
		];
	}//end rowsFor()

	/**
	 * Every grant row of one object, folded, with what an enforcing deny removes taken out.
	 *
	 * @param ObjectEntity         $object The object.
	 * @param array<string, mixed> $set    The holders, the denied rules and the deny mode, as `/permissions` reports them.
	 *
	 * @return GrantRowSet The rows.
	 */
	private function rowSetFor(ObjectEntity $object, array $set): GrantRowSet {
		$rows = new GrantRowSet(envelope: $object->getMatchEnvelope());
		$this->ownerRows(object: $object, rows: $rows);
		$this->sharedRows(object: $object, rows: $rows);
		$this->inheritedRows(object: $object, rows: $rows);
		$this->ruleRows(holders: $set['holders'], rows: $rows);

		if ($set['denyEnforcement'] === DenyEnforcementMode::MODE_ENFORCING) {
			$this->withoutDenied(rows: $rows, denied: $set['denied']);
		}

		return $rows;
	}//end rowSetFor()

	/**
	 * The rows of the asked principal types, each with its principal's display name.
	 *
	 * @param array<string, array<string, mixed>> $rows           The rows.
	 * @param string[]                            $principalTypes Keep only these principal types; empty keeps all.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function named(array $rows, array $principalTypes): array {
		$results = [];
		foreach ($rows as $row) {
			if ($principalTypes !== [] && in_array($row['principal']['type'], $principalTypes, true) === false) {
				continue;
			}

			$row['principal']['displayName'] = $this->displayNameOf(
				type: $row['principal']['type'],
				principalId: $row['principal']['id']
			);
			$results[] = $row;
		}

		return $results;
	}//end named()

	/**
	 * The owner and the owning group.
	 *
	 * @param ObjectEntity $object The object.
	 * @param GrantRowSet  $rows   The rows so far.
	 *
	 * @return void
	 */
	private function ownerRows(ObjectEntity $object, GrantRowSet $rows): void {
		$verbs = array_keys(PermissionCatalogue::CANONICAL);

		$owner = $object->getOwner();
		if (is_string($owner) === true && $owner !== '') {
			$rows->fold(row: $this->row(origin: self::ORIGIN_OWNER, holder: new GrantHolder(type: 'user', principalId: $owner, verbs: $verbs)));
		}

		$ownerGroup = $this->scopeResolver->ownerGroup(authorization: $object->getAuthorization());
		if ($ownerGroup !== null) {
			$holder = new GrantHolder(type: 'group', principalId: $ownerGroup, verbs: $verbs);
			$rows->fold(row: $this->row(origin: self::ORIGIN_OWNER, holder: $holder));
		}
	}//end ownerRows()

	/**
	 * The shares on the object's own folder, link shares left out.
	 *
	 * @param ObjectEntity $object The object.
	 * @param GrantRowSet  $rows   The rows so far.
	 *
	 * @return void
	 */
	private function sharedRows(ObjectEntity $object, GrantRowSet $rows): void {
		$found = $this->sources->shares->completeSharesOn(object: $object, sharers: [(string)$object->getOwner()]);
		foreach ($found as $share) {
			if ($share['type'] === 'link') {
				continue;
			}

			$rows->fold(row: $this->shareRow(origin: self::ORIGIN_SHARED, found: $share));
		}
	}//end sharedRows()

	/**
	 * The shares an ancestor confers, link shares and local-only shares left out.
	 *
	 * @param ObjectEntity $object The object.
	 * @param GrantRowSet  $rows   The rows so far.
	 *
	 * @return void
	 */
	private function inheritedRows(ObjectEntity $object, GrantRowSet $rows): void {
		foreach ($this->sources->inherited->inheritedSharesFor(object: $object, complete: true) as $found) {
			if ($found['type'] === 'link' || $this->attributes->inheritableOf(share: $found['share']) === false) {
				continue;
			}

			$row = $this->shareRow(origin: self::ORIGIN_INHERITED, found: $found);
			$row['inheritedFrom'] = [
				'uuid' => $found['ancestor']->getUuid(),
				'register' => $found['ancestor']->getRegister(),
				'schema' => $found['ancestor']->getSchema(),
			];
			$rows->fold(row: $row);
		}
	}//end inheritedRows()

	/**
	 * The rules that reach this record, conditional ones evaluated against it.
	 *
	 * @param array<int, array<string, mixed>> $holders The holders `/permissions` reports.
	 * @param GrantRowSet                      $rows    The rows so far, and the record as the matcher reads it.
	 *
	 * @return void
	 */
	private function ruleRows(array $holders, GrantRowSet $rows): void {
		foreach ($holders as $holder) {
			foreach (($holder['rules'] ?? []) as $rule) {
				$fit = $this->sources->rules->fitOf(rule: new HeldRule(entry: $rule['rule'], role: $rule['role']), envelope: $rows->envelope);
				if ($fit === null) {
					continue;
				}

				[$type, $principalId] = $this->sources->rules->principalOf(principal: (string)$rule['principal'], entry: $rule['rule']);
				$row = $this->row(origin: self::ORIGIN_RULE, holder: new GrantHolder(type: $type, principalId: $principalId, verbs: [(string)$rule['action']]));
				$row['level'] = (string)$rule['level'];
				$row['role'] = $rule['role'];
				$row['matchedOn'] = $fit['matchedOn'];
				$row['relative'] = $fit['relative'];
				$rows->fold(row: $row);
			}
		}
	}//end ruleRows()

	/**
	 * Take the verbs an enforcing deny removes out of the rows, and drop rows left empty.
	 *
	 * A deny is matched to rows by its principal. A deny whose condition does
	 * not fit this record removes nothing; one that reads the caller cannot be
	 * known to apply and removes nothing either.
	 *
	 * @param GrantRowSet                      $rows   The rows, and the record as the matcher reads it.
	 * @param array<int, array<string, mixed>> $denied The deny rules `/permissions` reports.
	 *
	 * @return void
	 */
	private function withoutDenied(GrantRowSet $rows, array $denied): void {
		foreach ($denied as $deny) {
			$fit = $this->sources->rules->fitOf(rule: new HeldRule(entry: $deny['rule'], role: $deny['role']), envelope: $rows->envelope);
			if ($fit === null || $fit['relative'] === true) {
				continue;
			}

			$rows->strip(
				principal: $this->sources->rules->principalOf(principal: (string)$deny['principal'], entry: $deny['rule']),
				action: (string)$deny['action']
			);
		}
	}//end withoutDenied()

	/**
	 * One row for one share.
	 *
	 * @param string                                $origin `shared` or `inherited`.
	 * @param array{type: string, share: IShare}    $found  The share's type label and the share, as its reader found it.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function shareRow(string $origin, array $found): array {
		$share = $found['share'];
		$verbs = [];
		foreach (self::SHARE_BIT_VERBS as $verb) {
			$bit = (int)PermissionBit::forAction(action: $verb);
			if (($share->getPermissions() & $bit) === $bit) {
				$verbs[] = $verb;
			}
		}

		$row = $this->row(
			origin: $origin,
			holder: new GrantHolder(
				type: $found['type'],
				principalId: (string)$share->getSharedWith(),
				verbs: array_values(array_unique(array_merge($verbs, $this->attributes->verbsOf(share: $share))))
			)
		);
		$row['id'] = $origin . ':' . $share->getFullId();
		$sharer = (string)$share->getSharedBy();
		$row['sharedBy'] = ['id' => $sharer, 'displayName' => $this->directory->userManager->getDisplayName($sharer)];
		$row['expiresAt'] = $share->getExpirationDate()?->format('c');
		$row['sharedAt'] = $share->getShareTime()?->format('c');

		return $row;
	}//end shareRow()

	/**
	 * A row with every field set to its empty value.
	 *
	 * @param string      $origin The origin.
	 * @param GrantHolder $holder The principal type, the principal id and the verbs.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $origin, GrantHolder $holder): array {
		$type = $holder->type;
		$principalId = $holder->principalId;
		return [
			'id' => $origin . ':' . $type . ':' . $principalId,
			'principal' => ['type' => $type, 'id' => $principalId, 'displayName' => null],
			'verbs' => $holder->verbs,
			'origin' => $origin,
			'level' => 'object',
			'role' => null,
			'matchedOn' => [],
			'relative' => false,
			'sharedBy' => null,
			'inheritedFrom' => null,
			'expiresAt' => null,
			'sharedAt' => null,
		];
	}//end row()


	/**
	 * The name a principal is shown by.
	 *
	 * A user or group that no longer exists keeps its id and gets null. A
	 * remote or e-mail principal is shown by its address; a pseudo principal
	 * has no name.
	 *
	 * @param string $type        The principal type.
	 * @param string $principalId The principal id.
	 *
	 * @return string|null The display name.
	 */
	private function displayNameOf(string $type, string $principalId): ?string {
		return match ($type) {
			'user' => $this->directory->userManager->getDisplayName($principalId),
			'group' => $this->directory->groupManager->get($principalId)?->getDisplayName(),
			'remote', 'remote_group', 'email' => $principalId,
			default => null,
		};
	}//end displayNameOf()
}//end class
