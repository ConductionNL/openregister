<?php

/**
 * OpenRegister PromotionService
 *
 * Promotion between environments: the source organisation's configuration lands in
 * the target as a draft set, reviewed, approved and deployed like any other.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Organisation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Organisation;
use DateTimeImmutable;
use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntry;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditEntryWriterInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionLandingInterface;
use OCA\OpenRegister\Kernel\Contracts\PromotionRefusedException;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Export, check the OTAP order, remap uuids, land as a draft set, audit both sides.
 *
 * Nothing here writes a live value. The package becomes drafts in a set named
 * "Promotion from {source}"; the target's preview, approval and deployment
 * are those of every other set, and the deployment's undo record is the
 * rollback snapshot. Each component is drafted at its own address: a register
 * at the register layer, everything else at the subject layer, keyed by the
 * uuid it gets in the target, so two promotions of the same component land on
 * different addresses and never overwrite each other's review.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The organisations, the package, the landing and the audit
 *     trail are the four sides of one promotion.
 *
 * @spec openspec/specs/environment-otap/spec.md#requirement-configuration-promotion-must-transfer-settings-between-otap-environments
 */
class PromotionService implements PromotionServiceInterface {

	/**
	 * The refusal reason of a promotion against the OTAP order.
	 *
	 * @var string
	 */
	public const REASON_INVALID = 'invalid';

	/**
	 * The refusal reason of an unknown organisation.
	 *
	 * @var string
	 */
	public const REASON_UNKNOWN = 'unknown';

	/**
	 * The refusal reason of a source with nothing to promote.
	 *
	 * @var string
	 */
	public const REASON_EMPTY = 'empty';

	/**
	 * The audit action written in both organisations.
	 *
	 * @var string
	 */
	public const ACTION_PROMOTED = 'organisation.configuration_promoted';

	/**
	 * The refusal message for a promotion against the OTAP order.
	 *
	 * @var string
	 */
	public const ORDER_MESSAGE = 'Promotion must follow OTAP order: development -> test -> acceptance -> production';

	/**
	 * The environment an organisation without one is in, as Organisation::jsonSerialize() reports it.
	 *
	 * @var string
	 */
	private const DEFAULT_ENVIRONMENT = TenantLifecycleServiceInterface::ENV_PRODUCTION;

