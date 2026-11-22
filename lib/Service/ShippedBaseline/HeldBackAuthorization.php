<?php

/**
 * The shipped authorization blocks an unattended upgrade holds back.
 *
 * A block both the app and the instance changed is a conflict: the upgrade
 * keeps the instance's block and waits for an administrator. Nothing else
 * says so, so a shipped read rule can sit unapplied while every listing calls
 * the register current. This reads the same three-way merge an upgrade runs,
 * without writing anything, and names the blocks it would hold back.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ShippedBaseline
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ShippedBaseline;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Configuration\ShippedSchemaBaseline;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Names the authorization blocks of a shipped schema that wait for an administrator.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) LiveDescriptor is the one shared reading of a live schema, a pure function.
 *
 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
 */
class HeldBackAuthorization {

	/**
	 * Constructor.
	 *
	 * @param ShippedBaselineStore  $baselines  Where the shipped baselines are kept.
	 * @param SchemaMapperInterface $schemas    Where the live schema is read.
	 * @param DivergenceComparator  $comparator The four states.
	 * @param DescriptorParts       $parts      The flattener.
	 * @param LoggerInterface       $logger     The logger.
	 */
	public function __construct(
		private readonly ShippedBaselineStore $baselines,
		private readonly SchemaMapperInterface $schemas,
		private readonly DivergenceComparator $comparator,
		private readonly DescriptorParts $parts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The authorization block roots an upgrade to this shipped definition would hold back.
	 *
	 * None without a baseline (the first release is imported whole) or
	 * without the schema on this instance.
	 *
	 * @param string               $slug    The schema slug.
	 * @param array<string, mixed> $shipped The schema definition the app now ships.
	 *
	 * @return array<int, string> The block roots, such as `authorization`.
	 *
	 * @spec openspec/changes/local-changes-to-app-shipped-configuration/specs/schema-import/spec.md
	 */
	public function roots(string $slug, array $shipped): array {
		if ($slug === '') {
			return [];
		}

		$baseline = $this->baselines->read(subject: $this->baselines->schemaSubject(slug: $slug));
		if ($this->comparator->hasBaseline(baseline: ($baseline['definition'] ?? null)) === false) {
			return [];
		}

		$live = $this->liveOf(slug: $slug);
		if ($live === null) {
			return [];
		}

		$merge = new GuardedDescriptorMerge(parts: $this->parts, comparator: $this->comparator);
		$result = $merge->mergeRevisions(
			revisions: new DescriptorRevisions(
				baseline: self::guarded(definition: $baseline['definition']),
				live: self::guarded(definition: $live),
				incoming: self::guarded(definition: $shipped)
			)
		);

		$blocks = new AuthorizationBlocks(parts: $this->parts);
		$roots = [];
		foreach ($result['conflicts'] as $conflict) {
			$path = (string)$conflict['path'];
			if ($blocks->rootOf(path: $path) === $path) {
				$roots[] = $path;
			}
		}

		return $roots;
	}//end roots()

	/**
	 * The parts of a definition an upgrade guards, beside the annotations.
	 *
	 * @param array<string, mixed> $definition The definition.
	 *
	 * @return array<string, mixed> The guarded parts it carries.
	 */
	private static function guarded(array $definition): array {
		return array_intersect_key($definition, array_flip(ShippedSchemaBaseline::GUARDED_PARTS));
	}//end guarded()

	/**
	 * The live definition of a schema, or null when it is not on this instance or cannot be read.
	 *
	 * @param string $slug The schema slug.
	 *
	 * @return array<string, mixed>|null The live definition.
	 */
	private function liveOf(string $slug): ?array {
		try {
			return LiveDescriptor::of(schema: $this->schemas->find(id: $slug, _rbac: false, _multitenancy: false));
		} catch (DoesNotExistException $e) {
			return null;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[HeldBackAuthorization] ' . $slug . ': schema unreadable, held-back access rules not checked: ' . $e->getMessage()
			);
			return null;
		}
	}//end liveOf()
}//end class
