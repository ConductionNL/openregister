<?php

/**
 * Promotes one organisation's configuration into another as a draft set.
 *
 * The source organisation's schemas, mappings, sources, webhooks and endpoints
 * are read as a package, their uuids are remapped to fresh ones (and every
 * reference to an old uuid inside the package follows), and the package lands
 * in the TARGET organisation as one draft set named "Promotion from {source}".
 * Nothing is live yet: the target's preview, approval and deployment are the
 * ones every draft set goes through, and the deployment's undo record is the
 * rollback snapshot. One audit entry is written in each organisation.
 *
 * Refusals, all before anything is written:
 * - an unknown organisation is 404;
 * - promoting an organisation into itself, across two organisation trees, or
 *   against the OTAP order (development, test, acceptance, production) is 400;
 * - a source with nothing to promote is 400, because a promotion that lands an
 *   empty set behind a success would say configuration moved when none did.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ConfigurationDeployment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ConfigurationDeployment;

use OCA\OpenRegister\Db\Organisation;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Organisation\OrganisationLifecycleAuditor;
use OCA\OpenRegister\Service\TenantLifecycleServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use Symfony\Component\Uid\Uuid;

/**
 * Configuration promotion between OTAP environments.
 *
 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
 */
class PromotionService implements PromotionServiceInterface {

	/**
	 * The refusal a promotion against the OTAP order answers with, as the spec words it.
	 *
	 * @var string
	 */
	public const ORDER_MESSAGE = 'Promotion must follow OTAP order: development -> test -> acceptance -> production';

	/**
	 * Constructor.
	 *
	 * @param OrganisationMapperInterface  $organisations The organisations.
	 * @param PromotionPackageReader       $packages      Reads an organisation's configuration.
	 * @param ConfigurationDraftsInterface $drafts        The draft sets the package lands in.
	 * @param OrganisationLifecycleAuditor $auditor       Writes the promotion to both audit trails.
	 */
	public function __construct(
		private readonly OrganisationMapperInterface $organisations,
		private readonly PromotionPackageReader $packages,
		private readonly ConfigurationDraftsInterface $drafts,
		private readonly OrganisationLifecycleAuditor $auditor,
	) {
	}//end __construct()

	/**
	 * Promote the source organisation's configuration into the target as a draft set.
	 *
	 * @param string $sourceUuid The organisation whose configuration travels.
	 * @param string $targetUuid The organisation it lands in.
	 *
	 * @return array<string, mixed> The draft set, the component counts and the uuid map.
	 *
	 * @throws DeploymentRefusedException When the promotion is refused; the status code says why.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public function promote(string $sourceUuid, string $targetUuid): array {
		if ($targetUuid === '' || $sourceUuid === $targetUuid) {
			throw self::badRequest(message: 'a promotion needs a target organisation other than its source');
		}

		$source = $this->organisation(uuid: $sourceUuid);
		$target = $this->organisation(uuid: $targetUuid);

		if (self::followsOtapOrder(sourceEnv: self::environmentOf(organisation: $source), targetEnv: self::environmentOf(organisation: $target)) === false) {
			throw self::badRequest(message: self::ORDER_MESSAGE);
		}

		if ($this->rootOf(uuid: $sourceUuid) !== $this->rootOf(uuid: $targetUuid)) {
			throw self::badRequest(message: 'a promotion stays within one organisation hierarchy; source and target have different roots');
		}

		$components = $this->packages->read(organisationUuid: $sourceUuid);
		if ($components === []) {
			throw self::badRequest(message: 'the source organisation owns no schemas, mappings, sources, webhooks or endpoints to promote');
		}

		$uuidMap    = self::freshUuids(components: $components);
		$uuidMap[$sourceUuid] = $targetUuid;
		$components = self::remapped(components: $components, uuidMap: $uuidMap);

		$set = $this->drafts->openSet(
			name: 'Promotion from '.self::nameOf(organisation: $source),
			description: sprintf('Configuration of %s promoted into %s', self::nameOf(organisation: $source), self::nameOf(organisation: $target))
		);

		$counts = array_fill_keys(PromotionComponent::KINDS, 0);
		foreach ($components as $component) {
			$this->drafts->draftValue(
				target: new ConfigurationDraftTarget(
					setUuid: (string) $set->getUuid(),
					address: new ConfigurationAddress(layer: ConfigurationLayer::SUBJECT, layerRef: $targetUuid, configKey: $component->configKey())
				),
				value: $component->definition
			);
			$counts[$component->kind]++;
		}

		unset($uuidMap[$sourceUuid]);
		$this->auditor->recordPromotion(source: $source, target: $target, setUuid: (string) $set->getUuid(), counts: $counts);

		return [
			'set' => $set->jsonSerialize(),
			'source' => $sourceUuid,
			'target' => $targetUuid,
			'counts' => $counts,
			'uuidMap' => $uuidMap,
		];
	}//end promote()

	/**
	 * Whether a promotion from one environment to another follows the OTAP order.
	 *
	 * Strictly upward: an unknown environment, the same environment twice, or
	 * a step down is refused.
	 *
	 * @param string $sourceEnv The source environment.
	 * @param string $targetEnv The target environment.
	 *
	 * @return bool True when the target comes after the source.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public static function followsOtapOrder(string $sourceEnv, string $targetEnv): bool {
		$order = TenantLifecycleServiceInterface::OTAP_ORDER;
		if (isset($order[$sourceEnv], $order[$targetEnv]) === false) {
			return false;
		}

		return $order[$sourceEnv] < $order[$targetEnv];
	}//end followsOtapOrder()

	/**
	 * A fresh uuid for every component that has one, keyed by its old uuid.
	 *
	 * @param PromotionComponent[] $components The components.
	 *
	 * @return array<string, string> Old uuid => new uuid.
	 */
	private static function freshUuids(array $components): array {
		$map = [];
		foreach ($components as $component) {
			if ($component->uuid !== null) {
				$map[$component->uuid] = Uuid::v4()->toRfc4122();
			}
		}

		return $map;
	}//end freshUuids()

