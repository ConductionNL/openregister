<?php

/**
 * Unit tests for the cross-schema unified-search register resolution helper.
 *
 * Covers extractSchemaIds(), the primitive used by
 * MagicMapper::searchObjectsPaginatedMultiSchema() to build the
 * schema_id -> owning-register map so every searched schema is paired with its
 * REAL register (correct magic table) instead of being forced onto a default
 * one. The DB-integration behaviour of the multi-schema union is validated live
 * (see openspec/changes/unified-search-index/tasks.md, §2 status note); these
 * tests pin the pure, environment-independent membership-parsing contract.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Db\MagicMapper
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 *
 * @spec openspec/changes/unified-search-index/specs/unified-search-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Targets MagicMapper::extractSchemaIds() — the schema-membership normaliser
 * behind cross-schema unified search.
 */
class MagicMapperSchemaRegisterResolutionTest extends TestCase
{

    /**
     * Build a MagicMapper instance bypassing the constructor.
     *
     * extractSchemaIds() is pure (touches no instance state), so no
     * dependencies need wiring — newInstanceWithoutConstructor() suffices.
     *
     * @return MagicMapper Mapper instance.
     */
    private function buildMapperWithoutConstructor(): MagicMapper
    {
        $reflection = new ReflectionClass(MagicMapper::class);
        return $reflection->newInstanceWithoutConstructor();

    }//end buildMapperWithoutConstructor()


    /**
     * Invoke a private MagicMapper method by name.
     *
     * @param MagicMapper $mapper Mapper instance.
     * @param string      $method Method name.
     * @param array       $args   Positional arguments.
     *
     * @return mixed Return value of the invocation.
     */
    private function invokePrivate(MagicMapper $mapper, string $method, array $args): mixed
    {
        $reflectionMethod = new ReflectionMethod(MagicMapper::class, $method);
        $reflectionMethod->setAccessible(true);
        return $reflectionMethod->invokeArgs($mapper, $args);

    }//end invokePrivate()


    /**
     * A plain list of integer schema ids (the common `getSchemas()` / decoded
     * `schemas` column shape) is returned verbatim as integers.
     *
     * @return void
     */
    public function testExtractsIntegerSchemaIdsByValue(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $result = $this->invokePrivate($mapper, 'extractSchemaIds', [[4306, 4307, 4309]]);

        sort($result);
        $this->assertSame([4306, 4307, 4309], $result);

    }//end testExtractsIntegerSchemaIdsByValue()


    /**
     * Numeric-string ids (as a JSON-decoded column can yield) are coerced to
     * integers, and ids carried by KEY (id => label maps) are also collected.
     *
     * @return void
     */
    public function testExtractsNumericStringsAndKeyedIds(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $byValue = $this->invokePrivate($mapper, 'extractSchemaIds', [['28', '430']]);
        sort($byValue);
        $this->assertSame([28, 430], $byValue);

        // id-by-key shape: {"4310": "Pet", "4311": "Visit"}.
        $byKey = $this->invokePrivate($mapper, 'extractSchemaIds', [[4310 => 'Pet', 4311 => 'Visit']]);
        sort($byKey);
        $this->assertSame([4310, 4311], $byKey);

    }//end testExtractsNumericStringsAndKeyedIds()


    /**
     * Non-numeric and empty inputs yield no ids and never throw — a register
     * with a malformed/empty `schemas` membership must simply contribute
     * nothing to the schema->register map (the schema is skipped downstream).
     *
     * @return void
     */
    public function testIgnoresNonNumericAndEmptyMembership(): void
    {
        $mapper = $this->buildMapperWithoutConstructor();

        $this->assertSame([], $this->invokePrivate($mapper, 'extractSchemaIds', [[]]));
        $this->assertSame([], $this->invokePrivate($mapper, 'extractSchemaIds', [['not-an-id', 'abc']]));

        // Mixed: only the numeric entry survives, distinct ids only.
        $mixed = $this->invokePrivate($mapper, 'extractSchemaIds', [['x', '4309', 4309]]);
        $this->assertSame([4309], $mixed);

    }//end testIgnoresNonNumericAndEmptyMembership()


}//end class
