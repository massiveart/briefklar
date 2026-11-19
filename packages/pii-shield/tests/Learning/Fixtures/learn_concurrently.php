<?php

declare(strict_types=1);

/*
 * Child process for LockingCustomEntityStoreTest: saves N types into <dir>/learned_types.json.
 * Usage: php learn_concurrently.php <dir> <process number> <types> [--no-lock]
 */

use Clarifile\PiiShield\Learning\CustomEntityType;
use Clarifile\PiiShield\Learning\JsonFileCustomEntityStore;
use Clarifile\PiiShield\Learning\LockingCustomEntityStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$args = $_SERVER['argv'] ?? [];
$args = is_array($args) ? array_values($args) : [];
[$dir, $process, $count] = [$args[1] ?? null, $args[2] ?? null, $args[3] ?? null];
if (!is_string($dir) || !is_string($process) || !is_string($count)) {
    fwrite(\STDERR, "Usage: php learn_concurrently.php <dir> <process number> <types> [--no-lock]\n");
    exit(2);
}
$store = new JsonFileCustomEntityStore($dir.'/learned_types.json');
if (!in_array('--no-lock', $args, true)) {
    $store = new LockingCustomEntityStore($store, new LockFactory(new FlockStore($dir)));
}

for ($i = 0; $i < (int) $count; ++$i) {
    $store->save(new CustomEntityType(sprintf('TYPE_%d_%d', $process, $i), 'Versichertennummer einer Versicherung', ['VN-2026-000417'], [], 'VN-\d{4}-\d{6}'));
}
