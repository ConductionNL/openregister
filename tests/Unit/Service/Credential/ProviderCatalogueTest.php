<?php

/**
 * ProviderCatalogueTest — unit tests for the read-only provider catalogue loader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use OCA\OpenRegister\Service\Credential\ProviderCatalogue;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Credential\ProviderCatalogue
 */
class ProviderCatalogueTest extends TestCase {
	private ProviderCatalogue $catalogue;

	protected function setUp(): void {
		// Repo root = four levels up from tests/Unit/Service/Credential.
		$appRoot = dirname(__DIR__, 4);
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($appRoot);

		$this->catalogue = new ProviderCatalogue(
			$appManager,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testLoadsGithubEntry(): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);
		$this->assertSame('https://api.github.com', $github['baseUrl']);
		$this->assertSame('Authorization', $github['authScheme']['header']);
		$this->assertStringContainsString('{secret}', $github['authScheme']['template']);
	}

	/**
	 * #2165 — a label write is how hydra's pipeline is COMMANDED, and the
	 * catalogue had nothing matching issue labels, so the broker refused it even
	 * with a valid PAT. Pin the three issue-workflow grants by (method, pattern)
	 * against a realistic path, so a rewording of a pattern that stops matching
	 * a real GitHub URL fails here rather than at runtime with a 403.
	 *
	 * @dataProvider githubIssueWorkflowCallProvider
	 */
	public function testGithubAllowsTheIssueWorkflowCalls(string $method, string $path): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);

		$matched = false;
		foreach ($github['allowRules'] as $rule) {
			if (strtoupper((string)($rule['method'] ?? '')) === $method
				&& fnmatch((string)($rule['pathPattern'] ?? ''), $path) === true
			) {
				$matched = true;
				break;
			}
		}

		$this->assertTrue($matched, 'github must allow ' . $method . ' ' . $path);
	}//end testGithubAllowsTheIssueWorkflowCalls()

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function githubIssueWorkflowCallProvider(): array {
		return [
			'add a label' => ['POST', '/repos/ConductionNL/hydra/issues/12/labels'],
			'remove a label' => ['DELETE', '/repos/ConductionNL/hydra/issues/12/labels/needs-review'],
			'close an issue' => ['PATCH', '/repos/ConductionNL/hydra/issues/12'],
		];
	}//end githubIssueWorkflowCallProvider()

	/**
	 * The issue grants must stay bounded to /issues/ — the point of adding them
	 * is a narrow workflow grant, not a second route to repo or content writes.
	 */
	public function testGithubIssueGrantsDoNotReachRepoOrContentWrites(): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);

		foreach ([['DELETE', '/repos/ConductionNL/hydra'], ['DELETE', '/repos/ConductionNL/hydra/contents/README.md'], ['PATCH', '/repos/ConductionNL/hydra']] as [$method, $path]) {
			foreach ($github['allowRules'] as $rule) {
				if (strtoupper((string)($rule['method'] ?? '')) !== $method) {
					continue;
				}

				$this->assertFalse(
					fnmatch((string)$rule['pathPattern'], $path),
					'github must not allow ' . $method . ' ' . $path . ' via ' . $rule['pathPattern']
				);
			}
		}
	}//end testGithubIssueGrantsDoNotReachRepoOrContentWrites()

	public function testLoadsGitlabEntry(): void {
		$gitlab = $this->catalogue->get('gitlab');
		$this->assertIsArray($gitlab);
		$this->assertSame('https://gitlab.com/api/v4', $gitlab['baseUrl']);
		$this->assertStringContainsString('Bearer', $gitlab['authScheme']['template']);
	}

	/**
	 * GitLab has no labels sub-resource — labels, state and assignee are fields
	 * of the issue, mutated with `PUT /projects/:id/issues/:iid`. So the fleet's
	 * GitLab equivalent of the three GitHub grants is this single rule.
	 */
	public function testGitlabAllowsTheIssueUpdateCall(): void {
		$gitlab = $this->catalogue->get('gitlab');
		$this->assertIsArray($gitlab);

		$matched = false;
		foreach ($gitlab['allowRules'] as $rule) {
			if (strtoupper((string)($rule['method'] ?? '')) === 'PUT'
				&& fnmatch((string)($rule['pathPattern'] ?? ''), '/projects/42/issues/7') === true
			) {
				$matched = true;
				break;
			}
		}

		$this->assertTrue($matched, 'gitlab must allow PUT /projects/*/issues/*');
	}//end testGitlabAllowsTheIssueUpdateCall()

	public function testUnknownProviderReturnsNull(): void {
		$this->assertNull($this->catalogue->get('does-not-exist'));
	}

	public function testAllReturnsBothProviders(): void {
		$all = $this->catalogue->all();
		$this->assertArrayHasKey('github', $all);
		$this->assertArrayHasKey('gitlab', $all);
	}

	// -------------------------------------------------------------------------
	// Fleet providers (2026-07-12). The catalogue used to hold only github /
	// gitlab / doffin, and create() rejects an unknown provider — so the fleet's
	// real credentials (Mollie, Stripe, KVK, …) could not be brokered AT ALL, and
	// every app kept custody of its own secrets because it had no other option.
	// -------------------------------------------------------------------------

	/**
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function fleetProviderProvider(): array {
		return [
			'mollie' => ['mollie', 'https://api.mollie.com', 'Authorization'],
			'stripe' => ['stripe', 'https://api.stripe.com', 'Authorization'],
			'adyen' => ['adyen', 'https://checkout-live.adyen.com', 'X-API-Key'],
			'adyen-test' => ['adyen-test', 'https://checkout-test.adyen.com', 'X-API-Key'],
			'ccv' => ['ccv', 'https://api.psp.ccv.eu', 'Authorization'],
			'ccv-sandbox' => ['ccv-sandbox', 'https://api.psp.sandbox.ccv.eu', 'Authorization'],
			'kvk' => ['kvk', 'https://api.kvk.nl', 'apikey'],
			'twilio' => ['twilio', 'https://api.twilio.com', 'Authorization'],
			'messagebird' => ['messagebird', 'https://rest.messagebird.com', 'Authorization'],
			'cmcom' => ['cmcom', 'https://gw.cmtelecom.com', 'X-CM-PRODUCTTOKEN'],
			'openai' => ['openai', 'https://api.openai.com', 'Authorization'],
			'fireworks' => ['fireworks', 'https://api.fireworks.ai', 'Authorization'],
		];
	}//end fleetProviderProvider()

	/**
	 * @dataProvider fleetProviderProvider
	 */
	public function testFleetProviderIsBrokerable(string $id, string $baseUrl, string $header): void {
		$entry = $this->catalogue->get($id);

		$this->assertIsArray($entry, $id . ' must exist, or its credential cannot even be created');
		$this->assertSame($id, $entry['identifier']);
		$this->assertSame($baseUrl, $entry['baseUrl']);
		$this->assertSame($header, $entry['authScheme']['header']);
		$this->assertStringContainsString('{secret}', $entry['authScheme']['template']);
	}//end testFleetProviderIsBrokerable()

	// -------------------------------------------------------------------------
	// Security invariants. The allow-rules ARE the security control — they bound
	// what any credential can ever do, and they cannot be widened at runtime. So
	// lock the shape here: a future entry that grants an unsanctioned DELETE, or
	// wildcards the whole host, or forgets the host-lock, fails this test rather
	// than shipping.
	// -------------------------------------------------------------------------

	/**
	 * The catalogue's ONE sanctioned DELETE, as `provider => [pathPattern, …]`.
	 *
	 * DELETE stays banned by default. The ban exists so the broker can never be
	 * the thing that removes a repository, a release or file content — an
	 * irreversible act performed with someone else's token. Removing one named
	 * label from one issue is not that: it destroys no content, is reversible by
	 * the `POST …/labels` rule that sits beside it, and is the other half of the
	 * label write hydra's pipeline is commanded with (#2165). A label that can
	 * only ever be added cannot express a state transition.
	 *
	 * Any DELETE rule NOT listed here still fails the test, so widening this
	 * remains a visible, reviewed diff — which is the whole point of the
	 * tripwire. Add to this list only alongside the reasoning for it.
	 *
	 * @var array<string, list<string>>
	 */
	private const SANCTIONED_DELETE_RULES = [
		'github' => ['/repos/*/issues/*/labels/*'],
	];

	public function testNoProviderGrantsDeleteBeyondTheSanctionedLabelRemoval(): void {
		foreach ($this->catalogue->all() as $id => $entry) {
			foreach (($entry['allowRules'] ?? []) as $rule) {
				if (strtoupper((string)($rule['method'] ?? '')) !== 'DELETE') {
					continue;
				}

				$this->assertContains(
					(string)($rule['pathPattern'] ?? ''),
					(self::SANCTIONED_DELETE_RULES[$id] ?? []),
					$id . ' must not grant DELETE through the broker beyond its sanctioned rules'
				);
			}
		}
	}//end testNoProviderGrantsDeleteBeyondTheSanctionedLabelRemoval()

	/**
	 * The sanctioned DELETE is not merely permitted — it must actually be there,
	 * or the label-removal half of the pipeline command silently regresses.
	 */
	public function testTheSanctionedLabelRemovalRuleIsPresent(): void {
		$github = $this->catalogue->get('github');
		$this->assertIsArray($github);

		$deletes = [];
		foreach ($github['allowRules'] as $rule) {
			if (strtoupper((string)($rule['method'] ?? '')) === 'DELETE') {
				$deletes[] = (string)($rule['pathPattern'] ?? '');
			}
		}

		$this->assertSame(['/repos/*/issues/*/labels/*'], $deletes);
	}//end testTheSanctionedLabelRemovalRuleIsPresent()

	public function testNoProviderWildcardsItsWholeApiSurface(): void {
		foreach ($this->catalogue->all() as $id => $entry) {
			foreach (($entry['allowRules'] ?? []) as $rule) {
				$path = (string)($rule['pathPattern'] ?? '');

				$this->assertNotSame('/*', $path, $id . ' must not grant its entire API surface');
				$this->assertNotSame('*', $path, $id . ' must not grant its entire API surface');
				$this->assertStringStartsWith('/', $path, $id . ' rule path must be absolute');
			}
		}
	}//end testNoProviderWildcardsItsWholeApiSurface()

	public function testEveryProxyProviderIsHostLockedOverHttps(): void {
		foreach ($this->catalogue->all() as $id => $entry) {
			// Inject-only providers are NEVER proxied (request() refuses them), so
			// they deliberately carry no baseUrl to host-lock — they are covered by
			// the inverse invariant below instead.
			if (($entry['inject_only'] ?? false) === true) {
				continue;
			}

			// An entry whose API host belongs to the connected ACCOUNT rather than to
			// the provider declares `baseUrlFrom` instead. That MOVES the lock rather
			// than dropping it: the host is validated at mint, pinned onto the
			// credential, and immutable afterwards, so the credential is locked to
			// one server for its whole life. What must never happen is an entry
			// carrying BOTH, because then two different values could each claim to be
			// the lock and the answer would depend on which code path read it first.
			$baseUrlFrom = trim((string)($entry['baseUrlFrom'] ?? ''));
			if ($baseUrlFrom !== '') {
				$this->assertArrayNotHasKey(
					'baseUrl',
					$entry,
					$id . ' declares a per-credential host, so it must not also carry a fixed one'
				);
				$this->assertNotEmpty($entry['allowRules'] ?? [], $id . ' must still bound its calls with allow-rules');
				continue;
			}

			$baseUrl = (string)($entry['baseUrl'] ?? '');

			// resolveAndLockUrl() parses the host out of baseUrl and refuses any
			// resolved URL that leaves it. An empty or non-https baseUrl would
			// defeat that lock.
			$this->assertStringStartsWith('https://', $baseUrl, $id . ' must be https');
			$this->assertIsString(parse_url($baseUrl, PHP_URL_HOST), $id . ' must have a lockable host');
		}
	}//end testEveryProxyProviderIsHostLockedOverHttps()

	public function testAnIdentityCallIsAlwaysOneTheEntryAlreadyPermits(): void {
		// An `identity` block says which of a provider's calls answers "who is this".
		// It must never be a way to reach a path the allow-rules do not already
		// cover, because that would let the catalogue widen itself in a place nobody
		// reviews as a permission.
		$declared = 0;
		foreach ($this->catalogue->all() as $id => $entry) {
			$identity = ($entry['identity'] ?? null);
			if (is_array($identity) === false) {
				continue;
			}

			$declared++;
			$path = (string)($identity['path'] ?? '');
			$method = strtoupper((string)($identity['method'] ?? 'GET'));

			$permitted = false;
			foreach (($entry['allowRules'] ?? []) as $rule) {
				if (strtoupper((string)($rule['method'] ?? '')) !== $method) {
					continue;
				}

				$pattern = (string)($rule['pathPattern'] ?? '');
				if ($pattern === $path || fnmatch($pattern, $path) === true) {
					$permitted = true;
					break;
				}
			}

			$this->assertTrue($permitted, $id . ' declares an identity call its own allow-rules do not permit');
			$this->assertNotSame('', (string)($identity['handleField'] ?? ''), $id . ' must say which field is the handle');
		}

		$this->assertGreaterThan(0, $declared, 'at least one provider must declare how to read its account identity');
	}//end testAnIdentityCallIsAlwaysOneTheEntryAlreadyPermits()

	public function testInjectOnlyProvidersCarryNoProxyAffordance(): void {
		$injectOnly = 0;
		foreach ($this->catalogue->all() as $id => $entry) {
			if (($entry['inject_only'] ?? false) !== true) {
				continue;
			}

			$injectOnly++;

			// An inject-only provider must have NO baseUrl and NO allowRules: those
			// are exactly the proxy affordances, and request() refuses to proxy it.
			// Its secret is only ever reachable app-side via resolveInjectable().
			$this->assertArrayNotHasKey('baseUrl', $entry, $id . ' (inject-only) must not carry a baseUrl');
			$this->assertArrayNotHasKey('allowRules', $entry, $id . ' (inject-only) must not carry allowRules');
			$this->assertNotEmpty($entry['authScheme']['header'] ?? '', $id . ' must still describe its auth header');
		}

		// Guard against the flag silently disappearing from the catalogue.
		$this->assertGreaterThan(0, $injectOnly, 'the catalogue must ship at least one inject-only provider');
	}//end testInjectOnlyProvidersCarryNoProxyAffordance()

	public function testEverySecretIsCarriedInASingleHeaderTemplate(): void {
		foreach ($this->catalogue->all() as $id => $entry) {
			$scheme = ($entry['authScheme'] ?? []);

			// injectAuth() can only substitute {secret} into ONE header. An entry
			// without the placeholder would silently send an unauthenticated call.
			$this->assertNotEmpty($scheme['header'] ?? '', $id . ' must name an auth header');
			$this->assertStringContainsString(
				'{secret}',
				(string)($scheme['template'] ?? ''),
				$id . ' template must carry the {secret} placeholder'
			);
		}
	}//end testEverySecretIsCarriedInASingleHeaderTemplate()

	/**
	 * The pushing credential is a SEPARATE, inject-only entry — and `github` is not.
	 *
	 * `git push` needs a credential, not a proxied API call: git speaks the
	 * smart-HTTP pack protocol, so there is no single request for the broker to
	 * make and no header to substitute. The host-locked `github` entry therefore
	 * returns null from `resolveInjectable()` by design, and that null is a
	 * ROUTING signal, not a denial — but `request()` cannot express a push, so a
	 * pushing stage had no compliant credential home at all.
	 *
	 * The half that matters most is the second assertion. Making `github`
	 * inject-only would have been the one-line version of this change and would
	 * have silently widened EVERY existing github credential in the fleet from
	 * "its secret never leaves OpenRegister" to "its secret is handed to the
	 * calling app". Two entries keeps the pushing credential greppable,
	 * reviewable and revocable on its own.
	 */
	public function testTheGithubPushCredentialIsInjectOnlyAndTheProxyEntryIsNot(): void {
		$push = $this->catalogue->get('github-push');
		$this->assertNotNull($push, 'github-push must exist: without it a push has no brokered credential');
		$this->assertTrue(($push['inject_only'] ?? false), 'github-push must be inject-only or it cannot be resolved');
		$this->assertArrayNotHasKey('baseUrl', $push, 'an inject-only entry must carry no proxy affordance');
		$this->assertArrayNotHasKey('allowRules', $push, 'an inject-only entry must carry no proxy affordance');

		$proxy = $this->catalogue->get('github');
		$this->assertNotNull($proxy);
		$this->assertNotTrue(
			($proxy['inject_only'] ?? false),
			'the host-locked github entry must stay a PROXY entry — flipping it would hand every existing '
			. 'github credential in the fleet to its calling app'
		);
		$this->assertArrayHasKey('baseUrl', $proxy);
	}//end testTheGithubPushCredentialIsInjectOnlyAndTheProxyEntryIsNot()

	/**
	 * No entry grants workflow write, and none ever should.
	 *
	 * A credential that can edit `.github/workflows` obtains code execution on
	 * the forge's runners, which escapes every other control around it. The
	 * catalogue carried a workflow-dispatch grant once (openregister#2240) and
	 * reverted it (#2242); this is the assertion that would have caught it.
	 *
	 * ⚠️ It bounds the CATALOGUE, not the token. Whether the stored secret
	 * itself carries workflow permission is a property of the forge credential
	 * and cannot be asserted from here — see the github-push $comment for the
	 * probe that verifies it before the secret is stored.
	 */
	public function testNoProviderCanReachAWorkflowDefinition(): void {
		foreach ($this->catalogue->all() as $id => $entry) {
			foreach (($entry['allowRules'] ?? []) as $rule) {
				$path = (string)($rule['pathPattern'] ?? '');
				$this->assertStringNotContainsStringIgnoringCase(
					'workflow',
					$path,
					$id . ' grants ' . $path . ' — a workflow write is code execution on the forge runners'
				);
				$this->assertStringNotContainsStringIgnoringCase(
					'/actions',
					$path,
					$id . ' grants ' . $path . ' — the actions surface can run arbitrary workflows'
				);
			}
		}
	}//end testNoProviderCanReachAWorkflowDefinition()

}//end class
