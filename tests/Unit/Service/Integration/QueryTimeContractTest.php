<?php

/**
 * Unit tests for QueryTimeContract.
 *
 * Covers:
 *  - documented constants are stable values
 *  - buildHttpBody returns the standard error envelope shape with
 *    the integration id wired into details
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/pluggable-integration-registry/tasks.md#task-6
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

use OCA\OpenRegister\Exception\NotImplementedException;
use OCA\OpenRegister\Service\Integration\QueryTimeContract;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for QueryTimeContract.
 */
class QueryTimeContractTest extends TestCase
{

    public function testRenderTimeoutMatchesContract(): void
    {
        $this->assertSame(2.0, QueryTimeContract::RENDER_TIMEOUT_SECONDS);
    }//end testRenderTimeoutMatchesContract()

    public function testHttpStatusForNotImplementedIs501(): void
    {
        $this->assertSame(501, QueryTimeContract::HTTP_NOT_IMPLEMENTED);
    }//end testHttpStatusForNotImplementedIs501()

    public function testBuildHttpBodyEnvelopesIntegrationId(): void
    {
        $exception = new NotImplementedException('Provider activity does not support create()');
        $body      = QueryTimeContract::buildHttpBody($exception, 'activity');

        $this->assertSame('Provider activity does not support create()', $body['message']);
        $this->assertSame(501, $body['code']);
        $this->assertSame('activity', $body['details']['integration']);
        $this->assertSame('query-time-storage-no-mutation', $body['details']['reason']);
    }//end testBuildHttpBodyEnvelopesIntegrationId()

}//end class
