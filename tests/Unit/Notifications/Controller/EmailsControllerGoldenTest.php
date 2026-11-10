<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use Exception;
use OCA\OpenRegister\Db\EmailLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Notifications\Controller\EmailsController;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsControllerServices;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsDomain;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsObjectAccess;
use OCA\OpenRegister\Notifications\Controller\Support\EmailsPlatform;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Service\EmailLinkService;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Db\ListQuery;
use OCA\OpenRegister\Notifications\Service\EmailLinkRequest;
use OCA\OpenRegister\Tests\Unit\Service\Object\PermissionAskArgs;

/**
 * Golden: the status, body and call order of index/create/destroy on the email links API,
 * for every allowed and refused path.
 *
 * @covers \OCA\OpenRegister\Notifications\Controller\EmailsController
 */
class EmailsControllerGoldenTest extends TestCase
{

    /**
     * Every collaborator call, in order.
     *
     * @var array<int, string>
     */
    private array $log = [];

    /**
     * Build the controller over one scenario.
     *
     * @param array<string, mixed> $scenario mail, object (found|null|missing|nouuid), update, params, fail.
     *
     * @return EmailsController The controller.
     */
    private function controller(array $scenario): EmailsController
    {
        $scenario += ['mail' => true, 'object' => 'found', 'update' => true, 'params' => [], 'fail' => null, 'schema' => true];

        $request = $this->createMock(IRequest::class);
        $request->method('getParams')->willReturnCallback(
            function () use ($scenario): array {
                $this->log[] = 'getParams';
                return $scenario['params'];
            }
        );

        $emailService = $this->createMock(EmailService::class);
        $emailService->method('isMailAvailable')->willReturnCallback(
            function () use ($scenario): bool {
                $this->log[] = 'isMailAvailable';
                return $scenario['mail'];
            }
        );
        $emailService->method('getEmailsForObject')->willReturnCallback(
            function (string $uuid, ListQuery $query) use ($scenario): array {
                $this->log[] = 'getEmailsForObject:' . $uuid . ':' . var_export($query->limitOrNull(), true) . ':' . var_export($query->offset, true);
                $this->maybeFail($scenario['fail'], 'list');
                return ['results' => [], 'total' => 0];
            }
        );
        $emailService->method('linkEmail')->willReturnCallback(
            function (EmailLinkRequest $request) use ($scenario): EmailLink {
                $this->log[] = 'linkEmail:' . $request->objectUuid . ':' . $request->registerId . ':' . $request->mailAccountId . ':' . $request->mailMessageId;
                $this->maybeFail($scenario['fail'], 'link');
                $link = new EmailLink();
                $link->setId(5);
                return $link;
            }
        );

        $links = $this->createMock(EmailLinkService::class);
        $links->method('assertAccountOwnership')->willReturnCallback(
            function (int $accountId) use ($scenario): void {
                $this->log[] = 'assertAccountOwnership:' . $accountId;
                $this->maybeFail($scenario['fail'], 'owner');
            }
        );
        $links->method('unlinkEmail')->willReturnCallback(
            function (string $objectUuid, int $linkId) use ($scenario): void {
                $this->log[] = 'unlinkEmail:' . $objectUuid . ':' . $linkId;
                $this->maybeFail($scenario['fail'], 'unlink');
            }
        );

        $objectService = $this->createMock(ObjectService::class);
        foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
            $objectService->method($setter)->willReturnCallback(
                function ($value) use ($setter, $objectService) {
                    $this->log[] = $setter . ':' . $value;
                    return $objectService;
                }
            );
        }

        $objectService->method('getObject')->willReturnCallback(
            function () use ($scenario): ?ObjectEntity {
                $this->log[] = 'getObject';
                if ($scenario['object'] === 'missing') {
                    throw new DoesNotExistException('gone');
                }

                if ($scenario['object'] === null) {
                    return null;
                }

                $object = new ObjectEntity();
                $object->setRegister('3');
                $object->setOwner('owner');
                if ($scenario['object'] === 'found') {
                    $object->setUuid('obj-1');
                }

                return $object;
            }
        );
        $objectService->method('getCurrentSchemaEntity')->willReturn($scenario['schema'] === true ? new Schema() : null);

        $permissions = $this->createMock(PermissionHandlerInterface::class);
        $permissions->method('hasPermission')->willReturnCallback(PermissionAskArgs::spread(
            function (Schema $schema, string $action) use ($scenario): bool {
                $this->log[] = 'hasPermission:' . $action;
                return $scenario['update'];
            }
        ));

