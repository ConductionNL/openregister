<?php

/**
 * OpenRegister dedup-collided-schemas command
 *
 * Backwards remediation for the cross-app schema-slug collision (#2150).
 *
 * The per-register slug-uniqueness fix is PREVENTIVE only — its own design
 * states "no data dedup, cannot fail on existing rows". Every collision that
 * predates it therefore persists: two or more registers keep pointing at one
 * shared schema row, and whichever app imported last owns its shape.
 *
 * Observed 2026-07-28: schema #21 `skill` carries `application=pipelinq` and
 * declares `required: ["title"]`, yet register #8 `larpingapp` also points at
 * it while larpingapp's own register JSON declares `required: ["name"]`. Every
 * larpingapp skill create therefore failed with
 * HTTP 400 "The required property (title) is missing" — the app was broken for
 * users, not merely for tests.
 *
 * This command splits such rows: each non-owning register gets its OWN copy of
 * the schema and is repointed at it. Object data needs no row migration because
 * storage is already per register+schema (`oc_openregister_table_<reg>_<schema>`),
 * so the table is simply renamed to follow the new schema id.
 *
 * Ownership rule (in order):
 *   1. the register whose `application` equals the schema's `application`;
 *   2. failing that, the register with the lowest id (the original creator).
 *
 * Dry-run by default. Pass --apply to write.
 *
 * @category Command
 * @package  OCA\OpenRegister\Command
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec exclude Backwards data-remediation tool for the #2150 collision class; no user-facing capability spec.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Command;

use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Split schemas that more than one register points at.
 */
class DedupCollidedSchemasCommand extends Command
{

