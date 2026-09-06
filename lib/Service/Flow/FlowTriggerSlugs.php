<?php

/**
 * Resolves register and schema identifiers to the SLUGS trigger matching runs on.
 *
 * The trigger index and the flow trigger columns speak slugs: the index column
 * is literally `schema_slug`, an imported `x-openregister-flows` declaration
 * names its register and schema by slug, and every matcher docblock reads "the
 * register slug the event fired on". But an object event carries the object's
 * NUMERIC register and schema ids, and a canvas-authored trigger node may hold
 * whatever the builder put in its config — id, uuid or slug. Comparing those
 * literally is how three case creations on a clean instance queued nothing
 * while the flow sat enabled and owned: `16` never equals `dossiq`.
 *
 * This class is the ONE answer to "what does a trigger triple look like".
 * The listener passes every fired subject through it, and the index writer
 * passes every derived trigger through it, so both sides of the comparison
 * arrive in the same vocabulary whatever they started as. A value that does
 * not resolve is passed through unchanged rather than dropped — an
 * unresolvable identifier that silently became an empty string would
 * unsubscribe the flow, which is the exact silence this class exists to end.
 *
 * Resolving a slug is idempotent: `RegisterMapper::find()` and
 * `SchemaMapper::find()` accept an id, uuid or slug alike, and looking a slug
 * up returns that same slug. So callers never need to know which form they
 * hold, which is the point.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place an id, uuid or slug becomes the slug triggers match on.
 *
 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
 */
class FlowTriggerSlugs {

	/**
	 * Constructor.
	 *
	 * The mappers memoise `find()` per request, so resolving the same
	 * register/schema for several triggers in one dispatch costs one read.
	 *
	 * @param RegisterMapper $registers Resolves a register by id, uuid or slug.
	 * @param SchemaMapper $schemas Resolves a schema by id, uuid or slug.
	 * @param LoggerInterface $logger Records identifiers that resolve to nothing.
	 */
	public function __construct(
		private readonly RegisterMapper $registers,
		private readonly SchemaMapper $schemas,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * The slug of the register the given identifier names.
	 *
	 * Loads with RBAC and multitenancy OFF: this runs on the trigger path,
	 * inside the dispatch of whatever fired the event, where there may be no
	 * session — and a register's SLUG is its public name, not its data.
	 *
	 * @param string $identifier The register's id, uuid or slug.
	 *
	 * @return string The slug, or the trimmed identifier when it does not resolve.
	 *
	 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
	 */
	public function registerSlug(string $identifier): string {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return '';
		}

		try {
			$slug = trim((string)$this->registers->find(id: $identifier, _rbac: false, _multitenancy: false)->getSlug());
		} catch (Throwable $e) {
			$this->reportUnresolved(kind: 'register', identifier: $identifier, error: $e);

			return $identifier;
		}

		if ($slug === '') {
			return $identifier;
		}

		return $slug;
	}//end registerSlug()

	/**
	 * The slug of the schema the given identifier names.
	 *
	 * A bare schema slug is only unique within a register, but that is no
	 * hazard here: resolving a slug globally can only ever answer that same
	 * slug back, and ids and uuids are globally unique. Nothing is LOADED
	 * through the answer — it is a name, used for an equality comparison.
	 *
	 * @param string $identifier The schema's id, uuid or slug.
	 *
	 * @return string The slug, or the trimmed identifier when it does not resolve.
	 *
	 * @spec openspec/changes/flow-trigger-canonical-slugs/specs/flow-engine/spec.md
	 */
	public function schemaSlug(string $identifier): string {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return '';
		}

		try {
			$slug = trim((string)$this->schemas->find(id: $identifier, _rbac: false, _multitenancy: false)->getSlug());
		} catch (Throwable $e) {
			$this->reportUnresolved(kind: 'schema', identifier: $identifier, error: $e);

			return $identifier;
		}

		if ($slug === '') {
			return $identifier;
		}

		return $slug;
	}//end schemaSlug()

	/**
	 * Say out loud that an identifier resolved to nothing.
	 *
	 * Debug level, deliberately: an identifier that is ALREADY a slug of a
	 * since-deleted register still has to pass through unchanged, and warning
	 * on every event for it would drown the log without changing the outcome.
	 *
	 * @param string $kind Which catalogue was asked.
	 * @param string $identifier What was asked for.
	 * @param Throwable $error Why it did not resolve.
	 *
	 * @return void
	 */
	private function reportUnresolved(string $kind, string $identifier, Throwable $error): void {
		$this->logger->debug(
			message: '[FlowTriggerSlugs] The ' . $kind . ' identifier "' . $identifier
				. '" did not resolve to a slug and is matched as-is: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

	}//end reportUnresolved()
}//end class
