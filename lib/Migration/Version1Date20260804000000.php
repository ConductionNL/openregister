<?php

/**
 * Rewrites the renamed `openregister.loop` node id in stored flow definitions.
 *
 * The node never looped — it splits items into fixed-size batches — and sitting
 * next to the real `openregister.iterate` the old name was a trap that re-armed
 * for every new reader. So it became `openregister.batch`.
 *
 * A node id is a reference the SYSTEM writes into a flow definition, which is
 * what makes correcting it different from correcting a Twig function name: a
 * template is typed by a person and cannot be safely rewritten, a flow's node
 * list is a JSON structure we own end to end. So the id is fixed and the data
 * migrated, rather than the wrong name being kept forever.
 *
 * The registry keeps a logged alias for one release, covering the one case this
 * migration cannot reach: a flow exported before the rename and imported after.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migrates `openregister.loop` to `openregister.batch` in stored flows.
 */
class Version1Date20260804000000 extends SimpleMigrationStep {

	/**
	 * The old node id.
	 *
	 * @var string
	 */
	private const OLD_ID = 'openregister.loop';

	/**
	 * The corrected node id.
	 *
	 * @var string
	 */
	private const NEW_ID = 'openregister.batch';

	/**
	 * The database connection.
	 *
	 * @var IDBConnection
	 */
	private IDBConnection $db;

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db The database connection.
	 */
	public function __construct(IDBConnection $db) {
		$this->db = $db;

	}//end __construct()

	/**
	 * Rewrite the node id in every stored flow definition.
	 *
	 * Operates on the `nodes` and `edges` JSON as TEXT, matching the quoted id
	 * exactly. A structural walk would be more elegant and is not worth it here:
	 * the id appears only as a JSON string value, an exact quoted match cannot
	 * hit a prefix (`openregister.loopback` would not match `"openregister.loop"`),
	 * and decoding/re-encoding every definition risks reordering keys or losing
	 * numeric precision in flows this migration has no business touching.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array $options Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-sync-decomposition/specs/flow-iteration/spec.md
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_flows') === false) {
			$output->info('No flow table yet; nothing to rename.');
			return;
		}

		$needle = '"' . self::OLD_ID . '"';
		$replacement = '"' . self::NEW_ID . '"';
		$touched = 0;

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'nodes', 'edges')
			->from('openregister_flows');

		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();

		foreach ($rows as $row) {
			$nodes = (string)($row['nodes'] ?? '');
			$edges = (string)($row['edges'] ?? '');

			if (str_contains($nodes, $needle) === false && str_contains($edges, $needle) === false) {
				continue;
			}

			$update = $this->db->getQueryBuilder();
			$update->update('openregister_flows')
				->set('nodes', $update->createNamedParameter(str_replace($needle, $replacement, $nodes)))
				->set('edges', $update->createNamedParameter(str_replace($needle, $replacement, $edges)))
				->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'])));
			$update->executeStatement();

			$touched++;
			$output->info(
				sprintf('Flow "%s": %s -> %s', (string)($row['name'] ?? $row['id']), self::OLD_ID, self::NEW_ID)
			);
		}//end foreach

		$output->info(sprintf('Node rename: %d flow definition(s) updated.', $touched));

	}//end postSchemaChange()
}//end class