	/**
	 * The draft address of each component kind: the layer and the configuration key.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ADDRESSES = [
		PromotionComponent::TYPE_REGISTER => [PromotionLandingInterface::LAYER_REGISTER, 'register.definition'],
		PromotionComponent::TYPE_SCHEMA => [PromotionLandingInterface::LAYER_SUBJECT, 'schema.definition'],
		PromotionComponent::TYPE_MAPPING => [PromotionLandingInterface::LAYER_SUBJECT, 'integration.mapping'],
		PromotionComponent::TYPE_SOURCE => [PromotionLandingInterface::LAYER_SUBJECT, 'integration.source'],
		PromotionComponent::TYPE_WEBHOOK => [PromotionLandingInterface::LAYER_SUBJECT, 'integration.webhook'],
		PromotionComponent::TYPE_ENDPOINT => [PromotionLandingInterface::LAYER_SUBJECT, 'integration.endpoint'],
	];

	/**
	 * Constructor.
	 *
	 * @param OrganisationMapperInterface     $organisations The organisations.
	 * @param PromotionPackageReaderInterface $package       What the source organisation holds.
	 * @param PromotionLandingInterface       $landing       The draft set the package lands in.
	 * @param AuditEntryWriterInterface       $auditEntries  The audit trail.
	 * @param IUserSession                    $userSession   Who promotes.
	 * @param LoggerInterface                 $logger        The logger.
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisations,
		private readonly PromotionPackageReaderInterface $package,
		private readonly PromotionLandingInterface $landing,
		private readonly AuditEntryWriterInterface $auditEntries,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Export the source organisation's configuration and land it in the target as a draft set.
	 *
	 * @param string $sourceUuid The organisation promoted from.
	 * @param string $targetUuid The organisation promoted to.
	 *
	 * @return array{set: array<string, mixed>, source: string, target: string, components: array<string, int>, remapped: array<string, string>}
	 *     The landed set, both organisations, the drafted components by kind and target uuid by source uuid.
	 *
	 * @throws PromotionRefusedException When an organisation is unknown, the OTAP order is not followed or there is nothing to promote.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public function promote(string $sourceUuid, string $targetUuid): array {
		$source = $this->organisation(uuid: $sourceUuid);
		$target = $this->organisation(uuid: $targetUuid);
		self::requireOtapOrder(source: $source, target: $target);

		$components = $this->package->componentsOf(organisationUuid: $sourceUuid);
		if ($components === []) {
			throw new PromotionRefusedException(
				reason: self::REASON_EMPTY,
				message: sprintf('organisation "%s" holds no configuration to promote', $sourceUuid),
				refusals: [],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		$remapped = self::remapTable(components: $components);
		$remap    = new PromotionRemap(targetUuid: $targetUuid, uuids: $remapped);
		$drafts   = self::drafts(components: $components, remap: $remap);
		$set      = $this->landing->land(
			name: sprintf('Promotion from %s', ($source->getName() ?? $sourceUuid)),
			description: sprintf(
				'Configuration promoted from %s (%s) to %s (%s)',
				$sourceUuid,
				self::environmentOf(organisation: $source),
				$targetUuid,
				self::environmentOf(organisation: $target)
			),
			drafts: $drafts
		);

		$counts = [];
		foreach ($components as $component) {
			$counts[$component->type] = (($counts[$component->type] ?? 0) + 1);
		}

		$result = [
			'set' => $set,
			'source' => $sourceUuid,
			'target' => $targetUuid,
			'components' => $counts,
			'remapped' => $remapped,
		];

		$this->audit(source: $source, target: $target, result: $result);

		return $result;

	}//end promote()

	/**
	 * One organisation, or a 404 refusal naming it.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return Organisation The organisation.
	 *
	 * @throws PromotionRefusedException When no such organisation exists.
	 */
	private function organisation(string $uuid): Organisation {
		try {
			return $this->organisations->findByUuid($uuid);
		} catch (DoesNotExistException $exception) {
			throw new PromotionRefusedException(
				reason: self::REASON_UNKNOWN,
				message: sprintf('no organisation "%s"', $uuid),
				refusals: [],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

	}//end organisation()

	/**
	 * Refuse unless the source's environment comes strictly before the target's.
	 *
	 * The same organisation, the same environment, a step backwards and an
	 * environment outside the four are all refused with the one message that
	 * names the order: each is a promotion that does not move forward.
	 *
	 * @param Organisation $source The organisation promoted from.
	 * @param Organisation $target The organisation promoted to.
	 *
	 * @return void
	 *
	 * @throws PromotionRefusedException When the order is not followed.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md#8-promotion-between-environments-row-op-otap
	 */
	public static function requireOtapOrder(Organisation $source, Organisation $target): void {
		$order      = TenantLifecycleServiceInterface::OTAP_ORDER;
		$sourceRank = ($order[self::environmentOf(organisation: $source)] ?? null);
		$targetRank = ($order[self::environmentOf(organisation: $target)] ?? null);

		$forward = $sourceRank !== null && $targetRank !== null && $sourceRank < $targetRank && $source->getUuid() !== $target->getUuid();
		if ($forward === true) {
			return;
		}

		throw new PromotionRefusedException(
			reason: self::REASON_INVALID,
			message: self::ORDER_MESSAGE,
			refusals: [
				[
					'source' => ['uuid' => $source->getUuid(), 'environment' => self::environmentOf(organisation: $source)],
					'target' => ['uuid' => $target->getUuid(), 'environment' => self::environmentOf(organisation: $target)],
				],
			],
			statusCode: Http::STATUS_BAD_REQUEST
		);

	}//end requireOtapOrder()

	/**
	 * An organisation's environment, production when it has none.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return string The environment.
	 */
	private static function environmentOf(Organisation $organisation): string {
		$environment = $organisation->getEnvironment();
		if ($environment === null || $environment === '') {
			return self::DEFAULT_ENVIRONMENT;
		}

		return $environment;

	}//end environmentOf()

	/**
	 * A fresh target uuid for every component that has a source uuid.
	 *
	 * @param list<PromotionComponent> $components The package.
	 *
	 * @return array<string, string> Target uuid by source uuid.
	 */
	private static function remapTable(array $components): array {
		$remapped = [];
		foreach ($components as $component) {
			if ($component->sourceUuid !== null && isset($remapped[$component->sourceUuid]) === false) {
				$remapped[$component->sourceUuid] = Uuid::v4()->toRfc4122();
			}
		}

		return $remapped;

	}//end remapTable()

	/**
	 * Every component as a draft at its own address, with its uuids remapped and the target as its organisation.
	 *
	 * @param list<PromotionComponent> $components The package.
	 * @param PromotionRemap           $remap      The target and the uuid table.
	 *
	 * @return list<array{layer: string, layerRef: string, configKey: string, value: mixed}> The drafts.
	 */
	private static function drafts(array $components, PromotionRemap $remap): array {
		$drafts = [];
		foreach ($components as $component) {
			$uuid       = self::targetUuidOf(component: $component, remap: $remap);
			$definition = $remap->apply(value: $component->definition);

			[$layer, $configKey] = self::ADDRESSES[$component->type];
			$drafts[] = [
				'layer' => $layer,
				'layerRef' => $uuid,
				'configKey' => $configKey,
				'value' => array_merge($definition, ['uuid' => $uuid, 'organisation' => $remap->targetUuid]),
			];
		}

		return $drafts;

	}//end drafts()

	/**
	 * The uuid a component gets in the target: its remapped one, or a fresh one when it had none.
	 *
	 * @param PromotionComponent $component The component.
	 * @param PromotionRemap     $remap     The uuid table.
	 *
	 * @return string The target uuid.
	 */
	private static function targetUuidOf(PromotionComponent $component, PromotionRemap $remap): string {
		if ($component->sourceUuid !== null && isset($remap->uuids[$component->sourceUuid]) === true) {
			return $remap->uuids[$component->sourceUuid];
		}

		return Uuid::v4()->toRfc4122();

	}//end targetUuidOf()

	/**
	 * Write the promotion into both organisations' audit trail; never throws.
	 *
	 * The set has been written when this runs. Failing here would report a
	 * promotion that did not happen, so a missing entry is logged at ERROR, as
	 * the organisation lifecycle auditor does.
	 *
	 * @param Organisation         $source The organisation promoted from.
	 * @param Organisation         $target The organisation promoted to.
	 * @param array<string, mixed> $result The promotion result.
	 *
	 * @return void
	 */
	private function audit(Organisation $source, Organisation $target, array $result): void {
		$changed = [
			'promotion' => [
				'old' => null,
				'new' => [
					'source' => $result['source'],
					'target' => $result['target'],
					'set' => ($result['set']['uuid'] ?? null),
					'components' => $result['components'],
				],
			],
		];

		$entries = [
			$this->entryFor(organisation: $source, changed: $changed),
			$this->entryFor(organisation: $target, changed: $changed),
		];

		try {
			$this->auditEntries->writeAuditEntries(entries: $entries);
		} catch (Throwable $error) {
			$this->logger->error(
				message: '[PromotionService] Could not record a promotion in the audit trail; the draft set itself was stored',
				context: ['source' => $result['source'], 'target' => $result['target'], 'exception' => $error->getMessage()]
			);
		}

	}//end audit()

	/**
	 * The audit entry of the promotion in one organisation.
	 *
	 * @param Organisation         $organisation The organisation.
	 * @param array<string, mixed> $changed      What the promotion did.
	 *
	 * @return AuditEntry The entry.
	 */
	private function entryFor(Organisation $organisation, array $changed): AuditEntry {
		$user = $this->userSession->getUser();

		return new AuditEntry(
			uuid: Uuid::v4()->toRfc4122(),
			action: self::ACTION_PROMOTED,
			user: ($user?->getUID() ?? 'system'),
			userName: ($user?->getDisplayName() ?? 'System'),
			changed: $changed,
			created: new DateTimeImmutable(),
			objectUuid: $organisation->getUuid(),
			organisationId: $organisation->getUuid(),
		);

	}//end entryFor()
}//end class