        return new EmailsController(
            $request,
            new EmailsControllerServices(
                new EmailsDomain($emailService, $links),
                new EmailsObjectAccess($objectService, $permissions),
                new EmailsPlatform($this->createMock(IUserSession::class), $this->createMock(LoggerInterface::class))
            )
        );
    }

    /**
     * Throw the scenario's failure at its step.
     *
     * @param array{0: string, 1: int}|null $fail The step and the exception code.
     *
     * @return void
     */
    private function maybeFail(?array $fail, string $step): void
    {
        if ($fail !== null && $fail[0] === $step) {
            throw new Exception('failed ' . $step, $fail[1]);
        }
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: array<string, mixed>, 3: array<int, string>}> */
    public static function createProvider(): array
    {
        $path   = ['setRegister:reg', 'setSchema:sch', 'setObject:obj'];
        $params = ['mailAccountId' => '4', 'mailMessageId' => '9'];
        $upTo   = array_merge(['isMailAvailable'], $path, ['getObject', 'hasPermission:update', 'getParams', 'assertAccountOwnership:4']);
        return [
            'linked'            => [['params' => $params], 201, ['id' => 5], array_merge($upTo, ['linkEmail:obj-1:3:4:9'])],
            'mail missing'      => [['mail' => false], 501, ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'], ['isMailAvailable']],
            'object null'       => [['object' => null], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'object missing'    => [['object' => 'missing'], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'no update right'   => [['update' => false, 'params' => $params], 403, ['error' => 'You may not change this object, so you cannot change its emails.'], array_merge(['isMailAvailable'], $path, ['getObject', 'hasPermission:update'])],
            'no schema'         => [['schema' => false, 'params' => $params], 403, ['error' => 'You may not change this object, so you cannot change its emails.'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'missing message'   => [['params' => ['mailAccountId' => '4']], 400, ['error' => 'mailAccountId and mailMessageId are required'], array_merge(['isMailAvailable'], $path, ['getObject', 'hasPermission:update', 'getParams'])],
            'zero account'      => [['params' => ['mailAccountId' => '0', 'mailMessageId' => '9']], 400, ['error' => 'mailAccountId and mailMessageId are required'], array_merge(['isMailAvailable'], $path, ['getObject', 'hasPermission:update', 'getParams'])],
            'foreign account'   => [['params' => $params, 'fail' => ['owner', 403]], 403, ['error' => 'failed owner'], $upTo],
            'unknown account'   => [['params' => $params, 'fail' => ['owner', 404]], 404, ['error' => 'failed owner'], $upTo],
            'already linked'    => [['params' => $params, 'fail' => ['link', 409]], 409, ['error' => 'failed link'], array_merge($upTo, ['linkEmail:obj-1:3:4:9'])],
            'other link error'  => [['params' => $params, 'fail' => ['link', 500]], 400, ['error' => 'failed link'], array_merge($upTo, ['linkEmail:obj-1:3:4:9'])],
        ];
    }

    #[DataProvider('createProvider')]
    public function testCreate(array $scenario, int $status, array $body, array $log): void
    {
        $response = $this->controller($scenario)->create('reg', 'sch', 'obj');

        $data = $response->getData();
        if ($data instanceof EmailLink) {
            $data = ['id' => $data->getId()];
        }

        $this->assertSame([$status, $body, $log], [$response->getStatus(), $data, $this->log]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: array<string, mixed>, 3: array<int, string>}> */
    public static function destroyProvider(): array
    {
        $path = ['setRegister:reg', 'setSchema:sch', 'setObject:obj'];
        $upTo = array_merge(['isMailAvailable'], $path, ['getObject', 'hasPermission:update']);
        return [
            'unlinked'        => [[], 200, ['success' => true], array_merge($upTo, ['unlinkEmail:obj-1:7'])],
            'mail missing'    => [['mail' => false], 501, ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'], ['isMailAvailable']],
            'object null'     => [['object' => null], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'object missing'  => [['object' => 'missing'], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'no update right' => [['update' => false], 403, ['error' => 'You may not change this object, so you cannot change its emails.'], $upTo],
            'no uuid'         => [['object' => 'nouuid'], 404, ['error' => 'Email link not found'], $upTo],
            'not this object' => [['fail' => ['unlink', 404]], 404, ['error' => 'failed unlink'], array_merge($upTo, ['unlinkEmail:obj-1:7'])],
            'other error'     => [['fail' => ['unlink', 403]], 400, ['error' => 'failed unlink'], array_merge($upTo, ['unlinkEmail:obj-1:7'])],
        ];
    }

    #[DataProvider('destroyProvider')]
    public function testDestroy(array $scenario, int $status, array $body, array $log): void
    {
        $response = $this->controller($scenario)->destroy('reg', 'sch', 'obj', '7x');

        $this->assertSame([$status, $body, $log], [$response->getStatus(), $response->getData(), $this->log]);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: int, 2: array<string, mixed>, 3: array<int, string>}> */
    public static function indexProvider(): array
    {
        $path = ['setRegister:reg', 'setSchema:sch', 'setObject:obj'];
        $upTo = array_merge(['isMailAvailable'], $path, ['getObject', 'getParams']);
        return [
            'paged'          => [['params' => ['limit' => '5', 'offset' => '10']], 200, ['results' => [], 'total' => 0], array_merge($upTo, ['getEmailsForObject:obj-1:5:10'])],
            'unpaged'        => [[], 200, ['results' => [], 'total' => 0], array_merge($upTo, ['getEmailsForObject:obj-1:NULL:NULL'])],
            'mail missing'   => [['mail' => false], 501, ['error' => 'Nextcloud Mail app is not installed', 'code' => 'APP_NOT_AVAILABLE'], ['isMailAvailable']],
            'object null'    => [['object' => null], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'object missing' => [['object' => 'missing'], 404, ['error' => 'Object not found'], array_merge(['isMailAvailable'], $path, ['getObject'])],
            'list error'     => [['fail' => ['list', 400]], 500, ['error' => 'failed list'], array_merge($upTo, ['getEmailsForObject:obj-1:NULL:NULL'])],
        ];
    }

    #[DataProvider('indexProvider')]
    public function testIndex(array $scenario, int $status, array $body, array $log): void
    {
        $response = $this->controller($scenario)->index('reg', 'sch', 'obj');

        $this->assertSame([$status, $body, $log], [$response->getStatus(), $response->getData(), $this->log]);
    }
}
