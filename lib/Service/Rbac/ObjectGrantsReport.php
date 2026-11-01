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
use OCA\OpenRegister\Support\PermissionBit;
use OCP\IGroupManager;
use OCP\IUserManager;
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
class ObjectGrantsReport {

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
	 * Constructor.
	 *
	 * @param ObjectAccessReport   $accessReport  The rules in play and the deny mode, as `/permissions` reads them.
	 * @param ObjectShareReader    $shares        The shares on the object's own folder.
	 * @param InheritedGrantLister $inherited     The shares on its ancestors' folders.
	 * @param GrantRuleFit         $rules         How a rule entry fits this record, and whom it names.
	 * @param ObjectScopeResolver  $scopeResolver The owning group.
	 * @param IUserManager         $userManager   User display names.
	 * @param IGroupManager        $groupManager  Group display names.
	 * @param ShareGrantAttributes $attributes    Extension verbs and the inheritable flag of a share.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor injection, one source per parameter.
	 */
	public function __construct(
		private readonly ObjectAccessReport $accessReport,
		private readonly ObjectShareReader $shares,
		private readonly InheritedGrantLister $inherited,
		private readonly GrantRuleFit $rules,
		private readonly ObjectScopeResolver $scopeResolver,
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
		private readonly ShareGrantAttributes $attributes = new ShareGrantAttributes(),
	) {
	}//end __construct()

	/**
	 * The grant rows of one object.
	 *
	 * @param ObjectEntity $object         The object, already resolved and gated.
	 * @param mixed        $registerRef    The register as the request named it.
	 * @param mixed        $schemaRef      The schema as the request named it.
	 * @param string[]     $principalTypes Keep only these principal types; empty keeps all.
	 *
	 * @return array{object: string|null, results: array<int, array<string, mixed>>, denyEnforcement: string}
	 *         The rows.
	 *
	 * @throws \RuntimeException When a share source cannot be read.
	 *
	 * @spec openspec/changes/an-object-lists-its-grants-with-their-origin/specs/rbac-scopes/spec.md
	 */
	public function rowsFor(ObjectEntity $object, mixed $registerRef, mixed $schemaRef, array $principalTypes = []): array {
		$set = $this->accessReport->setFor(object: $object, registerRef: $registerRef, schemaRef: $schemaRef);
		$envelope = $this->envelopeOf(object: $object);

		$rows = [];
		$rows = $this->ownerRows(object: $object, rows: $rows);
		$rows = $this->sharedRows(object: $object, rows: $rows);
		$rows = $this->inheritedRows(object: $object, rows: $rows);
		$rows = $this->ruleRows(holders: $set['holders'], envelope: $envelope, rows: $rows);

		if ($set['denyEnforcement'] === DenyEnforcementMode::MODE_ENFORCING) {
			$rows = $this->withoutDenied(rows: $rows, denied: $set['denied'], envelope: $envelope);
		}

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

		return [
			'object' => $object->getUuid(),
			'results' => $results,
			'denyEnforcement' => $set['denyEnforcement'],
		];
	}//end rowsFor()

