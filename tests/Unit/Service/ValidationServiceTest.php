<?php

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ValidationService;
use PHPUnit\Framework\TestCase;

/**
 * Test class for ValidationService
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  AGPL-3.0-or-later
 * @link     https://github.com/ConductionNL/OpenRegister
 * @version  1.0.0
 */
class ValidationServiceTest extends TestCase
{
    private ValidationService $validationService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create ValidationService instance
        $this->validationService = new ValidationService();
    }

    /**
     * Test that ValidationService can be instantiated
     */
    public function testValidationServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ValidationService::class, $this->validationService);
    }

    /**
     * Test that ValidationService is empty (no methods implemented yet)
     */
    public function testValidationServiceIsEmpty(): void
    {
        $reflection = new \ReflectionClass($this->validationService);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
        
        // Filter out inherited methods from parent classes
        $ownMethods = array_filter($methods, function($method) {
            return $method->getDeclaringClass()->getName() === ValidationService::class;
        });

        $this->assertCount(0, $ownMethods, 'ValidationService should have no public methods yet');
    }
}