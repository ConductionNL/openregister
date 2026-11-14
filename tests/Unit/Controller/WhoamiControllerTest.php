<?php

/**
 * WhoamiController test: the calling principal and its effective grant.
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
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Controller;

use DateTimeImmutable;
use OCA\OpenRegister\Controller\WhoamiController;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantReach;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(WhoamiController::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(TokenGrantReach::class)]
#[UsesClass(TokenGrantTerms::class)]
class WhoamiControllerTest extends TestCase {

	private TokenGrantSource $grants;

	protected function setUp(): void {
		$this->grants = new TokenGrantSource();
	}//end setUp()

	private function controller(?string $uid): WhoamiController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		return new WhoamiController($this->createMock(IRequest::class), $session, $this->grants);
	}//end controller()

	public function testWithoutASignedInUserItAnswers401(): void {
		$response = $this->controller(null)->show();

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'not-logged-in'], $response->getData());
	}//end testWithoutASignedInUserItAnswers401()

	public function testASessionWithoutATokenHasNoGrant(): void {
		$response = $this->controller('alice')->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['user' => 'alice', 'actorVia' => null, 'grant' => null, 'expired' => false], $response->getData());
	}//end testASessionWithoutATokenHasNoGrant()

	public function testATokenCallerSeesItsGrantAndActorVia(): void {
		$expires = new DateTimeImmutable('+30 days');
		$this->grants->bind(
			new TokenGrant(
				verbs: ['read'],
				reach: new TokenGrantReach(registers: null, schemas: ['zaak'], match: ['leverancier' => 'acme']),
				terms: new TokenGrantTerms(expiresAt: $expires, rateLimit: 60),
				tokenId: 'consumer-42'
			)
		);

		$data = $this->controller('leverancier-acme')->show()->getData();

		$this->assertSame('leverancier-acme', $data['user']);
		$this->assertSame('consumer-42', $data['actorVia']);
		$this->assertFalse($data['expired']);
		$this->assertSame(
			[
				'verbs' => ['read'],
				'registers' => null,
				'schemas' => ['zaak'],
				'match' => ['leverancier' => 'acme'],
				'expiresAt' => $expires->format(DATE_ATOM),
				'rateLimit' => 60,
				'tokenId' => 'consumer-42',
			],
			$data['grant']
		);
	}//end testATokenCallerSeesItsGrantAndActorVia()

	public function testALapsedGrantIsReportedAsExpired(): void {
		$this->grants->bind(new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('-1 day')), tokenId: 'consumer-7'));

		$data = $this->controller('bob')->show()->getData();

		$this->assertTrue($data['expired']);
		$this->assertSame('consumer-7', $data['actorVia']);
	}//end testALapsedGrantIsReportedAsExpired()

	public function testAGrantWithoutATokenIdNamesNoActorVia(): void {
		$this->grants->bind(new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('+1 day'))));

		$this->assertNull($this->controller('bob')->show()->getData()['actorVia']);
	}//end testAGrantWithoutATokenIdNamesNoActorVia()

	public function testAnyAuthenticatedCallerMayAskForThemselves(): void {
		$method = new ReflectionMethod(WhoamiController::class, 'show');

		$this->assertCount(1, $method->getAttributes(NoAdminRequired::class));
		$this->assertCount(1, $method->getAttributes(NoCSRFRequired::class));
		$this->assertSame(0, $method->getNumberOfParameters(), 'no id parameter: only the caller is ever answered');
	}//end testAnyAuthenticatedCallerMayAskForThemselves()

	public function testTheRouteIsAGet(): void {
		$routes = require __DIR__ . '/../../../appinfo/routes.php';
		$found = array_values(array_filter($routes['routes'], static fn (array $route): bool => $route['name'] === 'whoami#show'));

		$this->assertSame(['/api/whoami'], array_values(array_unique(array_map(static fn (array $route): string => preg_replace('#^/api/v\d+/#', '/api/', $route['url']), $found))));
		$this->assertSame(['GET'], array_values(array_unique(array_column($found, 'verb'))));
	}//end testTheRouteIsAGet()
}//end class
