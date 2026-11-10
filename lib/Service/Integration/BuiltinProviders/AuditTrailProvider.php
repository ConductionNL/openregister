<?php

/**
 * AuditTrailProvider — exposes audit-trail entries for an OR object
 * via the IntegrationProvider contract.
 *
 * Storage strategy is `query-time` (AD-22): audit-trail entries are
 * OR's own data; the provider always reads live, through the object's
 * history for the caller, rather than maintaining a parallel link table. Mutation methods
 * throw NotImplementedException — audit-trail entries are immutable
 * by construction.
 *
 * Always available — no required NC app — so `requiredApp` returns
 * null and `isEnabled()` is hardcoded true.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration\BuiltinProviders
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-16
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Integration\BuiltinProviders;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Audit\ObjectHistoryForReaders;
use OCA\OpenRegister\Service\Integration\AbstractIntegrationProvider;
use OCP\IL10N;
use Throwable;

/**
 * Audit-trail integration provider (read-only, query-time).
 */
class AuditTrailProvider extends AbstractIntegrationProvider {
	/**
	 * Constructor.
	 *
	 * @param ObjectHistoryForReaders $history The object's history for the caller.
	 * @param IL10N $l10n Localisation.
	 *
	 * @return void
	 */
	public function __construct(
		private ObjectHistoryForReaders $history,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Stable provider id used in routes and configs.
	 *
	 * @return string Stable provider identifier.
	 */
	public function getId(): string {
		return 'audit-trail';
	}//end getId()

	/**
	 * Translated, human-readable provider label.
	 *
	 * @return string Translated, human-readable provider label.
	 */
	public function getLabel(): string {
		return $this->l10n->t('Audit trail');
	}//end getLabel()

	/**
	 * MDI icon name for the provider.
	 *
	 * @return string MDI icon name for the provider.
	 */
	public function getIcon(): string {
		return 'History';
	}//end getIcon()

	/**
	 * Group identifier for UI grouping (or null).
	 *
	 * @return string|null Group identifier for UI grouping.
	 */
	public function getGroup(): ?string {
		return 'core';
	}//end getGroup()

	/**
	 * Required NC app id (null = built-in).
	 *
	 * @return string|null Required app id (null = built-in).
	 */
	public function getRequiredApp(): ?string {
		return null;
	}//end getRequiredApp()

	/**
	 * Storage strategy hint for the registry.
	 *
	 * @return string Storage strategy hint for the registry.
	 */
	public function getStorageStrategy(): string {
		return 'query-time';
	}//end getStorageStrategy()

	/**
	 * True when the provider is available for use.
	 *
	 * @return bool True when the provider is available for use.
	 */
	public function isEnabled(): bool {
		return true;
	}//end isEnabled()

	/**
	 * The object's audit-trail entries, as its history gives them to this caller.
	 *
	 * Read through the reader path of the object's history, so the object is
	 * read with the caller's RBAC and organisation, the doelbinding guard runs,
	 * the properties the caller may not see are removed from each entry, and
	 * `session`, `request` and `ipAddress` are withheld. A caller who may not
	 * read the object, a refused purpose or an ambiguous identifier gets no
	 * rows: the history is a soft surface and never blocks the detail page.
	 *
	 * @param string $register Register slug or numeric id.
	 * @param string $schema Schema slug or numeric id.
	 * @param string $objectId Object uuid.
	 * @param array<string,mixed> $filters Reserved.
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $filters is part of the IntegrationProvider contract.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-history-of-one-object-is-readable-by-whoever-may-read-that-object
	 */
	public function list(string $register, string $schema, string $objectId, array $filters = []): array {
		return $this->historyOf(ref: new ObjectRef(register: $register, schema: $schema, id: $objectId));
	}//end list()

	/**
	 * The object's audit entries as this caller may read them; none when the history cannot be read.
	 *
	 * @param ObjectRef $ref The object, by the register and schema path it was listed under.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function historyOf(ObjectRef $ref): array {
		try {
			$page = $this->history->page(
				ref: $ref,
				params: ['limit' => PHP_INT_MAX]
			);
		} catch (Throwable $e) {
			return [];
		}

		if ($page === null) {
			return [];
		}

		return array_values($page['results']);
	}//end historyOf()
}//end class