	/**
	 * The owner and the owning group.
	 *
	 * @param ObjectEntity                        $object The object.
	 * @param array<string, array<string, mixed>> $rows   The rows so far.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function ownerRows(ObjectEntity $object, array $rows): array {
		$verbs = array_keys(PermissionCatalogue::CANONICAL);

		$owner = $object->getOwner();
		if (is_string($owner) === true && $owner !== '') {
			$rows = $this->fold(rows: $rows, row: $this->row(origin: self::ORIGIN_OWNER, type: 'user', principalId: $owner, verbs: $verbs));
		}

		$ownerGroup = $this->scopeResolver->ownerGroup(authorization: $object->getAuthorization());
		if ($ownerGroup !== null) {
			$rows = $this->fold(rows: $rows, row: $this->row(origin: self::ORIGIN_OWNER, type: 'group', principalId: $ownerGroup, verbs: $verbs));
		}

		return $rows;
	}//end ownerRows()

	/**
	 * The shares on the object's own folder, link shares left out.
	 *
	 * @param ObjectEntity                        $object The object.
	 * @param array<string, array<string, mixed>> $rows   The rows so far.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function sharedRows(ObjectEntity $object, array $rows): array {
		$found = $this->shares->sharesOn(object: $object, sharers: [(string)$object->getOwner()], complete: true);
		foreach ($found as $share) {
			if ($share['type'] === 'link') {
				continue;
			}

			$rows = $this->fold(rows: $rows, row: $this->shareRow(origin: self::ORIGIN_SHARED, type: $share['type'], share: $share['share']));
		}

		return $rows;
	}//end sharedRows()

	/**
	 * The shares an ancestor confers, link shares and local-only shares left out.
	 *
	 * @param ObjectEntity                        $object The object.
	 * @param array<string, array<string, mixed>> $rows   The rows so far.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function inheritedRows(ObjectEntity $object, array $rows): array {
		foreach ($this->inherited->inheritedSharesFor(object: $object, complete: true) as $found) {
			if ($found['type'] === 'link' || $this->attributes->inheritableOf(share: $found['share']) === false) {
				continue;
			}

			$row = $this->shareRow(origin: self::ORIGIN_INHERITED, type: $found['type'], share: $found['share']);
			$row['inheritedFrom'] = [
				'uuid' => $found['ancestor']->getUuid(),
				'register' => $found['ancestor']->getRegister(),
				'schema' => $found['ancestor']->getSchema(),
			];
			$rows = $this->fold(rows: $rows, row: $row);
		}

		return $rows;
	}//end inheritedRows()

	/**
	 * The rules that reach this record, conditional ones evaluated against it.
	 *
	 * @param array<int, array<string, mixed>>    $holders  The holders `/permissions` reports.
	 * @param array<string, mixed>                $envelope The record as the matcher reads it.
	 * @param array<string, array<string, mixed>> $rows     The rows so far.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function ruleRows(array $holders, array $envelope, array $rows): array {
		foreach ($holders as $holder) {
			foreach (($holder['rules'] ?? []) as $rule) {
				$fit = $this->rules->fitOf(entry: $rule['rule'], role: $rule['role'], envelope: $envelope);
				if ($fit === null) {
					continue;
				}

				[$type, $principalId] = $this->rules->principalOf(principal: (string)$rule['principal'], entry: $rule['rule']);
				$row = $this->row(origin: self::ORIGIN_RULE, type: $type, principalId: $principalId, verbs: [(string)$rule['action']]);
				$row['level'] = (string)$rule['level'];
				$row['role'] = $rule['role'];
				$row['matchedOn'] = $fit['matchedOn'];
				$row['relative'] = $fit['relative'];
				$rows = $this->fold(rows: $rows, row: $row);
			}
		}

		return $rows;
	}//end ruleRows()



	/**
	 * Take the verbs an enforcing deny removes out of the rows, and drop rows left empty.
	 *
	 * A deny is matched to rows by its principal. A deny whose condition does
	 * not fit this record removes nothing; one that reads the caller cannot be
	 * known to apply and removes nothing either.
	 *
	 * @param array<string, array<string, mixed>> $rows     The rows.
	 * @param array<int, array<string, mixed>>    $denied   The deny rules `/permissions` reports.
	 * @param array<string, mixed>                $envelope The record as the matcher reads it.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function withoutDenied(array $rows, array $denied, array $envelope): array {
		foreach ($denied as $deny) {
			$fit = $this->rules->fitOf(entry: $deny['rule'], role: $deny['role'], envelope: $envelope);
			if ($fit === null || $fit['relative'] === true) {
				continue;
			}

			[$type, $principalId] = $this->rules->principalOf(principal: (string)$deny['principal'], entry: $deny['rule']);
			foreach ($rows as $key => $row) {
				if ($row['principal']['type'] !== $type || $row['principal']['id'] !== $principalId) {
					continue;
				}

				$rows[$key]['verbs'] = array_values(array_diff($row['verbs'], [(string)$deny['action']]));
				if ($rows[$key]['verbs'] === []) {
					unset($rows[$key]);
				}
			}
		}

		return $rows;
	}//end withoutDenied()

	/**
	 * One row for one share.
	 *
	 * @param string $origin `shared` or `inherited`.
	 * @param string $type   The share's type label.
	 * @param IShare $share  The share.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function shareRow(string $origin, string $type, IShare $share): array {
		$verbs = [];
		foreach (self::SHARE_BIT_VERBS as $verb) {
			$bit = (int)PermissionBit::forAction(action: $verb);
			if (($share->getPermissions() & $bit) === $bit) {
				$verbs[] = $verb;
			}
		}

		$row = $this->row(
			origin: $origin,
			type: $type,
			principalId: (string)$share->getSharedWith(),
			verbs: array_values(array_unique(array_merge($verbs, $this->attributes->verbsOf(share: $share))))
		);
		$row['id'] = $origin . ':' . $share->getFullId();
		$sharer = (string)$share->getSharedBy();
		$row['sharedBy'] = ['id' => $sharer, 'displayName' => $this->userManager->getDisplayName($sharer)];
		$row['expiresAt'] = $share->getExpirationDate()?->format('c');
		$row['sharedAt'] = $share->getShareTime()?->format('c');

		return $row;
	}//end shareRow()

	/**
	 * A row with every field set to its empty value.
	 *
	 * @param string   $origin      The origin.
	 * @param string   $type        The principal type.
	 * @param string   $principalId The principal id.
	 * @param string[] $verbs       The verbs.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function row(string $origin, string $type, string $principalId, array $verbs): array {
		return [
			'id' => $origin . ':' . $type . ':' . $principalId,
			'principal' => ['type' => $type, 'id' => $principalId, 'displayName' => null],
			'verbs' => $verbs,
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
	 * Fold a row into the one with the same principal, origin, level, role and ancestor.
	 *
	 * The verbs and the matched properties are merged. A folded row is relative
	 * only when every entry behind it is.
	 *
	 * @param array<string, array<string, mixed>> $rows The rows so far.
	 * @param array<string, mixed>                $row  The row to fold in.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function fold(array $rows, array $row): array {
		$key = implode(
			"\0",
			[
				$row['origin'],
				$row['level'],
				(string)$row['role'],
				$row['principal']['type'],
				$row['principal']['id'],
				(string)($row['inheritedFrom']['uuid'] ?? ''),
			]
		);

		if ($row['origin'] === self::ORIGIN_RULE) {
			$parts = ['rule', $row['level'], $row['principal']['type'], $row['principal']['id'], $row['role']];
			$row['id'] = implode(':', array_filter($parts, static fn ($part) => $part !== null));
		}

		if (isset($rows[$key]) === false) {
			$rows[$key] = $row;
			return $rows;
		}

		$rows[$key]['verbs'] = array_values(array_unique(array_merge($rows[$key]['verbs'], $row['verbs'])));
		$rows[$key]['matchedOn'] = array_values(array_unique(array_merge($rows[$key]['matchedOn'], $row['matchedOn'])));
		$rows[$key]['relative'] = ($rows[$key]['relative'] === true && $row['relative'] === true);

		return $rows;
	}//end fold()


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
			'user' => $this->userManager->getDisplayName($principalId),
			'group' => $this->groupManager->get($principalId)?->getDisplayName(),
			'remote', 'remote_group', 'email' => $principalId,
			default => null,
		};
	}//end displayNameOf()

	/**
	 * The record as the single-object decision hands it to the matcher.
	 *
	 * @param ObjectEntity $object The record.
	 *
	 * @return array<string, mixed> The envelope.
	 */
	private function envelopeOf(ObjectEntity $object): array {
		$envelope = ($object->getObject() ?? []);
		$organisation = $object->getOrganisation();
		if ($organisation !== null) {
			$envelope['@self'] = (($envelope['@self'] ?? []) + ['organisation' => $organisation]);
		}

		return $envelope;
	}//end envelopeOf()
}//end class
