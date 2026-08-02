<?php

/**
 * Regression tests for named-argument contracts on lib/Db mappers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\DestructionList;
use OCA\OpenRegister\Db\DestructionListMapper;
use OCA\OpenRegister\Db\OrganisationMapper;
use OCA\OpenRegister\Db\SelectionList;
use OCA\OpenRegister\Db\SelectionListMapper;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * A named argument that does not exist on the callee is a PHP 8 fatal
 * (`Error: Unknown named parameter $x`) — and `\Error` is NOT an `\Exception`,
 * so a `catch (\Exception)` around the call does not contain it.
 *
 * Four such calls shipped because phpstan's reports about them had been
 * baselined alongside ~62 phantom reports of an identical shape, caused by
 * stale class-level `@method` docblocks shadowing the real signature
 * (see openregister#2283). These tests assert the CONSEQUENCE — that the call
 * a caller actually makes is accepted by the callee — rather than any
 * particular docblock text.
 */
class MapperNamedArgumentContractTest extends TestCase
{
    /**
     * Return the parameter names of a method, in declaration order.
     *
     * @param string $class  Fully-qualified class name.
     * @param string $method Method name.
     *
     * @return string[]
     */
    private function parameterNames(string $class, string $method): array
    {
        $names = [];
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $names[] = $parameter->getName();
        }

        return $names;
    }

    /**
     * TenantDeprovisionJob, TenantPurgeJob and TenantUsageSyncJob all call
     * `organisationMapper->findAll(filters: ['status' => ...])`. Until the
     * parameter existed, every run of all three jobs died on an uncaught
     * `\Error` — their `catch (\Exception)` could not see it.
     *
     * @return void
     */
    public function testOrganisationMapperFindAllAcceptsFilters(): void
    {
        $this->assertContains(
            'filters',
            $this->parameterNames(OrganisationMapper::class, 'findAll'),
            'OrganisationMapper::findAll() must accept a $filters named argument; '
            . 'three tenant-lifecycle background jobs already pass one.'
        );
    }

    /**
     * The three tenant jobs pass `filters:` as a named argument, so the
     * parameter must not merely exist — it must be reachable by that name
     * without positionally supplying $limit/$offset first.
     *
     * @return void
     */
    public function testOrganisationMapperFindAllIsCallableWithOnlyFilters(): void
    {
        $reflection = new ReflectionMethod(OrganisationMapper::class, 'findAll');
        foreach ($reflection->getParameters() as $parameter) {
            if ($parameter->getName() === 'filters') {
                continue;
            }

            $this->assertTrue(
                $parameter->isDefaultValueAvailable(),
                sprintf(
                    'OrganisationMapper::findAll($%s) has no default, so '
                    . 'findAll(filters: ...) — which three background jobs make — '
                    . 'would raise ArgumentCountError.',
                    $parameter->getName()
                )
            );
        }
    }

    /**
     * `SelectionListMapper::updateEntry()` forwarded to the inherited
     * `QBMapper::update()` as `update(objectId: $entity)`. `QBMapper::update()`
     * takes `$entity`, so the first ever call to `updateEntry()` would have
     * raised `Error: Unknown named parameter $objectId`.
     *
     * This drives the real method body against a stub of `update()` whose
     * signature PHPUnit copies from QBMapper — so the named argument is
     * resolved for real. Before the fix this test fails with \Error.
     *
     * @return void
     */
    public function testSelectionListMapperUpdateEntryForwardsWithAValidArgumentName(): void
    {
        $entity = new SelectionList();

        $mapper = $this->getMockBuilder(SelectionListMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update'])
            ->getMock();

        $mapper->expects($this->once())
            ->method('update')
            ->with($this->identicalTo($entity))
            ->willReturn($entity);

        $this->assertSame($entity, $mapper->updateEntry($entity));
    }

    /**
     * Identical defect, identical shape, in DestructionListMapper.
     *
     * @return void
     */
    public function testDestructionListMapperUpdateEntryForwardsWithAValidArgumentName(): void
    {
        $entity = new DestructionList();

        $mapper = $this->getMockBuilder(DestructionListMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update'])
            ->getMock();

        $mapper->expects($this->once())
            ->method('update')
            ->with($this->identicalTo($entity))
            ->willReturn($entity);

        $this->assertSame($entity, $mapper->updateEntry($entity));
    }

    /**
     * The mechanical guard for the whole defect class.
     *
     * A class-level `@method` line that names a method the class ALSO declares
     * natively overrides the real signature for phpstan and psalm, turning
     * correct calls into phantom errors — and, worse, making a genuine
     * unknown-named-argument fatal indistinguishable from that noise.
     *
     * Narrowing an INHERITED QBMapper method (insert/update/delete/findEntity/
     * findEntities) is legitimate and is deliberately not caught here: those
     * methods are not declared by the class itself.
     *
     * SCOPE: only the `find`/`findAll` shadows have been removed so far — those
     * are the ones producing live findings. The same seven classes still shadow
     * `insert`/`update`/`delete`, and 14 further mappers still shadow `findAll`.
     * Both remainders are tracked in openregister#2283; widening the method list
     * below is how that issue gets closed.
     *
     * @return void
     */
    public function testCleanedMappersDeclareNoShadowingMethodAnnotation(): void
    {
        $guarded = ['find', 'findAll'];

        $cleaned = [
            'ApplicationMapper',
            'AuditTrailMapper',
            'ConfigurationMapper',
            'OrganisationMapper',
            'SchemaMapper',
            'SearchTrailMapper',
            'ViewMapper',
        ];

        foreach ($cleaned as $shortName) {
            $file = dirname(__DIR__, 3) . '/lib/Db/' . $shortName . '.php';
            $this->assertFileExists($file);

            $source = (string) file_get_contents($file);
            $class  = 'OCA\\OpenRegister\\Db\\' . $shortName;

            preg_match_all('/^ \* @method\s+\S+\s+(\w+)\(/m', $source, $annotated);

            $native = [];
            foreach ((new ReflectionClass($class))->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() === $class) {
                    $native[$method->getName()] = true;
                }
            }

            foreach (array_unique($annotated[1]) as $name) {
                if (in_array($name, $guarded, true) === false) {
                    continue;
                }

                $this->assertArrayNotHasKey(
                    $name,
                    $native,
                    sprintf(
                        '%s carries "@method ... %s(...)" while also declaring %s() '
                        . 'natively. The docblock silently overrides the real signature '
                        . 'for phpstan and psalm — see openregister#2283.',
                        $shortName,
                        $name,
                        $name
                    )
                );
            }
        }
    }
}
