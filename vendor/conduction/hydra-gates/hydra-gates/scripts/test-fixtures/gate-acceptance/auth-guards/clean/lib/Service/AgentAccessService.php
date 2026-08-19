<?php
/**
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

namespace OCA\ScopeFixture\Service;

class AgentAccessService {

	public function findAgent(int $id): ?array {
		return ['id' => $id, 'ownerId' => 'alice'];
	}

	public function isShared(array $agent, string $userId): bool {
		return in_array($userId, $agent['sharedWith'] ?? [], true);
	}

	public function apply(array $agent, array $data): array {
		return array_merge($agent, $data);
	}

	public function diff(array $agent): array {
		return ['id' => $agent['id'], 'changes' => []];
	}
}
