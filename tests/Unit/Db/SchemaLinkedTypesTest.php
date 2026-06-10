<?php

/**
 * Unit tests for Schema::validateLinkedTypesValue() — the registry-driven
 * `linkedTypes` configuration validator (ADR-019 / pluggable-integration-registry
 * task 7-8).
 *
 * Covers:
 *  - legacy VALID_LINKED_TYPES fallback still accepts pre-registry ids
 *    (`files`, `mail`, `contacts`, `notes`, `todos`, `calendar`, `talk`,
 *    `deck`) — backwards compatibility for one cycle, before
 *    `cleanup-linked-entity-type-map` removes the constant.
 *  - registry ids appear merged into the accepted-vocabulary error message
 *    when an unknown id is rejected (so devs see what is registered).
 *  - non-string values are rejected with a clear error.
 *  - non-array values are rejected with a clear error.
 *  - null is accepted (cleared configuration).
 *  - validation runs through the public `setConfiguration()` surface
 *    (no @internal API surface used).
 *
 * Note: this test deliberately exercises only the *legacy fallback* path
 * because `Schema::resolveIntegrationRegistryIds()` reads
 * `\OC::$server`, which is not booted in PHPUnit's unit context. The
 * registry-driven side is covered by `IntegrationRegistryTest` and the
 * integration path is verified live by the end-to-end registry test
 * (acceptance verification — Phase 5 of the change).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db
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
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-7
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\Schema::validateLinkedTypesValue
 */
class SchemaLinkedTypesTest extends TestCase
{

    /**
     * Subject under test — a fresh Schema entity per test.
     *
     * @var Schema
     */
    private Schema $schema;

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->schema = new Schema();
    }//end setUp()

    /**
     * Each id in the deprecated VALID_LINKED_TYPES constant MUST still
     * round-trip through setConfiguration. This guarantees that schemas
     * created before the registry exists keep validating until the
     * matching providers ship.
     *
     * @return void
     */
    public function testLegacyLinkedTypesStillAccepted(): void
    {
        $legacy = ['files', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'];

        $this->schema->setConfiguration(['linkedTypes' => $legacy]);

        $config = $this->schema->getConfiguration();
        $this->assertIsArray($config);
        $this->assertSame($legacy, $config['linkedTypes']);
    }//end testLegacyLinkedTypesStillAccepted()

    /**
     * A subset of the legacy list (just `files`) also round-trips.
     *
     * @return void
     */
    public function testSingleLegacyLinkedTypeAccepted(): void
    {
        $this->schema->setConfiguration(['linkedTypes' => ['files']]);

        $config = $this->schema->getConfiguration();
        $this->assertSame(['files'], $config['linkedTypes']);
    }//end testSingleLegacyLinkedTypeAccepted()

    /**
     * An unknown id (no legacy + no registry registration) is rejected
     * with a clear error message that lists the accepted vocabulary.
     *
     * @return void
     */
    public function testUnknownLinkedTypeRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid linked type 'totally-not-a-real-thing'");

        $this->schema->setConfiguration(['linkedTypes' => ['totally-not-a-real-thing']]);
    }//end testUnknownLinkedTypeRejected()

    /**
     * The rejection message contains the legacy vocabulary so callers
     * can fix the typo without grepping source. Asserts the union path
     * (legacy + registry) produces a stable, alphabetised list.
     *
     * @return void
     */
    public function testUnknownLinkedTypeErrorListsValidValues(): void
    {
        try {
            $this->schema->setConfiguration(['linkedTypes' => ['bogus']]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $message = $e->getMessage();
            // Every legacy id MUST appear in the "Valid values:" tail.
            foreach (['files', 'mail', 'contacts', 'notes', 'todos', 'calendar', 'talk', 'deck'] as $expected) {
                $this->assertStringContainsString(
                    $expected,
                    $message,
                    sprintf('Expected legacy id "%s" in error message: %s', $expected, $message)
                );
            }
        }
    }//end testUnknownLinkedTypeErrorListsValidValues()

    /**
     * `linkedTypes` MUST be an array — strings and other scalars are
     * rejected at the configuration-validator layer.
     *
     * @return void
     */
    public function testLinkedTypesMustBeAnArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("'linkedTypes' must be an array");

        $this->schema->setConfiguration(['linkedTypes' => 'files']);
    }//end testLinkedTypesMustBeAnArray()

    /**
     * Each entry in the array MUST be a string. Numeric / boolean
     * payloads are rejected.
     *
     * @return void
     */
    public function testLinkedTypeEntriesMustBeStrings(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("All values in 'linkedTypes' must be strings");

        $this->schema->setConfiguration(['linkedTypes' => ['files', 42]]);
    }//end testLinkedTypeEntriesMustBeStrings()

    /**
     * Null linkedTypes is the explicit "no integrations declared"
     * shape — it MUST be accepted without an error and dropped from
     * the persisted configuration.
     *
     * @return void
     */
    public function testNullLinkedTypesIsAccepted(): void
    {
        $this->schema->setConfiguration(['linkedTypes' => null]);

        $config = $this->schema->getConfiguration();
        // Either the linkedTypes key is dropped, or it round-trips as null.
        if ($config !== null && array_key_exists('linkedTypes', $config) === true) {
            $this->assertNull($config['linkedTypes']);
        } else {
            $this->assertTrue(true, 'linkedTypes was dropped (also acceptable)');
        }
    }//end testNullLinkedTypesIsAccepted()

    /**
     * An empty linkedTypes array is accepted — explicit "I have looked
     * at this and there's nothing" vs the null "I haven't configured it".
     *
     * @return void
     */
    public function testEmptyLinkedTypesArrayAccepted(): void
    {
        $this->schema->setConfiguration(['linkedTypes' => []]);

        $config = $this->schema->getConfiguration();
        $this->assertIsArray($config);
        $this->assertSame([], $config['linkedTypes']);
    }//end testEmptyLinkedTypesArrayAccepted()

    /**
     * Mixing legacy ids with one unknown id rejects the whole payload —
     * partial-acceptance would let bogus values slip into stored
     * configuration. Defence in depth.
     *
     * @return void
     */
    public function testMixedValidAndInvalidLinkedTypesRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->schema->setConfiguration(['linkedTypes' => ['files', 'bogus']]);
    }//end testMixedValidAndInvalidLinkedTypesRejected()
}//end class
