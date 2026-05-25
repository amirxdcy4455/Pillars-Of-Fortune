<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Database;

interface DatabaseProvider {
    public function loadPlayer(string $playerName): array;
    public function savePlayer(string $playerName, array $data): void;
    public function getTopByColumn(string $column, int $limit = 10): array;
    public function close(): void;
}
