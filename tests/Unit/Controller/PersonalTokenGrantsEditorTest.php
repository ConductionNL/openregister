<?php

/**
 * The grant editor's endpoints: the caller's granted tokens, naming an app
 * password by pasting it, the label, and the preview on one object.
 *
 * @category Test
 * @package  Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace Unit\Controller;

use OCA\OpenRegister\Controller\PersonalTokenGrantsController;
use OCA\OpenRegister\Controller\Support\PersonalTokenGrantsServices;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Rbac\AccessReviewGateInterface;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantBinder;
use OCA\OpenRegister\Service\Rbac\PersonalTokenGrantStore;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantIssue;
use OCA\OpenRegister\Service\Rbac\TokenGrantPreview;
use OCA\OpenRegister\Service\Rbac\TokenGrantSource;
use OCA\OpenRegister\Service\Rbac\TokenGrantValidator;
use OCP\AppFramework\Http;
use OCP\Authentication\Exceptions\InvalidTokenException;
use OCP\Authentication\Token\IProvider as ITokenProvider;
use OCP\Authentication\Token\IToken;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(PersonalTokenGrantsController::class)]
#[UsesClass(PersonalTokenGrantsServices::class)]
#[UsesClass(PersonalTokenGrantStore::class)]
#[UsesClass(TokenGrant::class)]
#[UsesClass(TokenGrantIssue::class)]
#[UsesClass(TokenGrantSource::class)]
#[UsesClass(TokenGrantValidator::class)]
#[UsesClass(ObjectRef::class)]
class PersonalTokenGrantsEditorTest extends TestCase {
	/**
	 * Stored user values, keyed by "uid/key", and app values, keyed by key.
	 *
	 * @var array<string, string>
	 */
	private array $values = [];

	private TokenGrantSource $source;

	private ITokenProvider&MockObject $tokens;

	private AccessReviewGateInterface&MockObject $gate;

	private SchemaMapperInterface&MockObject $schemas;

	private TokenGrantPreview&MockObject $preview;

	protected function setUp(): void {
		$this->source  = new TokenGrantSource();
		$this->tokens  = $this->createMock(ITokenProvider::class);
		$this->gate    = $this->createMock(AccessReviewGateInterface::class);
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->preview = $this->createMock(TokenGrantPreview::class);
	}//end setUp()

	/**
	 * @param array<string, mixed> $params The request parameters.
	 */
	private function controller(?string $uid, array $params = [], bool $tokenRequest = false): PersonalTokenGrantsController {
		$session = $this->createMock(IUserSession::class);
		$user    = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => ($params[$key] ?? $default)
		);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $u, string $app, string $key, $default = ''): string => ($this->values["$u/$key"] ?? $default)
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $u, string $app, string $key, $value): void {
				$this->values["$u/$key"] = (string) $value;
			}
		);
		$config->method('getAppValue')->willReturnCallback(
			fn (string $app, string $key, $default = ''): string => ($this->values[$key] ?? $default)
		);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, $value): void {
				$this->values[$key] = (string) $value;
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
			$catalogue,
			$this->tokens,
			$this->gate,
			$this->schemas,
			$this->preview
		);

		return new PersonalTokenGrantsController($request, $session, $services);
	}//end controller()

	private static function validGrant(): array {
		return ['verbs' => ['read'], 'schemas' => ['case'], 'expiresAt' => (new \DateTimeImmutable('+30 days'))->format(DATE_ATOM)];
	}//end validGrant()

	private function token(string $uid, int $id, string $name): IToken {
		$token = $this->createMock(IToken::class);
		$token->method('getUID')->willReturn($uid);
		$token->method('getId')->willReturn($id);
		$token->method('getName')->willReturn($name);

		return $token;
	}//end token()

	public function testIndexListsOnlyTheCallersGrantedTokensWithTheirLabels(): void {
		$this->controller('alice', ['grant' => self::validGrant(), 'label' => '  Supplier sync  '])->update('42');
		$this->controller('bob', ['grant' => self::validGrant()])->update('7');

		$response = $this->controller('alice')->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$results = $response->getData()['results'];
		$this->assertCount(1, $results);
		$this->assertSame('42', $results[0]['tokenId']);
		$this->assertSame('Supplier sync', $results[0]['label']);
		$this->assertSame('app-password:42', $results[0]['actorVia']);
	}//end testIndexListsOnlyTheCallersGrantedTokensWithTheirLabels()

	public function testALaterUpdateWithoutALabelKeepsTheStoredOne(): void {
		$this->controller('alice', ['grant' => self::validGrant(), 'label' => 'Supplier sync'])->update('42');
		$response = $this->controller('alice', ['grant' => self::validGrant()])->update('42');

		$this->assertSame('Supplier sync', $response->getData()['label']);
	}//end testALaterUpdateWithoutALabelKeepsTheStoredOne()

	public function testIndexWithoutAUserIsRefused(): void {
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->index()->getStatus());
	}//end testIndexWithoutAUserIsRefused()

	public function testResolveNamesTheCallersAppPasswordWithoutReturningIt(): void {
		$this->tokens->method('getToken')->with('secret-app-password')->willReturn($this->token('alice', 42, 'Supplier sync'));

		$response = $this->controller('alice', ['password' => ' secret-app-password '])->resolve();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['tokenId' => '42', 'name' => 'Supplier sync', 'grant' => null], $response->getData());
		$this->assertStringNotContainsString('secret-app-password', (string) json_encode($response->getData()));
		$this->assertSame([], $this->values);
	}//end testResolveNamesTheCallersAppPasswordWithoutReturningIt()

	public function testResolveReturnsTheGrantTheTokenAlreadyCarries(): void {
		$this->controller('alice', ['grant' => self::validGrant()])->update('42');
		$this->tokens->method('getToken')->willReturn($this->token('alice', 42, 'Supplier sync'));

		$grant = $this->controller('alice', ['password' => 'p'])->resolve()->getData()['grant'];

		$this->assertSame(self::validGrant()['schemas'], $grant['grant']['schemas']);
	}//end testResolveReturnsTheGrantTheTokenAlreadyCarries()

	public function testSomebodyElsesPasswordAndAnUnknownOneAnswerTheSame404(): void {
		$this->tokens->method('getToken')->willReturnCallback(
			fn (string $password): IToken => ($password === 'bobs' ? $this->token('bob', 7, 'Bob') : throw new InvalidTokenException())
		);

		$other   = $this->controller('alice', ['password' => 'bobs'])->resolve();
		$unknown = $this->controller('alice', ['password' => 'nope'])->resolve();

		$this->assertSame(Http::STATUS_NOT_FOUND, $other->getStatus());
		$this->assertSame($other->getData(), $unknown->getData());
	}//end testSomebodyElsesPasswordAndAnUnknownOneAnswerTheSame404()

	public function testResolveIsRefusedToATokenCallerAndWithoutAPassword(): void {
		$this->tokens->expects($this->never())->method('getToken');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller('alice', ['password' => 'p'], tokenRequest: true)->resolve()->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('alice', ['password' => '  '])->resolve()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null, ['password' => 'p'])->resolve()->getStatus());
	}//end testResolveIsRefusedToATokenCallerAndWithoutAPassword()

	public function testPreviewAsksTheReadableObjectAndItsSchema(): void {
		$object = new ObjectEntity();
		$object->setSchema('12');
		$schema = new Schema();
		$answer = ['verbs' => [['verb' => 'read', 'holder' => true, 'token' => true]], 'expired' => false, 'empty' => false];

		$this->gate->expects($this->once())->method('resolve')
			->with($this->callback(fn (ObjectRef $ref): bool => $ref->register === 'zaken' && $ref->schema === 'case' && $ref->id === 'abc'))
			->willReturn($object);
		$this->schemas->expects($this->once())->method('find')->with('12')->willReturn($schema);
		$this->preview->expects($this->once())->method('preview')
			->with($this->callback(fn (TokenGrant $grant): bool => $grant->verbs === ['read']), $schema, $object)
			->willReturn($answer);

		$response = $this->controller('alice', ['grant' => self::validGrant(), 'register' => 'zaken', 'schema' => 'case', 'object' => 'abc'])->preview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($answer, $response->getData());
	}//end testPreviewAsksTheReadableObjectAndItsSchema()

	public function testPreviewOfAnObjectTheCallerCannotReadIs404(): void {
		$this->gate->method('resolve')->willReturn(null);
		$this->preview->expects($this->never())->method('preview');

		$response = $this->controller('alice', ['grant' => self::validGrant(), 'register' => 'zaken', 'schema' => 'case', 'object' => 'abc'])->preview();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testPreviewOfAnObjectTheCallerCannotReadIs404()

	public function testPreviewWithoutAnObjectOrAGrantIsRefused(): void {
		$this->gate->expects($this->never())->method('resolve');

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('alice', ['grant' => self::validGrant(), 'register' => 'zaken', 'schema' => 'case'])->preview()->getStatus());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $this->controller('alice', ['register' => 'zaken', 'schema' => 'case', 'object' => 'abc'])->preview()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $this->controller(null)->preview()->getStatus());
	}//end testPreviewWithoutAnObjectOrAGrantIsRefused()
}//end class