	/**
	 * The components with every value that is an old uuid replaced by its new one.
	 *
	 * A value is replaced only when it IS an old uuid, never inside a longer
	 * string: a description that mentions a uuid is prose, a reference is a value.
	 *
	 * @param PromotionComponent[]  $components The components.
	 * @param array<string, string> $uuidMap    Old uuid => new uuid.
	 *
	 * @return PromotionComponent[] The remapped components.
	 *
	 * @spec openspec/changes/configuration-as-a-deployment/tasks.md
	 */
	public static function remapped(array $components, array $uuidMap): array {
		return array_map(
			static fn (PromotionComponent $component): PromotionComponent => $component->withDefinition(
				definition: self::remapValues(values: $component->definition, uuidMap: $uuidMap)
			),
			$components
		);
	}//end remapped()

	/**
	 * The values with every old uuid replaced, at any depth.
	 *
	 * @param array<mixed>          $values  The values.
	 * @param array<string, string> $uuidMap Old uuid => new uuid.
	 *
	 * @return array<mixed> The remapped values.
	 */
	private static function remapValues(array $values, array $uuidMap): array {
		foreach ($values as $key => $value) {
			if (is_array($value) === true) {
				$values[$key] = self::remapValues(values: $value, uuidMap: $uuidMap);
				continue;
			}

			if (is_string($value) === true && isset($uuidMap[$value]) === true) {
				$values[$key] = $uuidMap[$value];
			}
		}

		return $values;
	}//end remapValues()

	/**
	 * The organisation, or a 404 naming the uuid.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return Organisation The organisation.
	 *
	 * @throws DeploymentRefusedException When there is no such organisation.
	 */
	private function organisation(string $uuid): Organisation {
		try {
			return $this->organisations->findByUuid($uuid);
		} catch (DoesNotExistException) {
			throw new DeploymentRefusedException(
				reason: DeploymentRefusedException::REASON_UNKNOWN,
				message: sprintf('no organisation "%s"', $uuid),
				refusals: [],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}
	}//end organisation()

	/**
	 * The root of the organisation's hierarchy: its topmost parent, or itself.
	 *
	 * @param string $uuid The organisation uuid.
	 *
	 * @return string The root uuid.
	 */
	private function rootOf(string $uuid): string {
		$chain = $this->organisations->findParentChain($uuid);
		if ($chain === []) {
			return $uuid;
		}

		return (string) end($chain);
	}//end rootOf()

	/**
	 * The organisation's OTAP environment; an unset one is production, as the entity defaults.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return string The environment.
	 */
	private static function environmentOf(Organisation $organisation): string {
		return (string) ($organisation->getEnvironment() ?? TenantLifecycleServiceInterface::ENV_PRODUCTION);
	}//end environmentOf()

	/**
	 * The organisation's name, or its uuid when it has none.
	 *
	 * @param Organisation $organisation The organisation.
	 *
	 * @return string The name.
	 */
	private static function nameOf(Organisation $organisation): string {
		$name = (string) ($organisation->getName() ?? '');
		if ($name === '') {
			return (string) $organisation->getUuid();
		}

		return $name;
	}//end nameOf()

	/**
	 * A 400 refusal of the promotion.
	 *
	 * @param string $message Why.
	 *
	 * @return DeploymentRefusedException The refusal.
	 */
	private static function badRequest(string $message): DeploymentRefusedException {
		return new DeploymentRefusedException(
			reason: DeploymentRefusedException::REASON_INVALID,
			message: $message,
			refusals: [],
			statusCode: Http::STATUS_BAD_REQUEST
		);
	}//end badRequest()
}//end class
