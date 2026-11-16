<?php

/**
 * PersonalTokenGrantsController: the owner of a personal token puts a grant on
 * it, reads it back and takes it off, from an interactive sign-in only; a grant
 * that cannot be issued is refused with 422 naming why.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\PersonalTokenGrantsController;
use OCA\OpenRegister\Controller\Support\PersonalTokenGrantsServices;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(PersonalTokenGrantsController::class)]
#[UsesClass(PersonalTokenGrantsServices::class)]
#[UsesClass(PersonalTokenGrantStore::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantIssue::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(TokenGrantValidator::class)]
class PersonalTokenGrantsControllerTest extends TestCase {
	/**
	 * Stored user values, keyed by "uid/key".
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	private TokenGrantSource $source;

	protected function setUp(): void {
		$this->source = new TokenGrantSource();
	}//end setUp()

	private function controller(?string $uid, mixed $grantParam = null, bool $tokenRequest = false): PersonalTokenGrantsController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->with('grant')->willReturn($grantParam);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $u, string $app, string $key, $default = ''): string => ($this->values["$u/$key"] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $u, string $app, string $key, $value): void {
				$this->values["$u/$key"] = (string) $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $u, string $app, string $key): void {
				unset($this->values["$u/$key"]);
			}
		);

		$binder = $this->createMock(PersonalTokenGrantBinder::class);
		$binder->method('isTokenRequest')->willReturn($tokenRequest);

		$catalogue = $this->createMock(PermissionCatalogueInterface::class);
		$catalogue->method('verbs')->willReturn(['read', 'create', 'update', 'delete', 'share', 'manage']);

		$services = new PersonalTokenGrantsServices(
			new PersonalTokenGrantStore($config),
			$binder,
			$this->source,
			new TokenGrantValidator($catalogue),
			$catalogue
		);

		return new PersonalTokenGrantsController($request, $session, $services);
	}//end controller()

	private static function validGrant(string $expiresAt = '+30 days'): array {
		return ['verbs' => ['read'], 'schemas' => ['case'], 'expiresAt' => (new \DateTimeImmutable($expiresAt))->format(DATE_ATOM)];
	}//end validGrant()

	public function testTheOwnerPutsAGrantOnTheirToken(): void {
		$grant    = self::validGrant();
		$response = $this->controller('alice', $grant)->update('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('app-password:42', $response->getData()['actorVia']);
		$this->assertSame($grant, $response->getData()['grant']);
		$this->assertSame('alice', $response->getData()['issuedBy']);
		$this->assertSame($grant, json_decode($this->values['alice/token_grant_42'], true)['grant']);
	}//end testTheOwnerPutsAGrantOnTheirToken()

	public function testAGrantWithoutAnEndDateIsRefusedNamingTheRequirement(): void {
		$response = $this->controller('alice', ['verbs' => ['read']])->update('42');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringContainsString('end date', $response->getData()['error']);
		$this->assertSame([], $this->values);
	}//end testAGrantWithoutAnEndDateIsRefusedNamingTheRequirement()

	public function testManageCannotBeGranted(): void {
		$response = $this->controller('alice', ['verbs' => ['manage']] + self::validGrant())->update('42');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame([], $this->values);
	}//end testManageCannotBeGranted()

	public function testAGrantThatIsNotAnObjectIsRefused(): void {
		$response = $this->controller('alice', 'read')->update('42');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['error' => 'a grant must be an object'], $response->getData());
	}//end testAGrantThatIsNotAnObjectIsRefused()

	public function testATokenCannotChangeOrLiftAGrant(): void {
		$this->values['alice/token_grant_42'] = (string) json_encode(['grant' => self::validGrant()]);
		$before = $this->values;

		$update  = $this->controller('alice', ['verbs' => ['read', 'update']] + self::validGrant(), true)->update('42');
		$destroy = $this->controller('alice', null, true)->destroy('42');

		$this->assertSame(Http::STATUS_FORBIDDEN, $update->getStatus());
		$this->assertSame(Http::STATUS_FORBIDDEN, $destroy->getStatus());
		$this->assertSame($before, $this->values);
	}//end testATokenCannotChangeOrLiftAGrant()

	public function testAConsumerBoundRequestCannotChangeAGrant(): void {
		$this->source->bind(new TokenGrant(verbs: ['read'], tokenId: 'consumer-uuid'));

		$response = $this->controller('alice', self::validGrant())->update('42');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->values);
	}//end testAConsumerBoundRequestCannotChangeAGrant()

	public function testShowAnswersTheStoredGrantAndItsRenewals(): void {
		$this->controller('alice', self::validGrant('+10 days'))->update('42');
		$this->controller('alice', self::validGrant('+40 days'))->update('42');

		$response = $this->controller('alice')->show('42');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['readable']);
		$this->assertCount(1, $response->getData()['renewals']);
		$this->assertSame('alice', $response->getData()['renewals'][0]['by']);
	}//end testShowAnswersTheStoredGrantAndItsRenewals()

	public function testShowAnswers404ForATokenWithoutAGrantAndNeverAnotherUsers(): void {
		$this->values['bob/token_grant_42'] = (string) json_encode(['grant' => self::validGrant()]);

		$response = $this->controller('alice')->show('42');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowAnswers404ForATokenWithoutAGrantAndNeverAnotherUsers()

	public function testDestroyTakesTheGrantOff(): void {
		$this->values['alice/token_grant_42'] = (string) json_encode(['grant' => self::validGrant()]);

		$response = $this->controller('alice')->destroy('42');

		$this->assertSame(['tokenId' => '42', 'grant' => null], $response->getData());
		$this->assertSame([], $this->values);
	}//end testDestroyTakesTheGrantOff()

	public function testWithoutASignedInUserEveryActionAnswers401(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->show('42')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, self::validGrant())->update('42')->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->destroy('42')->getStatus());
	}//end testWithoutASignedInUserEveryActionAnswers401()

	public function testEveryActionIsForSignedInUsersAndChangesKeepCsrf(): void {
		foreach (['show', 'update', 'destroy'] as $method) {
			$reflection = new ReflectionMethod(PersonalTokenGrantsController::class, $method);
			$this->assertNotEmpty($reflection->getAttributes(NoAdminRequired::class), $method);
			$this->assertEmpty($reflection->getAttributes(NoCSRFRequired::class), $method);
		}
	}//end testEveryActionIsForSignedInUsersAndChangesKeepCsrf()
}//end class
