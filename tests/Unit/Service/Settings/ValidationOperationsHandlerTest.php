<?php

declare(strict_types=1);

/**
 * ValidationOperationsHandler Unit Tests
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Settings
 * @author   OpenRegister Team
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/OpenRegister/OpenRegister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Settings;

use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Object\ValidateObject;
use OCA\OpenRegister\Service\Settings\ValidationOperationsHandler;
use OCP\AppFramework\IAppContainer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for ValidationOperationsHandler
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Comprehensive coverage requires many test methods
 */
class ValidationOperationsHandlerTest extends TestCase
{
    /** @var ValidationOperationsHandler */
    private ValidationOperationsHandler $handler;

    /** @var ValidateObject&MockObject */
    private ValidateObject $validateHandler;

    /** @var SchemaMapper&MockObject */
    private SchemaMapper $schemaMapper;

    /** @var LoggerInterface&MockObject */
    private LoggerInterface $logger;

    /** @var IAppContainer&MockObject */
    private IAppContainer $container;

    /**
     * Set up test fixtures
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->validateHandler = $this->createMock(ValidateObject::class);
        $this->schemaMapper = $this->createMock(SchemaMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->container = $this->createMock(IAppContainer::class);

        $this->handler = new ValidationOperationsHandler(
            $this->validateHandler,
            $this->schemaMapper,
            $this->logger,
            $this->container
        );
    }

    /**
     * Test validateAllObjects returns empty result when ObjectService is unavailable.
     *
     * The getObjectService() method always returns null (circular dependency workaround),
     * so validateAllObjects should return the empty/default result.
     *
     * @return void
     */
    public function testValidateAllObjectsReturnsEmptyWhenObjectServiceUnavailable(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertSame(0, $result['total_objects']);
        $this->assertSame(0, $result['valid_objects']);
        $this->assertSame(0, $result['invalid_objects']);
        $this->assertEmpty($result['validation_errors']);
        $this->assertSame(100, $result['summary']['validation_success_rate']);
        $this->assertFalse($result['summary']['has_errors']);
        $this->assertSame(0, $result['summary']['error_count']);
    }

    /**
     * Test validateAllObjects result structure contains all required keys.
     *
     * @return void
     */
    public function testValidateAllObjectsHasCorrectStructure(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertArrayHasKey('total_objects', $result);
        $this->assertArrayHasKey('valid_objects', $result);
        $this->assertArrayHasKey('invalid_objects', $result);
        $this->assertArrayHasKey('validation_errors', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertArrayHasKey('validation_success_rate', $result['summary']);
        $this->assertArrayHasKey('has_errors', $result['summary']);
        $this->assertArrayHasKey('error_count', $result['summary']);
    }

    /**
     * Test validateAllObjects returns array type for validation_errors.
     *
     * @return void
     */
    public function testValidateAllObjectsValidationErrorsIsArray(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertIsArray($result['validation_errors']);
    }

    /**
     * Test validateAllObjects summary has correct types.
     *
     * @return void
     */
    public function testValidateAllObjectsSummaryHasCorrectTypes(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertIsInt($result['total_objects']);
        $this->assertIsInt($result['valid_objects']);
        $this->assertIsInt($result['invalid_objects']);
        $this->assertIsNumeric($result['summary']['validation_success_rate']);
        $this->assertIsBool($result['summary']['has_errors']);
        $this->assertIsInt($result['summary']['error_count']);
    }

    /**
     * Test validateAllObjects returns 100% success rate for zero objects.
     *
     * @return void
     */
    public function testValidateAllObjectsReturns100PercentForZeroObjects(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertSame(100, $result['summary']['validation_success_rate']);
    }

    /**
     * Test constructor stores dependencies correctly.
     *
     * @return void
     */
    public function testConstructorStoresDependencies(): void
    {
        $handler = new ValidationOperationsHandler(
            $this->validateHandler,
            $this->schemaMapper,
            $this->logger,
            $this->container
        );

        // Verify the handler works (indirectly testing constructor).
        $result = $handler->validateAllObjects();
        $this->assertIsArray($result);
    }

    /**
     * Test validateAllObjects can be called multiple times.
     *
     * @return void
     */
    public function testValidateAllObjectsCanBeCalledMultipleTimes(): void
    {
        $result1 = $this->handler->validateAllObjects();
        $result2 = $this->handler->validateAllObjects();

        $this->assertSame($result1, $result2);
    }

    /**
     * Test validateAllObjects invalid_objects count matches errors for empty result.
     *
     * @return void
     */
    public function testValidateAllObjectsInvalidCountMatchesErrorCount(): void
    {
        $result = $this->handler->validateAllObjects();

        $this->assertSame(
            $result['invalid_objects'],
            $result['summary']['error_count']
        );
    }
}
