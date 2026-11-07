<?php

/**
 * Pins the folder binding check on the save path of ObjectService.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\FolderAccessDeniedException;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * @covers \OCA\OpenRegister\Service\ObjectService
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Exception\FolderAccessDeniedException
 */
class ObjectServiceFolderRevalidationTest extends TestCase
{
    /** @var FileService&MockObject */
    private FileService $files;

    /** @var MagicMapper&MockObject */
    private MagicMapper $mapper;

    private ObjectService $service;

    private Register $register;

    private Schema $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files    = $this->createMock(FileService::class);
        $this->mapper   = $this->createMock(MagicMapper::class);
        $this->register = new Register();
        $this->schema   = new Schema();

        $reflection    = new ReflectionClass(ObjectService::class);
        $this->service = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'fileService'     => $this->files,
            'objectMapper'    => $this->mapper,
            'currentRegister' => $this->register,
            'currentSchema'   => $this->schema,
        ] as $name => $value) {
            $reflection->getProperty($name)->setValue($this->service, $value);
        }
    }

    /**
     * Call the private save-path step.
     *
     * @param IUser|null $user The acting user.
     *
     * @return int|null What the step returns.
     */
    private function ensure(?IUser $user=null): ?int
    {
        return (new ReflectionMethod(ObjectService::class, 'ensureObjectFolder'))->invoke($this->service, 'uuid-1', $user);
    }

    /**
     * Store one object with the given folder value.
     *
     * @param string|null $folder The folder column.
     *
     * @return ObjectEntity The stored object.
     */
    private function stored(?string $folder): ObjectEntity
    {
        $object = $this->createMock(ObjectEntity::class);
        $object->method('__call')->willReturnCallback(
            static fn (string $name) => $name === 'getFolder' ? $folder : null
        );
        $this->mapper->method('find')->willReturn($object);

        return $object;
    }

    public function testANumericBindingIsCheckedForTheActingUser(): void
    {
        $object = $this->stored('42');
        $user   = $this->createMock(IUser::class);
        $this->files->expects($this->once())->method('assertObjectFolderAccessible')
            ->with($object, $user);

        $this->assertNull($this->ensure($user));
    }

    /**
     * @dataProvider noBindingProvider
     */
    public function testNoNumericBindingMeansNothingToCheck(?string $folder): void
    {
        $this->stored($folder);
        $this->files->expects($this->never())->method('assertObjectFolderAccessible');

        $this->assertNull($this->ensure());
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function noBindingProvider(): array
    {
        return ['null' => [null], 'empty' => [''], 'legacy path' => ['Open Registers/legacy']];
    }

    public function testTheLookupStaysInsideTheWriteTarget(): void
    {
        $this->mapper->expects($this->once())->method('find')
            ->with('uuid-1', $this->register, $this->schema)
            ->willReturn($this->createMock(ObjectEntity::class));

        $this->ensure();
    }

    public function testADenialReachesTheCaller(): void
    {
        $this->stored('42');
        $this->files->method('assertObjectFolderAccessible')
            ->willThrowException(new FolderAccessDeniedException(attemptedFolderId: '42'));

        $this->expectException(FolderAccessDeniedException::class);

        $this->ensure();
    }

    public function testAMissingObjectLeavesTheSaveToCreateIt(): void
    {
        $this->mapper->method('find')->willThrowException(new DoesNotExistException('none'));
        $this->files->expects($this->never())->method('assertObjectFolderAccessible');

        $this->assertNull($this->ensure());
    }

    public function testAnotherLookupErrorLeavesTheSaveToCreateIt(): void
    {
        $this->mapper->method('find')->willThrowException(new Exception('down'));

        $this->assertNull($this->ensure());
    }
}
