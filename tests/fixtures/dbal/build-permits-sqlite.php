<?php

/**
 * build-permits-sqlite.php — builds the `permits.sqlite` demo fixture used by the
 * dbal-virtual-registers tests (a municipality permit-tracking database).
 *
 * No binary database is committed; the file is generated on demand — from the
 * test bootstrap or by running this script directly:
 *
 *   php tests/fixtures/dbal/build-permits-sqlite.php [/path/to/permits.sqlite]
 *
 * The tables and foreign keys are created through Doctrine DBAL's schema builder
 * so introspection round-trips cleanly to the golden `expected-introspection.json`.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Fixtures\Dbal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema as DbalSchema;
use Doctrine\DBAL\Types\Types;

/**
 * Build the permits demo SQLite database at the given path (idempotent — an
 * existing file is removed and rebuilt).
 *
 * @param string $path The target SQLite file path.
 *
 * @return void
 */
function build_permits_sqlite(string $path): void
{
    if (file_exists($path) === true) {
        unlink($path);
    }

    $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);

    $schema = new DbalSchema();

    // permit_types(id PK, code, name, max_duration_days).
    $permitTypes = $schema->createTable('permit_types');
    $permitTypes->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
    $permitTypes->addColumn('code', Types::STRING, ['length' => 32, 'notnull' => true]);
    $permitTypes->addColumn('name', Types::STRING, ['length' => 128, 'notnull' => true]);
    $permitTypes->addColumn('max_duration_days', Types::INTEGER, ['notnull' => false]);
    $permitTypes->setPrimaryKey(['id']);

    // applicants(id PK, full_name TEXT NOT NULL, email VARCHAR(255) NOT NULL, kvk_number VARCHAR NULL).
    $applicants = $schema->createTable('applicants');
    $applicants->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
    $applicants->addColumn('full_name', Types::TEXT, ['notnull' => true]);
    $applicants->addColumn('email', Types::STRING, ['length' => 255, 'notnull' => true]);
    $applicants->addColumn('kvk_number', Types::STRING, ['length' => 16, 'notnull' => false]);
    $applicants->setPrimaryKey(['id']);

    // permits(id PK, applicant_id FK NOT NULL, permit_type_id FK NOT NULL, reference, status, submitted_at, decided_at NULL).
    $permits = $schema->createTable('permits');
    $permits->addColumn('id', Types::INTEGER, ['autoincrement' => true]);
    $permits->addColumn('applicant_id', Types::INTEGER, ['notnull' => true]);
    $permits->addColumn('permit_type_id', Types::INTEGER, ['notnull' => true]);
    $permits->addColumn('reference', Types::STRING, ['length' => 64, 'notnull' => true]);
    $permits->addColumn('status', Types::STRING, ['length' => 32, 'notnull' => true]);
    $permits->addColumn('submitted_at', Types::DATETIME_MUTABLE, ['notnull' => true]);
    $permits->addColumn('decided_at', Types::DATETIME_MUTABLE, ['notnull' => false]);
    $permits->setPrimaryKey(['id']);
    $permits->addForeignKeyConstraint('applicants', ['applicant_id'], ['id']);
    $permits->addForeignKeyConstraint('permit_types', ['permit_type_id'], ['id']);

    $platform = $connection->getDatabasePlatform();
    foreach ($schema->toSql($platform) as $sql) {
        $connection->executeStatement($sql);
    }

    // A view to exercise view support.
    $connection->executeStatement(
        "CREATE VIEW active_permits AS SELECT id, applicant_id, permit_type_id, reference, status, submitted_at, decided_at FROM permits WHERE status = 'active'"
    );

    seed_permits_rows(connection: $connection);
}//end build_permits_sqlite()

/**
 * Insert a small set of demo rows into the permits database.
 *
 * @param \Doctrine\DBAL\Connection $connection The open connection.
 *
 * @return void
 */
function seed_permits_rows(\Doctrine\DBAL\Connection $connection): void
{
    $connection->insert('permit_types', ['code' => 'ENV', 'name' => 'Environmental', 'max_duration_days' => 365]);
    $connection->insert('permit_types', ['code' => 'BLD', 'name' => 'Building', 'max_duration_days' => 730]);

    $connection->insert('applicants', ['full_name' => 'Alice Example', 'email' => 'alice@example.com', 'kvk_number' => '12345678']);
    $connection->insert('applicants', ['full_name' => 'Bob Sample', 'email' => 'bob@example.com', 'kvk_number' => null]);

    $connection->insert(
            'permits',
            [
                'applicant_id'   => 1,
                'permit_type_id' => 1,
                'reference'      => 'P-2026-0001',
                'status'         => 'active',
                'submitted_at'   => '2026-01-05 09:00:00',
                'decided_at'     => null,
            ]
            );
    $connection->insert(
            'permits',
            [
                'applicant_id'   => 2,
                'permit_type_id' => 2,
                'reference'      => 'P-2026-0002',
                'status'         => 'closed',
                'submitted_at'   => '2026-02-10 11:30:00',
                'decided_at'     => '2026-03-01 14:00:00',
            ]
            );
}//end seed_permits_rows()

// Allow running the builder directly from the CLI.
if (PHP_SAPI === 'cli' && isset($argv) === true && realpath($argv[0]) === realpath(__FILE__)) {
    $target = ($argv[1] ?? (__DIR__.'/permits.sqlite'));
    include_once __DIR__.'/../../../vendor/autoload.php';
    build_permits_sqlite(path: $target);
    fwrite(STDOUT, 'Built permits fixture at '.$target.PHP_EOL);
}
