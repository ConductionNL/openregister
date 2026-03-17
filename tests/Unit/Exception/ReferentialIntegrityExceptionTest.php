<?php
/**
 * ReferentialIntegrityException Unit Tests
 *
 * Tests for the exception thrown when object deletion is blocked by
 * referential integrity constraints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Exception;

use OCA\OpenRegister\Dto\DeletionAnalysis;
use OCA\OpenRegister\Exception\ReferentialIntegrityException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReferentialIntegrityException
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Exception
 */
class ReferentialIntegrityExceptionTest extends TestCase
{

    /**
     * Test that the exception message contains the blocker count.
     *
     * @return void
     */
    public function testMessageContainsBlockerCount(): void
    {
        $blockers = [
            ['objectUuid' => 'uuid-1', 'schema' => '5', 'property' => 'ref', 'action' => 'RESTRICT'],
            ['objectUuid' => 'uuid-2', 'schema' => '5', 'property' => 'ref', 'action' => 'RESTRICT'],
        ];

        $analysis  = new DeletionAnalysis(deletable: false, blockers: $blockers);
        $exception = new ReferentialIntegrityException($analysis);

        $this->assertSame(
            'Cannot delete object: 2 dependent object(s) block deletion',
            $exception->getMessage()
        );
    }//end testMessageContainsBlockerCount()

    /**
     * Test message with a single blocker.
     *
     * @return void
     */
    public function testMessageSingleBlocker(): void
    {
        $blockers = [
            ['objectUuid' => 'uuid-1', 'schema' => '5', 'property' => 'ref', 'action' => 'RESTRICT'],
        ];

        $analysis  = new DeletionAnalysis(deletable: false, blockers: $blockers);
        $exception = new ReferentialIntegrityException($analysis);

        $this->assertStringContainsString('1 dependent object(s)', $exception->getMessage());
    }//end testMessageSingleBlocker()

    /**
     * Test getAnalysis() returns the DeletionAnalysis.
     *
     * @return void
     */
    public function testGetAnalysis(): void
    {
        $blockers = [
            ['objectUuid' => 'uuid-1', 'schema' => '5', 'property' => 'ref', 'action' => 'RESTRICT'],
        ];

        $analysis  = new DeletionAnalysis(deletable: false, blockers: $blockers);
        $exception = new ReferentialIntegrityException($analysis);

        $this->assertSame($analysis, $exception->getAnalysis());
        $this->assertFalse($exception->getAnalysis()->deletable);
        $this->assertCount(1, $exception->getAnalysis()->blockers);
    }//end testGetAnalysis()

    /**
     * Test toResponseBody() returns structured error response.
     *
     * @return void
     */
    public function testToResponseBody(): void
    {
        $blockers = [
            [
                'objectUuid' => 's1-uuid',
                'schema'     => 'service-schema',
                'property'   => 'serviceType',
                'action'     => 'RESTRICT',
                'chain'      => ['st-uuid → s1-uuid (RESTRICT)'],
            ],
            [
                'objectUuid' => 's2-uuid',
                'schema'     => 'service-schema',
                'property'   => 'serviceType',
                'action'     => 'RESTRICT',
                'chain'      => ['st-uuid → s2-uuid (RESTRICT)'],
            ],
        ];

        $analysis  = new DeletionAnalysis(deletable: false, blockers: $blockers);
        $exception = new ReferentialIntegrityException($analysis);

        $body = $exception->toResponseBody();

        $this->assertSame('DELETION_BLOCKED', $body['error']);
        $this->assertStringContainsString('2 dependent object(s)', $body['message']);
        $this->assertCount(2, $body['blockers']);
        $this->assertSame('s1-uuid', $body['blockers'][0]['objectUuid']);
        $this->assertSame('s2-uuid', $body['blockers'][1]['objectUuid']);
    }//end testToResponseBody()

    /**
     * Test that the exception extends base Exception.
     *
     * @return void
     */
    public function testExtendsException(): void
    {
        $analysis  = new DeletionAnalysis(deletable: false, blockers: []);
        $exception = new ReferentialIntegrityException($analysis);

        $this->assertInstanceOf(\Exception::class, $exception);
    }//end testExtendsException()

    /**
     * Test custom error code is preserved.
     *
     * @return void
     */
    public function testCustomErrorCode(): void
    {
        $analysis  = new DeletionAnalysis(deletable: false, blockers: []);
        $exception = new ReferentialIntegrityException($analysis, code: 409);

        $this->assertSame(409, $exception->getCode());
    }//end testCustomErrorCode()

    /**
     * Test previous exception chaining.
     *
     * @return void
     */
    public function testPreviousExceptionChaining(): void
    {
        $previous  = new \RuntimeException('root cause');
        $analysis  = new DeletionAnalysis(deletable: false, blockers: []);
        $exception = new ReferentialIntegrityException($analysis, previous: $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }//end testPreviousExceptionChaining()

    /**
     * Test response body with chained RESTRICT includes chain field.
     *
     * @return void
     */
    public function testResponseBodyWithChainedRestrict(): void
    {
        $blockers = [
            [
                'objectUuid' => 'audit-uuid',
                'schema'     => 'audit-schema',
                'property'   => 'contact',
                'action'     => 'RESTRICT',
                'chain'      => [
                    'person-uuid → contact-uuid (CASCADE)',
                    'contact-uuid → audit-uuid (RESTRICT)',
                ],
            ],
        ];

        $analysis  = new DeletionAnalysis(deletable: false, blockers: $blockers);
        $exception = new ReferentialIntegrityException($analysis);
        $body      = $exception->toResponseBody();

        $this->assertCount(2, $body['blockers'][0]['chain']);
        $this->assertStringContainsString('CASCADE', $body['blockers'][0]['chain'][0]);
        $this->assertStringContainsString('RESTRICT', $body['blockers'][0]['chain'][1]);
    }//end testResponseBodyWithChainedRestrict()
}//end class
