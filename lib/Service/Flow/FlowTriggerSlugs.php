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
 * @spec openspec/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place an id, uuid or slug becomes the slug triggers match on.
 *
 * @spec openspec/specs/flow-engine/spec.md
 */
class FlowTriggerSlugs implements FlowTriggerSlugsInterface {

	/**
	 * Constructor.
	 *
	 * The mappers memoise `find()` per request, so resolving the same
	 * register/schema for several triggers in one dispatch costs one read.
	 *
	 * @param SchemaCatalogInterface $catalog Resolves a register or schema by id, uuid or slug.
	 * @param LoggerInterface        $logger  Records identifiers that resolve to nothing.
	 */
	public function __construct(
		private readonly SchemaCatalogInterface $catalog,
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function registerSlug(string $identifier): string {
		return $this->slugOf(identifier: $identifier, kind: 'register');
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
	 * @spec openspec/specs/flow-engine/spec.md
	 */
	public function schemaSlug(string $identifier): string {
		return $this->slugOf(identifier: $identifier, kind: 'schema');
	}//end schemaSlug()

	/**
	 * The slug of a register or schema identifier, or the identifier itself when it has none or cannot be read.
	 *
	 * @param string $identifier The id, uuid or slug as written.
	 * @param string $kind       `register` or `schema`.
	 *
	 * @return string The slug, the identifier, or '' for a blank one.
	 */
	private function slugOf(string $identifier, string $kind): string {
		$identifier = trim($identifier);
		if ($identifier === '') {
			return '';
		}

		$slug = $this->storedSlug(identifier: $identifier, kind: $kind);
		if ($slug === null || $slug === '') {
			return $identifier;
		}

		return $slug;
	}//end slugOf()

	/**
	 * The stored slug of the entity the identifier names, or null when it cannot be read.
	 *
	 * @param string $identifier The trimmed identifier.
	 * @param string $kind       `register` or `schema`.
	 *
	 * @return string|null The trimmed slug, possibly empty.
	 */
	private function storedSlug(string $identifier, string $kind): ?string {
		try {
			return trim((string)$this->findEntity(identifier: $identifier, kind: $kind)->getSlug());
		} catch (Throwable $e) {
			$this->reportUnresolved(what: $kind . ' identifier "' . $identifier . '"', error: $e);

			return null;
		}
	}//end storedSlug()

	/**
	 * The register or schema the identifier names, read as the system.
	 *
	 * @param string $identifier The trimmed identifier.
	 * @param string $kind       `register` or `schema`.
	 *
	 * @return Register|Schema The entity.
	 */
	private function findEntity(string $identifier, string $kind): Register|Schema {
		if ($kind === 'register') {
			return $this->catalog->registers()->find(id: $identifier, _rbac: false, _multitenancy: false);
		}

		return $this->catalog->schemas()->find(id: $identifier, _rbac: false, _multitenancy: false);
	}//end findEntity()

	/**
	 * Say out loud that an identifier resolved to nothing.
	 *
	 * Debug level, deliberately: an identifier that is ALREADY a slug of a
	 * since-deleted register still has to pass through unchanged, and warning
	 * on every event for it would drown the log without changing the outcome.
	 *
	 * @param string    $what  Which catalogue was asked, and for what: `register identifier "x"`.
	 * @param Throwable $error Why it did not resolve.
	 *
	 * @return void
	 */
	private function reportUnresolved(string $what, Throwable $error): void {
		$this->logger->debug(
			message: '[FlowTriggerSlugs] The ' . $what . ' did not resolve to a slug and is matched as-is: ' . $error->getMessage(),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);

	}//end reportUnresolved()
}//end class
