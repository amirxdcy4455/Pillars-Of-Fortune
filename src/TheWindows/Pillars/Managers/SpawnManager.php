<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Managers;

use pocketmine\world\Position;
use TheWindows\Pillars\Main;

class SpawnManager {

    private Main $plugin;
    private array $spawnPoints = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->loadWorldsFromConfig();
        $this->spawnPoints = $this->plugin->getConfigManager()->loadSpawnPoints();
    }

    private function loadWorldsFromConfig(): void {
        $data = $this->plugin->getConfigManager()->getSpawnConfig()->get('spawn-points', []);
        $worlds = array_unique(array_column($data, 'world'));
        foreach ($worlds as $worldName) {
            if (!$this->plugin->getServer()->getWorldManager()->isWorldLoaded($worldName)) {
                $this->plugin->getServer()->getWorldManager()->loadWorld($worldName);
            }
        }
    }

    public function reloadSpawnPoints(): void {
        $this->spawnPoints = $this->plugin->getConfigManager()->loadSpawnPoints();
    }

    public function getSpawnPoints(): array {
        return $this->spawnPoints;
    }

    public function getSpawnPointsForWorld(string $worldName): array {
        return $this->spawnPoints[$worldName] ?? [];
    }

    public function addSpawnPoint(string $worldName, Position $position): bool {
        if (!isset($this->spawnPoints[$worldName])) {
            $this->spawnPoints[$worldName] = [];
        }
        $maxPlayers = $this->plugin->getGameManager()->getMapMaxPlayers($worldName);
        if (count($this->spawnPoints[$worldName]) >= $maxPlayers) {
            return false;
        }
        $this->spawnPoints[$worldName][] = $position;
        $this->plugin->getConfigManager()->saveSpawnPoints($this->spawnPoints);
        return true;
    }

    public function removeSpawnPoint(string $worldName, Position $position): bool {
        if (!isset($this->spawnPoints[$worldName])) {
            return false;
        }
        $tolerance = 0.1;
        foreach ($this->spawnPoints[$worldName] as $key => $point) {
            if (
                abs($point->getX() - $position->getX()) < $tolerance &&
                abs($point->getY() - $position->getY()) < $tolerance &&
                abs($point->getZ() - $position->getZ()) < $tolerance
            ) {
                unset($this->spawnPoints[$worldName][$key]);
                $this->spawnPoints[$worldName] = array_values($this->spawnPoints[$worldName]);
                $this->plugin->getConfigManager()->saveSpawnPoints($this->spawnPoints);
                return true;
            }
        }
        return false;
    }

    public function clearSpawnPointsForWorld(string $worldName): void {
        if (isset($this->spawnPoints[$worldName])) {
            unset($this->spawnPoints[$worldName]);
            $this->plugin->getConfigManager()->saveSpawnPoints($this->spawnPoints);
        }
    }
}
