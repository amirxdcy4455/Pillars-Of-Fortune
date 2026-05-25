<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Session;

use pocketmine\player\Player;
use TheWindows\Pillars\Database\DatabaseProvider;

class SessionManager {

    private DatabaseProvider $db;
    private array $sessions = [];

    public function __construct(DatabaseProvider $db) {
        $this->db = $db;
    }

    public function create(Player $player): PlayerSession {
        $data = $this->db->loadPlayer($player->getName());
        $session = new PlayerSession($player->getName(), $data);
        $this->sessions[$player->getUniqueId()->toString()] = $session;
        return $session;
    }

    public function getSession(Player $player): ?PlayerSession {
        return $this->get($player);
    }

    public function get(Player $player): ?PlayerSession {
        return $this->sessions[$player->getUniqueId()->toString()] ?? null;
    }

    public function destroy(Player $player): void {
        $uuid = $player->getUniqueId()->toString();
        if (isset($this->sessions[$uuid])) {
            $session = $this->sessions[$uuid];
            $this->db->savePlayer($session->getPlayerName(), $session->toArray());
            unset($this->sessions[$uuid]);
        }
    }

    public function saveAll(array $players): void {
        foreach ($players as $player) {
            $this->destroy($player);
        }
    }

    public function saveGamePlayers(array $players): void {
        foreach ($players as $player) {
            $uuid = $player->getUniqueId()->toString();
            if (isset($this->sessions[$uuid])) {
                $session = $this->sessions[$uuid];
                $this->db->savePlayer($session->getPlayerName(), $session->toArray());
            }
        }
    }

    public function getDatabase(): DatabaseProvider {
        return $this->db;
    }

    public function getState(Player $player): ?string {
        return $this->get($player)?->getState();
    }

    public function setState(Player $player, string $state): void {
        $this->get($player)?->setState($state);
    }

    public function isInLobby(Player $player): bool {
        return $this->get($player)?->isInLobby() ?? true;
    }

    public function isPlaying(Player $player): bool {
        return $this->get($player)?->isPlaying() ?? false;
    }

    public function isSpectating(Player $player): bool {
        return $this->get($player)?->isSpectating() ?? false;
    }

    public function isInQueue(Player $player): bool {
        return $this->get($player)?->isInQueue() ?? false;
    }

    public function getGameId(Player $player): ?string {
        return $this->get($player)?->getGameId();
    }

    public function setGameId(Player $player, ?string $gameId): void {
        $this->get($player)?->setGameId($gameId);
    }

    public function getSpawnIndex(Player $player): ?int {
        return $this->get($player)?->getSpawnIndex();
    }

    public function setSpawnIndex(Player $player, ?int $index): void {
        $this->get($player)?->setSpawnIndex($index);
    }

    public function getWins(Player $player): int {
        return $this->get($player)?->getWins() ?? 0;
    }

    public function setWins(Player $player, int $wins): void {
        $this->get($player)?->setWins($wins);
    }

    public function addWin(Player $player): void {
        $this->get($player)?->addWin();
    }

    public function getCoins(Player $player): int {
        return $this->get($player)?->getCoins() ?? 0;
    }

    public function setCoins(Player $player, int $coins): void {
        $this->get($player)?->setCoins($coins);
    }

    public function addCoins(Player $player, int $amount): void {
        $this->get($player)?->addCoins($amount);
    }

    public function removeCoins(Player $player, int $amount): bool {
        return $this->get($player)?->removeCoins($amount) ?? false;
    }

    public function getKills(Player $player): int {
        return $this->get($player)?->getKills() ?? 0;
    }

    public function setKills(Player $player, int $kills): void {
        $this->get($player)?->setKills($kills);
    }

    public function addKill(Player $player): void {
        $this->get($player)?->addKill();
    }

    public function getDeaths(Player $player): int {
        return $this->get($player)?->getDeaths() ?? 0;
    }

    public function setDeaths(Player $player, int $deaths): void {
        $this->get($player)?->setDeaths($deaths);
    }

    public function addDeath(Player $player): void {
        $this->get($player)?->addDeath();
    }

    public function getGamesPlayed(Player $player): int {
        return $this->get($player)?->getGamesPlayed() ?? 0;
    }

    public function setGamesPlayed(Player $player, int $count): void {
        $this->get($player)?->setGamesPlayed($count);
    }

    public function incrementGamesPlayed(Player $player): void {
        $this->get($player)?->incrementGamesPlayed();
    }

    public function getKDR(Player $player): float {
        return $this->get($player)?->getKDR() ?? 0.0;
    }

    public function getWinRate(Player $player): float {
        return $this->get($player)?->getWinRate() ?? 0.0;
    }
}
