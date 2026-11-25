<?php

/**
 * SecondFactorReadGate: refuses a read of a scope that requires a verified second factor to a caller without one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCP\Authentication\TwoFactorAuth\IRegistry;
use OCP\ISession;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * A schema, or a register holding it, may declare `"x-openregister-second-factor": true`
 * in its configuration. Reading that scope then requires a verified second
 * factor, and a caller without one is refused, whatever the grants say.
 *
 * The platform enforces the factor and this gate only declares the scope
 * (design D-3). A caller has a verified second factor when Nextcloud has a
 * second-factor provider enabled for the account (backup codes alone do not
 * count, as in Nextcloud itself), which Nextcloud then demands at every
 * interactive login, and the request did not sign in with an app password,
 * which skips the second factor. An anonymous caller has none.
 *
 * The declaration is read on the schema itself and on every register that
 * holds the schema, so the find path and the list path, which both know the
 * schema, decide alike. A register lookup that fails counts as declared
 * (fail-closed). Both answers are kept for the request.
 *
 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
 */
final class SecondFactorReadGate {

	/**
	 * The configuration key a schema or register declares the requirement under.
	 *
	 * @var string
	 */
	public const ANNOTATION = 'x-openregister-second-factor';

	/**
	 * Nextcloud's backup-codes provider, which is not a second factor on its own.
	 *
	 * @var string
	 */
	private const BACKUP_CODES = 'backup_codes';

	/**
	 * The session key Nextcloud sets when the request signed in with an app password.
	 *
	 * @var string
	 */
	private const APP_PASSWORD_KEY = 'app_password';

	/**
	 * Whether each user has a verified second factor, for this request.
	 *
	 * @var array<string, bool>
	 */
	private array $satisfied = [];

	/**
	 * The scope that declares the requirement for each schema id ('' for none), for this request.
	 *
	 * @var array<int|string, string>
	 */
	private array $declaredBy = [];

	/**
	 * Constructor.
	 *
	 * @param IRegistry               $registry  The second-factor providers enabled per account.
	 * @param IUserManager            $users     Resolves the caller's account.
	 * @param ISession                $session   Tells an app-password sign-in apart.
	 * @param RegisterMapperInterface $registers The registers holding a schema.
	 * @param LoggerInterface         $logger    Records each refusal.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function __construct(
		private readonly IRegistry $registry,
		private readonly IUserManager $users,
		private readonly ISession $session,
		private readonly RegisterMapperInterface $registers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why this caller may not read this schema's objects, or null when no second factor stands in the way.
	 *
	 * @param Schema      $schema The schema read.
	 * @param string|null $userId The caller, null when anonymous.
	 *
	 * @return string|null The refusal, naming the requirement and the scope that declares it.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function readRefusal(Schema $schema, ?string $userId): ?string {
		if ($this->hasVerifiedSecondFactor(userId: $userId) === true) {
			return null;
		}

		$scope = $this->declaredBy(schema: $schema);
		if ($scope === '') {
			return null;
		}

		$refusal = sprintf('Reading %s requires a verified second factor', $scope);
		$this->logger->info(message: '[SecondFactorReadGate] ' . $refusal, context: ['user' => ($userId ?? 'anonymous')]);

		return $refusal;
	}//end readRefusal()

	/**
	 * Whether a configuration declares the requirement.
	 *
	 * @param array<string, mixed>|null $configuration A schema's or register's configuration.
	 *
	 * @return bool True only for the literal `true`.
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public static function declares(?array $configuration): bool {
		return (($configuration ?? [])[self::ANNOTATION] ?? false) === true;
	}//end declares()

	/**
	 * Whether the caller has a verified second factor on this request.
	 *
	 * @param string|null $userId The caller, null when anonymous.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/instance-hardening-controls/specs/instance-hardening/spec.md#requirement-a-scope-may-require-a-second-factor-and-a-surface-may-be-bound-to-addresses-req-ihc-003
	 */
	public function hasVerifiedSecondFactor(?string $userId): bool {
		if ($userId === null || $userId === '') {
			return false;
		}

		if (isset($this->satisfied[$userId]) === false) {
			$this->satisfied[$userId] = $this->providerEnabled(userId: $userId) === true
				&& $this->session->get(self::APP_PASSWORD_KEY) === null;
		}

		return $this->satisfied[$userId];
	}//end hasVerifiedSecondFactor()

	/**
	 * Whether a second-factor provider other than backup codes is enabled for the account.
	 *
	 * @param string $userId The account.
	 *
	 * @return bool False when the account or its providers cannot be read.
	 */
	private function providerEnabled(string $userId): bool {
		try {
			$user = $this->users->get($userId);
			if ($user === null) {
				return false;
			}

			foreach ($this->registry->getProviderStates($user) as $provider => $enabled) {
				if ($enabled === true && $provider !== self::BACKUP_CODES) {
					return true;
				}
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[SecondFactorReadGate] The second-factor providers could not be read; treating the caller as without one',
				context: ['user' => $userId, 'error' => $e->getMessage()]
			);
		}

		return false;
	}//end providerEnabled()

	/**
	 * The scope that declares the requirement for this schema, or '' when none does.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string `schema '<title>'`, `register '<title>'` or ''.
	 */
	private function declaredBy(Schema $schema): string {
		$key = ($schema->getId() ?? spl_object_id($schema));
		if (isset($this->declaredBy[$key]) === false) {
			$this->declaredBy[$key] = $this->lookUpScope(schema: $schema);
		}

		return $this->declaredBy[$key];
	}//end declaredBy()

	/**
	 * Read the declaration on the schema and on each register holding it.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return string The declaring scope, or ''.
	 */
	private function lookUpScope(Schema $schema): string {
		if (self::declares(configuration: $schema->getConfiguration()) === true) {
			return sprintf("schema '%s'", (string)$schema->getTitle());
		}

		if ($schema->getId() === null) {
			return '';
		}

		try {
			foreach ($this->registers->getAllRegisterIdsWithSchema(schemaId: (int)$schema->getId()) as $registerId) {
				$register = $this->registers->find(id: $registerId, _rbac: false, _multitenancy: false);
				if (self::declares(configuration: $register->getConfiguration()) === true) {
					return sprintf("register '%s'", (string)$register->getTitle());
				}
			}
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[SecondFactorReadGate] The registers of a schema could not be read; requiring a second factor (fail-closed)',
				context: ['schema' => $schema->getId(), 'error' => $e->getMessage()]
			);
			return sprintf("schema '%s'", (string)$schema->getTitle());
		}

		return '';
	}//end lookUpScope()
}//end class
