<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Session;

class PlayerSession {

    private string $playerName;
    private string $state;
    private ?string $gameId;
    private ?int $spawnIndex;
    private int $joinTime;

    private int $wins;
    private int $coins;
    private int $kills;
    private int $deaths;
    private int $gamesPlayed;

    public function __construct(string $playerName, array $dbData) {
        $this->playerName    = $playerName;
        $this->state         = 'lobby';
        $this->gameId        = null;
        $this->spawnIndex    = null;
        $this->joinTime      = time();

        $this->wins        = (int) ($dbData['wins']         ?? 0);
        $this->coins       = (int) ($dbData['coins']        ?? 0);
        $this->kills       = (int) ($dbData['kills']        ?? 0);
        $this->deaths      = (int) ($dbData['deaths']       ?? 0);
        $this->gamesPlayed = (int) ($dbData['games_played'] ?? 0);
    }

    

    public function getPlayerName(): string {
        return $this->playerName;
    }

    

    public function getState(): string {
        return $this->state;
    }

    public function setState(string $state): void {
        $this->state = $state;
    }

    public function isInLobby(): bool {
        return $this->state === 'lobby';
    }

    public function isPlaying(): bool {
        return $this->state === 'playing';
    }

    public function isSpectating(): bool {
        return $this->state === 'spectating';
    }

    public function isInQueue(): bool {
        return $this->state === 'queue';
    }

    

    public function getGameId(): ?string {
        return $this->gameId;
    }

    public function setGameId(?string $gameId): void {
        $this->gameId = $gameId;
    }

    

    public function getSpawnIndex(): ?int {
        return $this->spawnIndex;
    }

    public function setSpawnIndex(?int $index): void {
        $this->spawnIndex = $index;
    }

    

    public function getJoinTime(): int {
        return $this->joinTime;
    }

    

    public function getWins(): int {
        return $this->wins;
    }

    public function setWins(int $wins): void {
        $this->wins = max(0, $wins);
    }

    public function addWin(): void {
        $this->wins++;
    }

    

    public function getCoins(): int {
        return $this->coins;
    }

    public function setCoins(int $coins): void {
        $this->coins = max(0, $coins);
    }

    public function addCoins(int $amount): void {
        $this->coins += max(0, $amount);
    }

    
    public function removeCoins(int $amount): bool {
        if ($this->coins < $amount) {
            return false;
        }
        $this->coins -= $amount;
        return true;
    }

    

    public function getKills(): int {
        return $this->kills;
    }

    public function setKills(int $kills): void {
        $this->kills = max(0, $kills);
    }

    public function addKill(): void {
        $this->kills++;
    }

    

    public function getDeaths(): int {
        return $this->deaths;
    }

    public function setDeaths(int $deaths): void {
        $this->deaths = max(0, $deaths);
    }

    public function addDeath(): void {
        $this->deaths++;
    }

    

    public function getGamesPlayed(): int {
        return $this->gamesPlayed;
    }

    public function setGamesPlayed(int $gamesPlayed): void {
        $this->gamesPlayed = max(0, $gamesPlayed);
    }

    public function incrementGamesPlayed(): void {
        $this->gamesPlayed++;
    }

    

    public function getKDR(): float {
        return $this->deaths === 0
            ? (float) $this->kills
            : round($this->kills / $this->deaths, 2);
    }

    public function getWinRate(): float {
        return $this->gamesPlayed === 0
            ? 0.0
            : round(($this->wins / $this->gamesPlayed) * 100, 1);
    }

    

    public function toArray(): array {
        return [
            'wins'         => $this->wins,
            'coins'        => $this->coins,
            'kills'        => $this->kills,
            'deaths'       => $this->deaths,
            'games_played' => $this->gamesPlayed,
        ];
    }
}
