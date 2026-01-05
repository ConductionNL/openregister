<?php
require_once '/var/www/html/lib/base.php';

header('Content-Type: application/json');

try {
    $registerMapper = \OC::$server->get(\OCA\OpenRegister\Db\RegisterMapper::class);
    $registers = $registerMapper->findAll();
    
    echo json_encode([
        'success' => true,
        'count' => count($registers),
        'results' => array_map(fn($r) => $r->jsonSerialize(), $registers)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
