<?php

namespace Unit\Twig;

use OCA\OpenRegister\Db\Source;
use OCA\OpenRegister\Service\AuthenticationService;
use OCA\OpenRegister\Twig\AuthenticationRuntime;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Twig\Extension\RuntimeExtensionInterface;

class AuthenticationRuntimeTest extends TestCase {
	private AuthenticationService&MockObject $authService;
	private AuthenticationRuntime $runtime;

	protected function setUp(): void {
		$this->authService = $this->createMock(AuthenticationService::class);
		$this->runtime = new AuthenticationRuntime($this->authService);
	}

	/**
	 * A real Source carrying an auth config.
	 *
	 * This helper used to hand back a double declaring getConfiguration()
	 * through addMethods(). Source has no `configuration` property, so the
	 * double invented the accessor and the whole file stayed green while
	 * every templated token threw "configuration is not a valid attribute".
	 * authConfig is a real property, so a real entity answers it.
	 *
	 * @param array|null $authConfig The credentials to return
	 * @return Source
	 */
	private function createSourceWithConfig(?array $authConfig): Source {
		$source = new Source();
		$source->setAuthConfig($authConfig);
		return $source;
	}

	public function testImplementsRuntimeExtensionInterface(): void {
		$this->assertInstanceOf(RuntimeExtensionInterface::class, $this->runtime);
	}

	// --- oauthToken() ---
	//
	// The fixtures below are flat credential maps, because that is what
	// Source::authConfig holds and what AuthenticationService reads
	// (grant_type, tokenUrl, api_key...). They used to be wrapped in an
	// `authentication` key, the shape of a Source `configuration` field that
	// does not exist on the entity, in the database or in the API.

	public function testOauthTokenCallsAuthService(): void {
		$source = $this->createSourceWithConfig(['grant_type' => 'client_credentials', 'client_id' => 'abc']);

		$this->authService->expects($this->once())
			->method('fetchOAuthTokens')
			->with(['grant_type' => 'client_credentials', 'client_id' => 'abc'])
			->willReturn('oauth-token-123');

		$this->assertSame('oauth-token-123', $this->runtime->oauthToken($source));
	}

	public function testOauthTokenPassesAnEmptyMapWhenTheSourceHasNoCredentials(): void {
		$source = $this->createSourceWithConfig(null);

		$this->authService->expects($this->once())
			->method('fetchOAuthTokens')
			->with([])
			->willReturn('');

		$this->assertSame('', $this->runtime->oauthToken($source));
	}

	// --- decosToken() ---

	public function testDecosTokenCallsAuthService(): void {
		$source = $this->createSourceWithConfig(['api_key' => 'decos-key']);

		$this->authService->expects($this->once())
			->method('fetchDecosToken')
			->with(['api_key' => 'decos-key'])
			->willReturn('decos-token-456');

		$this->assertSame('decos-token-456', $this->runtime->decosToken($source));
	}

	public function testDecosTokenPassesAnEmptyMapWhenTheSourceHasNoCredentials(): void {
		$source = $this->createSourceWithConfig([]);

		$this->authService->expects($this->once())
			->method('fetchDecosToken')
			->with([])
			->willReturn('');

		$this->assertSame('', $this->runtime->decosToken($source));
	}

	// --- jwtToken() ---

	public function testJwtTokenCallsAuthService(): void {
		$source = $this->createSourceWithConfig(['secret' => 'jwt-secret', 'issuer' => 'test']);

		$this->authService->expects($this->once())
			->method('fetchJWTToken')
			->with(['secret' => 'jwt-secret', 'issuer' => 'test'])
			->willReturn('jwt-token-789');

		$this->assertSame('jwt-token-789', $this->runtime->jwtToken($source));
	}

	public function testJwtTokenPassesAnEmptyMapWhenTheSourceHasNoCredentials(): void {
		$source = $this->createSourceWithConfig(null);

		$this->authService->expects($this->once())
			->method('fetchJWTToken')
			->with([])
			->willReturn('');

		$this->assertSame('', $this->runtime->jwtToken($source));
	}
}