    /**
     * Constructor.
     *
     * @param IDBConnection $db Database connection.
     */
    public function __construct(private readonly IDBConnection $db)
    {
        parent::__construct();

    }//end __construct()


    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('openregister:schemas:dedup')
            ->setDescription('Split schemas shared by multiple registers so each register owns its own copy (#2150 backwards fix)')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually write changes. Without this the command only reports.')
            ->addOption('register', null, InputOption::VALUE_REQUIRED, 'Limit to a single register slug.')
            ->addOption('schema', null, InputOption::VALUE_REQUIRED, 'Limit to a single schema id.');

    }//end configure()


    /**
     * Find every schema referenced by more than one register.
     *
     * @param string|null $registerSlug Optional register slug filter.
     * @param string|null $schemaId     Optional schema id filter.
     *
     * @return array<int, array<string, mixed>> Collision rows.
     */
    private function findCollisions(?string $registerSlug, ?string $schemaId): array
    {
        // `schemas` is a JSON array that may hold ids as numbers OR strings,
        // so containment is checked against both shapes. A LIKE/substring
        // match would be wrong here — '21' also matches '210' and '121',
        // which inflates the collision count dramatically.
        $sql = 'SELECT s.id AS schema_id, s.slug AS schema_slug, s.application AS schema_app,
                       r.id AS register_id, r.slug AS register_slug, r.application AS register_app
                  FROM oc_openregister_schemas s
                  JOIN oc_openregister_registers r
                    ON (r.schemas::jsonb @> to_jsonb(s.id::text) OR r.schemas::jsonb @> to_jsonb(s.id))
                 WHERE s.id IN (
                       SELECT s2.id FROM oc_openregister_schemas s2
                        JOIN oc_openregister_registers r2
                          ON (r2.schemas::jsonb @> to_jsonb(s2.id::text) OR r2.schemas::jsonb @> to_jsonb(s2.id))
                        GROUP BY s2.id HAVING COUNT(*) > 1)';
        $params = [];
        if ($schemaId !== null) {
            $sql          .= ' AND s.id = :sid';
            $params['sid'] = (int) $schemaId;
        }

        $sql .= ' ORDER BY s.id, r.id';

        $rows = $this->db->executeQuery($sql, $params)->fetchAll();

        if ($registerSlug === null) {
            return $rows;
        }

        // Keep whole collision groups that involve the requested register, so
        // the owner is still visible in the report.
        $keep = [];
        foreach ($rows as $row) {
            if ($row['register_slug'] === $registerSlug) {
                $keep[$row['schema_id']] = true;
            }
        }

        return array_values(
            array_filter($rows, static fn (array $r): bool => isset($keep[$r['schema_id']]) === true)
        );

    }//end findCollisions()


    /**
     * Pick the register that keeps the original schema row.
     *
     * @param array<int, array<string, mixed>> $group All registers referencing one schema.
     *
     * @return array<string, mixed> The owning register row.
     */
    private function pickOwner(array $group): array
    {
        $schemaApp = ($group[0]['schema_app'] ?? null);
        if (empty($schemaApp) === false) {
            foreach ($group as $row) {
                if ($row['register_app'] === $schemaApp) {
                    return $row;
                }
            }
        }

        // Fallback: the oldest register wins.
        usort($group, static fn (array $a, array $b): int => ((int) $a['register_id'] <=> (int) $b['register_id']));

        return $group[0];

    }//end pickOwner()


    /**
     * Clone a schema row, repoint the register at the clone, and move its table.
     *
     * @param int    $schemaId    Shared schema id.
     * @param int    $registerId  Register that must stop sharing.
     * @param string $registerApp Application owning that register.
     *
     * @return int The new schema id.
     */
    private function splitOne(int $schemaId, int $registerId, ?string $registerApp): int
    {
        $newUuid = sprintf(
            '%s-%s',
            substr(bin2hex(random_bytes(8)), 0, 8),
            substr(bin2hex(random_bytes(12)), 0, 24)
        );

        // All three steps are one unit: an earlier revision let the clone
        // INSERT commit while the repoint UPDATE failed on a type error,
        // leaving orphaned schema rows referenced by nobody. Either the
        // register ends up owning a clone, or nothing changes.
        $this->db->beginTransaction();
        try {
            $newId = $this->splitOneLocked($schemaId, $registerId, $registerApp, $newUuid);
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $newId;

    }//end splitOne()


    /**
     * Perform the split inside an open transaction.
     *
     * @param int         $schemaId    Shared schema id.
     * @param int         $registerId  Register that must stop sharing.
     * @param string|null $registerApp Application owning that register.
     * @param string      $newUuid     Uuid for the clone.
     *
     * @return int The new schema id.
     */
    private function splitOneLocked(int $schemaId, int $registerId, ?string $registerApp, string $newUuid): int
    {
        // 1. Clone every column except the identity ones.
        $this->db->executeStatement(
            'INSERT INTO oc_openregister_schemas
             (uuid, title, slug, description, version, summary, required, properties,
              application, organisation, owner, created, updated)
             SELECT :uuid, title, slug, description, version, summary, required, properties,
                    :app, organisation, owner, NOW(), NOW()
               FROM oc_openregister_schemas WHERE id = :sid',
            ['uuid' => $newUuid, 'app' => $registerApp, 'sid' => $schemaId]
        );

        $newId = (int) $this->db->lastInsertId('oc_openregister_schemas');

        // 2. Repoint the register: replace the shared id with the clone, in
        //    whichever shape (string or number) the array happens to use.
        $this->db->executeStatement(
            // Every parameter is CAST explicitly: Postgres cannot infer the
            // type of a bare placeholder inside to_jsonb() and fails with
            // "could not determine polymorphic type because input has type
            // unknown".
            "UPDATE oc_openregister_registers
                SET schemas = (
                    SELECT jsonb_agg(
                        CASE WHEN elem::text IN (CAST(:sidTxt AS text), CAST(:sidQuoted AS text))
                             THEN to_jsonb(CAST(:newIdTxt AS text))
                             ELSE elem END)
                      FROM jsonb_array_elements(schemas::jsonb) AS elem)
              WHERE id = :rid",
            [
                'sidTxt'    => (string) $schemaId,
                'sidQuoted' => '"'.$schemaId.'"',
                'newIdTxt'  => (string) $newId,
                'rid'       => $registerId,
            ]
        );

        // 3. Object storage is already per register+schema, so the rows only
        //    need the table to follow the new schema id — no row migration.
        $old = 'oc_openregister_table_'.$registerId.'_'.$schemaId;
        $new = 'oc_openregister_table_'.$registerId.'_'.$newId;
        $exists = $this->db->executeQuery(
            'SELECT 1 FROM pg_tables WHERE schemaname = current_schema() AND tablename = :t',
            ['t' => $old]
        )->fetchOne();
        if ($exists !== false) {
            $this->db->executeStatement(sprintf('ALTER TABLE %s RENAME TO %s', $old, $new));

            // The rename alone is NOT enough: every row still carries the OLD
            // schema id in its `_schema` column, so anything filtering by
            // schema (queries, exports, RBAC scoping) would miss them or
            // attribute them to the register that kept the original. Verified
            // on the larpingapp split — 139 rows sat at `_schema = 21` inside
            // `oc_openregister_table_8_5440` until this ran.
            $this->db->executeStatement(
                sprintf('UPDATE %s SET _schema = :new WHERE _schema = :old', $new),
                ['new' => (string) $newId, 'old' => (string) $schemaId]
            );
        }

        return $newId;

    }//end splitOne()


    /**
     * Execute the command.
     *
     * @param InputInterface  $input  Console input.
     * @param OutputInterface $output Console output.
     *
     * @return int Exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $apply    = (bool) $input->getOption('apply');
        $register = $input->getOption('register');
        $schema   = $input->getOption('schema');

        $rows = $this->findCollisions($register, $schema);
        if (empty($rows) === true) {
            $output->writeln('<info>No collided schemas found.</info>');
            return 0;
        }

        $groups = [];
        foreach ($rows as $row) {
            $groups[(int) $row['schema_id']][] = $row;
        }

        $output->writeln(sprintf('Found <comment>%d</comment> schema(s) shared by more than one register.', count($groups)));
        if ($apply === false) {
            $output->writeln('<comment>DRY RUN</comment> — pass --apply to write. Nothing has been changed.');
        }

        $split  = 0;
        $failed = 0;
        foreach ($groups as $schemaId => $group) {
            $owner = $this->pickOwner($group);
            $output->writeln(
                sprintf(
                    "\nschema #%d <info>%s</info> (application=%s) — owner: register #%s %s",
                    $schemaId,
                    $group[0]['schema_slug'],
                    ($group[0]['schema_app'] ?? '-'),
                    $owner['register_id'],
                    $owner['register_slug']
                )
            );

            foreach ($group as $row) {
                if ((int) $row['register_id'] === (int) $owner['register_id']) {
                    continue;
                }

                if ($apply === false) {
                    $output->writeln(sprintf('    would split -> register #%s %s', $row['register_id'], $row['register_slug']));
                    $split++;
                    continue;
                }

                try {
                    $newId = $this->splitOne((int) $schemaId, (int) $row['register_id'], $row['register_app']);
                    $output->writeln(
                        sprintf('    <info>split</info> -> register #%s %s now owns schema #%d', $row['register_id'], $row['register_slug'], $newId)
                    );
                    $split++;
                } catch (Throwable $e) {
                    $output->writeln(sprintf('    <error>failed</error> register #%s: %s', $row['register_id'], $e->getMessage()));
                    $failed++;
                }
            }//end foreach
        }//end foreach

        $output->writeln(sprintf("\n%s %d register/schema pair(s); %d failure(s).", ($apply === true ? 'Split' : 'Would split'), $split, $failed));

        return ($failed === 0 ? 0 : 1);

    }//end execute()


}//end class
