<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Managers;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\item\VanillaItems;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use TheWindows\Pillars\Main;
use TheWindows\Pillars\Session\PlayerSession;
use TheWindows\Pillars\Tasks\CountdownTask;
use TheWindows\Pillars\Tasks\ItemDistributionTask;
use TheWindows\Pillars\Forms\SpectatorForm;

class GameManager {

    private Main $plugin;

    private array $games = [];
    private array $activeGames = [];
    private array $spectators = [];
    private array $playerSpawnIndex = [];
    private array $countdownTasks = [];
    private array $persistentActionBars = [];
    private array $invulnerableWinners = [];
    private array $instanceTemplates = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;

        $this->plugin->getScheduler()->scheduleRepeatingTask(
            new class($this) extends Task {
                private GameManager $gm;
                public function __construct(GameManager $gm) { $this->gm = $gm; }
                public function onRun(): void {
                    $this->gm->updatePersistentActionBars();
                    $this->gm->cleanupExpiredInvulnerability();
                }
            },
            20
        );
    }

    public function findOrCreateGame(Player $player): void {
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session === null) {
            return;
        }
        if (!$session->isInLobby()) {
            $player->sendMessage('§cYou are already in a game. Use /pillars leave first.');
            return;
        }

        $availableGame = $this->findWaitingGame();

        if ($availableGame !== null) {
            $this->addPlayerToGame($player, $availableGame);
            return;
        }

        $this->spawnNewGameInstance($player);
    }

    private function findWaitingGame(): ?string {
        foreach ($this->games as $gameId => $data) {
            if ($data['status'] !== 'waiting') {
                continue;
            }
            $wm = $this->plugin->getServer()->getWorldManager();
            if (!$wm->isWorldLoaded($gameId)) {
                continue;
            }
            $world = $wm->getWorldByName($gameId);
            if ($world === null) {
                continue;
            }
            $currentCount = count(array_filter(
                $world->getPlayers(),
                fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
            ));
            if ($currentCount < $data['max_players']) {
                return $gameId;
            }
        }
        return null;
    }

    private function spawnNewGameInstance(Player $player): void {
        $templateName = $this->plugin->getMapManager()->getRandomTemplateName();
        if ($templateName === null) {
            $player->sendMessage('§cNo map templates available. Contact an administrator.');
            return;
        }

        $instanceName = $this->plugin->getMapManager()->generateInstanceName($templateName);

        $player->sendMessage('§eCreating a new game instance, please wait...');

        if (!$this->plugin->getMapManager()->createInstance($instanceName, $templateName)) {
            $player->sendMessage('§cFailed to create game instance. Please try again.');
            return;
        }

        $this->plugin->getMapManager()->autoSetupSpawnPoints($instanceName, $templateName);
        $this->plugin->getSpawnManager()->reloadSpawnPoints();

        $maxPlayers = (int) $this->plugin->getConfigManager()->getMapSetting($templateName, 'max-players', 12);
        $minPlayers = (int) $this->plugin->getConfigManager()->getMapSetting($templateName, 'min-players', 2);
        $countdownTime = (int) $this->plugin->getConfigManager()->getMapSetting($templateName, 'countdown-time', 30);
        $itemInterval = (int) $this->plugin->getConfigManager()->getMapSetting($templateName, 'item-interval', 300);

        $this->games[$instanceName] = [
            'world' => $instanceName,
            'template' => $templateName,
            'status' => 'waiting',
            'max_players' => $maxPlayers,
            'min_players' => $minPlayers,
            'game_time' => 1200,
            'countdown_time' => $countdownTime,
            'item_interval' => $itemInterval,
        ];

        $this->instanceTemplates[$instanceName] = $templateName;
        $this->plugin->getConfigManager()->addArenaWorld($instanceName);
        $this->plugin->getConfigManager()->setMapSetting($instanceName, 'max-players', $maxPlayers);
        $this->plugin->getConfigManager()->setMapSetting($instanceName, 'min-players', $minPlayers);
        $this->plugin->getConfigManager()->setMapSetting($instanceName, 'countdown-time', $countdownTime);
        $this->plugin->getConfigManager()->setMapSetting($instanceName, 'item-interval', $itemInterval);

        $this->addPlayerToGame($player, $instanceName);
    }

    public function getInstanceTemplateName(string $instanceName): string {
        return $this->instanceTemplates[$instanceName] ?? $instanceName;
    }

    public function createGame(string $worldName, int $maxPlayers, int $minPlayers, int $gameTime, int $countdownTime, int $itemInterval): bool {
        if (isset($this->games[$worldName])) {
            return false;
        }
        $wm = $this->plugin->getServer()->getWorldManager();
        if (!$wm->isWorldLoaded($worldName)) {
            $wm->loadWorld($worldName);
        }
        if ($wm->getWorldByName($worldName) === null) {
            return false;
        }
        $maxPlayers = max(2, min(24, $maxPlayers));
        $minPlayers = max(2, min($maxPlayers, $minPlayers));
        $countdownTime = max(5, min(60, $countdownTime));

        $templateName = $this->plugin->getMapManager()->isTemplate($worldName) ? $worldName : $worldName;

        $this->games[$worldName] = [
            'world' => $worldName,
            'template' => $templateName,
            'status' => 'waiting',
            'max_players' => $maxPlayers,
            'min_players' => $minPlayers,
            'game_time' => 1200,
            'countdown_time' => $countdownTime,
            'item_interval' => $itemInterval * 20,
        ];

        $this->instanceTemplates[$worldName] = $templateName;

        $cm = $this->plugin->getConfigManager();
        $cm->addArenaWorld($worldName);
        $cm->setMapSetting($worldName, 'max-players', $maxPlayers);
        $cm->setMapSetting($worldName, 'min-players', $minPlayers);
        $cm->setMapSetting($worldName, 'countdown-time', $countdownTime);
        $cm->setMapSetting($worldName, 'item-interval', $itemInterval);
        return true;
    }

    public function gameExists(string $worldName): bool {
        return isset($this->games[$worldName]);
    }

    public function removeGame(string $gameId): bool {
        if (!isset($this->games[$gameId])) {
            return false;
        }
        if (isset($this->activeGames[$gameId])) {
            $this->endGame($gameId);
        }
        unset(
            $this->games[$gameId],
            $this->persistentActionBars[$gameId],
            $this->instanceTemplates[$gameId]
        );
        $this->plugin->getConfigManager()->removeArenaWorld($gameId);
        $this->plugin->getSpawnManager()->clearSpawnPointsForWorld($gameId);
        return true;
    }

    public function getAvailableGames(): array {
        $result = [];
        foreach ($this->games as $gameId => $data) {
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
            $count = $world !== null
                ? count(array_filter($world->getPlayers(), fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())))
                : 0;
            $color = match($data['status']) {
                'playing' => '§c',
                'ending' => '§8',
                default => '§a',
            };
            $result[] = [
                'id' => $gameId,
                'world' => $gameId,
                'template' => $data['template'] ?? $gameId,
                'players' => $count,
                'max_players' => $data['max_players'],
                'status' => $color . ucfirst($data['status']),
            ];
        }
        return $result;
    }

    public function addPlayerToGame(Player $player, string $gameId): void {
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session === null) {
            return;
        }
        if (!$session->isInLobby()) {
            $player->sendMessage('§cYou are already in a game. Use /pillars leave first.');
            return;
        }
        if (!isset($this->games[$gameId])) {
            $player->sendMessage('§cGame not found.');
            return;
        }
        if ($this->games[$gameId]['status'] === 'playing') {
            $player->sendMessage('§cThis game is already in progress.');
            return;
        }
        if ($this->games[$gameId]['status'] === 'ending') {
            $player->sendMessage('§cThis game is resetting. Try again shortly.');
            return;
        }

        $wm = $this->plugin->getServer()->getWorldManager();
        if (!$wm->isWorldLoaded($gameId)) {
            if (!$wm->loadWorld($gameId)) {
                $player->sendMessage('§cGame world could not be loaded.');
                return;
            }
        }
        $world = $wm->getWorldByName($gameId);
        if ($world === null) {
            $player->sendMessage('§cGame world not found.');
            return;
        }

        $maxPlayers = $this->games[$gameId]['max_players'];
        $currentAdventurePlayers = array_filter(
            $world->getPlayers(),
            fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
        );
        if (count($currentAdventurePlayers) >= $maxPlayers) {
            $player->sendMessage('§cThis game is full.');
            return;
        }

        $spawnPoints = $this->plugin->getSpawnManager()->getSpawnPointsForWorld($gameId);
        $usedIndices = $this->playerSpawnIndex[$gameId] ?? [];
        $assignedIndex = null;
        for ($i = 0; $i < count($spawnPoints); $i++) {
            if (!in_array($i, $usedIndices, true)) {
                $assignedIndex = $i;
                break;
            }
        }
        if ($assignedIndex === null) {
            $player->sendMessage('§cNo spawn points available.');
            return;
        }

        $playerKey = strtolower($player->getName());
        if (!isset($this->playerSpawnIndex[$gameId])) {
            $this->playerSpawnIndex[$gameId] = [];
        }
        $this->playerSpawnIndex[$gameId][$playerKey] = $assignedIndex;

        $session->setState('queue');
        $session->setGameId($gameId);
        $session->setSpawnIndex($assignedIndex);

        $spawnPoint = $spawnPoints[$assignedIndex];
        $freshSpawn = new Position($spawnPoint->getX(), $spawnPoint->getY(), $spawnPoint->getZ(), $world);

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setGamemode(GameMode::ADVENTURE());
        $player->setNoClientPredictions(true);
        $player->getHungerManager()->setFood(20);
        $player->getHungerManager()->setSaturation(20);
        $player->getHungerManager()->setEnabled(false);
        $player->teleport($freshSpawn);

        $leaveQueueItem = VanillaBlocks::BED()->setColor(DyeColor::RED())->asItem()->setCustomName('§cLeave Queue');
        $player->getInventory()->setItem(8, $leaveQueueItem);

        $minPlayers = $this->games[$gameId]['min_players'];
        $currentCount = count(array_filter(
            $world->getPlayers(),
            fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
        ));

        $this->broadcastToGame($gameId, "§a{$player->getName()} joined the queue! (§6{$currentCount}/{$maxPlayers}§a)");

        if ($currentCount < $minPlayers) {
            $this->setPersistentActionBar($gameId, "§cWaiting for players... (§6{$currentCount}/{$maxPlayers}§c)");
        } else {
            $this->clearPersistentActionBar($gameId);
            if (!isset($this->countdownTasks[$gameId])) {
                $this->startCountdown($gameId);
            }
        }
    }

    private function startCountdown(string $gameId): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world === null) {
            return;
        }
        $players = array_filter(
            $world->getPlayers(),
            fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
        );
        $countdownTime = (int) $this->getGameData($gameId, 'countdown_time', 30);
        $this->clearPersistentActionBar($gameId);
        $this->broadcastToGame($gameId, '§eEnough players! Countdown starting...');
        $task = new CountdownTask($this->plugin, $players, $gameId, $countdownTime);
        $handler = $this->plugin->getScheduler()->scheduleRepeatingTask($task, 20);
        $this->countdownTasks[$gameId] = $handler;
    }

    public function startGame(array $players, string $gameId): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world === null) {
            return;
        }
        unset($this->countdownTasks[$gameId]);
        $this->clearPersistentActionBar($gameId);
        $this->games[$gameId]['status'] = 'playing';

        $spawnPoints = $this->plugin->getSpawnManager()->getSpawnPointsForWorld($gameId);

        $alivePlayers = [];
        foreach ($players as $player) {
            if (!$player->isOnline() || $player->isClosed()) {
                continue;
            }
            $session = $this->plugin->getSessionManager()->get($player);
            if ($session === null) {
                continue;
            }
            $session->setState('playing');
            $session->incrementGamesPlayed();

            $spawnIndex = $session->getSpawnIndex() ?? 0;
            if (isset($spawnPoints[$spawnIndex])) {
                $sp = $spawnPoints[$spawnIndex];
                $player->teleport(new Position($sp->getX(), $sp->getY(), $sp->getZ(), $world));
            }

            $player->setGamemode(GameMode::SURVIVAL());
            $player->setNoClientPredictions(false);
            $player->getInventory()->clearAll();
            $player->getHungerManager()->setFood(20);
            $player->getHungerManager()->setSaturation(20);
            $player->getHungerManager()->setEnabled(false);
            $player->sendActionBarMessage('');
            $player->sendTitle(TextFormat::GREEN . 'GAME STARTED!', TextFormat::YELLOW . 'Last player standing wins!', 10, 40, 10);
            $alivePlayers[] = $player;
        }

        $maxPlayers = $this->games[$gameId]['max_players'];
        $itemTask = new ItemDistributionTask($this->plugin, $alivePlayers, $gameId, $maxPlayers);
        $handler = $this->plugin->getScheduler()->scheduleRepeatingTask($itemTask, 1);

        $this->activeGames[$gameId] = [
            'players' => $alivePlayers,
            'item_task' => $handler,
        ];

        $this->broadcastToGame($gameId, '§a§lGAME STARTED! §r§aThe battle begins!');
        $this->startGameTimer($gameId);
    }

    private function startGameTimer(string $gameId): void {
        $gameTime = (int) $this->getMapSetting($gameId, 'game_time', 600);
        $this->games[$gameId]['time_left'] = $gameTime;
        $this->plugin->getScheduler()->scheduleRepeatingTask(
            new class($this, $gameId) extends Task {
                private GameManager $gm;
                private string $gameId;
                private array $announced = [];

                public function __construct(GameManager $gm, string $gameId) {
                    $this->gm = $gm;
                    $this->gameId = $gameId;
                }

                public function onRun(): void {
                    if (!isset($this->gm->getActiveGames()[$this->gameId])) {
                        $this->getHandler()?->cancel();
                        return;
                    }
                    $remaining = (int) ($this->gm->getAllGames()[$this->gameId]['time_left'] ?? 0);
                    if ($remaining <= 0) {
                        $this->gm->endGameDueToTime($this->gameId);
                        $this->getHandler()?->cancel();
                        return;
                    }
                    $announceTimes = [60, 30, 15, 10, 5, 3, 2, 1];
                    if (in_array($remaining, $announceTimes, true) && !isset($this->announced[$remaining])) {
                        $unit = $remaining >= 60 ? 'MINUTE' : 'SECOND';
                        $val  = $remaining >= 60 ? (int) ($remaining / 60) : $remaining;
                        $this->gm->broadcastToGame($this->gameId, "§c§l{$val} {$unit}" . ($val > 1 ? 'S' : '') . ' LEFT!');
                        $this->announced[$remaining] = true;
                    }
                    $this->gm->decrementTimeLeft($this->gameId);
                }
            },
            20
        );
    }

    public function decrementTimeLeft(string $gameId): void {
        if (isset($this->games[$gameId]['time_left'])) {
            $this->games[$gameId]['time_left'] = max(0, $this->games[$gameId]['time_left'] - 1);
        }
    }

    public function getTimeLeft(string $gameId): int {
        return (int) ($this->games[$gameId]['time_left'] ?? 0);
    }

    public function getPlayerCount(string $gameId): int {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        return $world !== null ? count($world->getPlayers()) : 0;
    }

    public function getActiveGames(): array {
        return $this->activeGames;
    }

    public function getAllGames(): array {
        return $this->games;
    }

    public function getGameData(string $gameId, string $key, mixed $default = null): mixed {
        return $this->games[$gameId][$key] ?? $default;
    }

    public function handlePlayerDeath(Player $player, string $gameId): void {
        if (!isset($this->activeGames[$gameId])) {
            return;
        }
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session !== null) {
            $session->addDeath();
        }

        $killer = null;
        $cause = $player->getLastDamageCause();
        if ($cause instanceof EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager instanceof Player) {
                $killer = $damager;
                $killerSession = $this->plugin->getSessionManager()->get($killer);
                if ($killerSession !== null) {
                    $killerSession->addKill();
                }
            }
        }

        $key = array_search($player, $this->activeGames[$gameId]['players'], true);
        if ($key !== false) {
            unset($this->activeGames[$gameId]['players'][$key]);
            $this->activeGames[$gameId]['players'] = array_values($this->activeGames[$gameId]['players']);
        }

        $this->setPlayerSpectator($player, $gameId);

        $aliveCount = count($this->activeGames[$gameId]['players']);
        if ($killer !== null) {
            $killerSession = $this->plugin->getSessionManager()->get($killer);
            $killerKills = $killerSession?->getKills() ?? 0;
            $this->broadcastToGame($gameId, "§c{$player->getName()} §7was killed by §c{$killer->getName()} §7(§e{$killerKills} kills§7) §8[§a{$aliveCount}§8]");
        } else {
            $this->broadcastToGame($gameId, "§c{$player->getName()} §7died §8[§a{$aliveCount}§8]");
        }

        $this->checkGameEnd($gameId);
    }

    private function setPlayerSpectator(Player $player, string $gameId): void {
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session !== null) {
            $session->setState('spectating');
            $session->setGameId($gameId);
        }

        $player->setGamemode(GameMode::SPECTATOR());
        $player->setInvisible(true);
        $player->setSilent(true);
        $player->setAllowFlight(true);
        $player->setFlying(true);
        $player->setNoClientPredictions(false);
        $player->getEffects()->clear();
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $leaveItem = VanillaBlocks::BED()->setColor(DyeColor::RED())->asItem()->setCustomName('§cLeave Game');
        $spectateItem = VanillaItems::COMPASS()->setCustomName('§bSpectate Players');
        $mapsItem = VanillaItems::CLOCK()->setCustomName('§eSpectate Map');
        $player->getInventory()->setItem(0, $spectateItem);
        $player->getInventory()->setItem(4, $mapsItem);
        $player->getInventory()->setItem(8, $leaveItem);

        $player->setHealth($player->getMaxHealth());
        $player->getHungerManager()->setEnabled(false);
        $player->getHungerManager()->setFood(20);

        if (!isset($this->spectators[$gameId])) {
            $this->spectators[$gameId] = [];
        }
        $this->spectators[$gameId][] = $player;

        $player->sendMessage('§7You are now spectating. Use the compass to teleport or bed to leave.');
    }

    public function showSpectatorMenu(Player $spectator): void {
        $gameId = $this->plugin->getSessionManager()->getGameId($spectator);
        if ($gameId === null) {
            return;
        }
        $alivePlayers = array_values(array_filter(
            $this->getAlivePlayers($gameId),
            fn(Player $p) => $p->getId() !== $spectator->getId() && $p->isOnline()
        ));
        if (empty($alivePlayers)) {
            $spectator->sendMessage('§cNo alive players to spectate.');
            return;
        }
        $spectator->sendForm(SpectatorForm::createForm($this->plugin, $spectator, $alivePlayers));
    }

    public function teleportSpectatorToPlayer(Player $spectator, Player $target): void {
        if ($spectator->isOnline() && $target->isOnline()) {
            $spectator->teleport($target->getPosition());
            $spectator->sendMessage("§aTeleported to §6{$target->getName()}");
        }
    }

    public function getAlivePlayers(string $gameId): array {
        if (!isset($this->activeGames[$gameId])) {
            return [];
        }
        return array_values(array_filter(
            $this->activeGames[$gameId]['players'],
            fn(Player $p) => $p->isOnline() && !$p->isClosed()
        ));
    }

    public function getGameStatus(string $gameId): string {
        return $this->games[$gameId]['status'] ?? 'unknown';
    }

    public function isGameActive(string $gameId): bool {
        return isset($this->activeGames[$gameId]);
    }

    private function checkGameEnd(string $gameId): void {
        if (!isset($this->activeGames[$gameId])) {
            return;
        }
        $players = $this->activeGames[$gameId]['players'];
        if (count($players) === 1) {
            $winner = reset($players);
            if ($winner->isOnline()) {
                $winnerSession = $this->plugin->getSessionManager()->get($winner);
                $kills = $winnerSession?->getKills() ?? 0;
                if ($winnerSession !== null) {
                    $winnerSession->addWin();
                    $winnerSession->addCoins(20);
                }
                $this->invulnerableWinners[$winner->getId()] = time() + 5;
                $winner->sendTitle('§6§lVICTORY!', '§aYou won the game!', 10, 60, 10);
                $this->broadcastToGame($gameId, "§6§l{$winner->getName()} §6won with §e{$kills} kills§6!");
            }
            $this->plugin->getScheduler()->scheduleDelayedTask(
                new class($this->plugin, $gameId) extends Task {
                    private Main $plugin;
                    private string $gameId;
                    public function __construct(Main $plugin, string $gameId) {
                        $this->plugin = $plugin;
                        $this->gameId = $gameId;
                    }
                    public function onRun(): void {
                        $this->plugin->getGameManager()->endGame($this->gameId);
                    }
                },
                5 * 20
            );
        } elseif (count($players) === 0) {
            $this->broadcastToGame($gameId, '§cGame ended with no winner.');
            $this->endGame($gameId);
        }
    }

    public function endGameDueToTime(string $gameId): void {
        if (!isset($this->activeGames[$gameId])) {
            return;
        }
        $this->broadcastToGame($gameId, "§6§lTIME'S UP! §r§7No one won this round.");
        $this->endGame($gameId);
    }

    public function endGame(string $gameId): void {
        if (!isset($this->activeGames[$gameId])) {
            return;
        }
        $gameData = $this->activeGames[$gameId];

        if (isset($gameData['item_task'])) {
            $gameData['item_task']->cancel();
        }

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world !== null) {
            foreach ($world->getPlayers() as $player) {
                if ($player->isOnline()) {
                    $session = $this->plugin->getSessionManager()->get($player);
                    if ($session !== null) {
                        $session->addCoins(5);
                    }
                }
            }
        }

        $this->showGameStatistics($gameId);
        $this->games[$gameId]['status'] = 'ending';
        unset($this->activeGames[$gameId], $this->spectators[$gameId], $this->playerSpawnIndex[$gameId]);
        $this->clearPersistentActionBar($gameId);

        $this->plugin->getScheduler()->scheduleDelayedTask(
            new class($this->plugin, $gameId) extends Task {
                private Main $plugin;
                private string $gameId;
                public function __construct(Main $plugin, string $gameId) {
                    $this->plugin = $plugin;
                    $this->gameId = $gameId;
                }
                public function onRun(): void {
                    $this->plugin->getGameManager()->teleportAllToLobby($this->gameId);
                    $this->plugin->getScheduler()->scheduleDelayedTask(
                        new class($this->plugin, $this->gameId) extends Task {
                            private Main $plugin;
                            private string $gameId;
                            public function __construct(Main $plugin, string $gameId) {
                                $this->plugin = $plugin;
                                $this->gameId = $gameId;
                            }
                            public function onRun(): void {
                                $this->plugin->getMapManager()->resetWorld($this->gameId);
                                $this->plugin->getGameManager()->resetGameStatus($this->gameId);
                            }
                        },
                        20
                    );
                }
            },
            5 * 20
        );
    }

    public function resetGameStatus(string $gameId): void {
        if (isset($this->games[$gameId])) {
            $this->games[$gameId]['status'] = 'waiting';
            unset($this->playerSpawnIndex[$gameId]);
        }
    }

    public function teleportAllToLobby(string $gameId): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world === null) {
            return;
        }
        foreach ($world->getPlayers() as $player) {
            if ($player->isOnline()) {
                $this->teleportToLobby($player);
                $this->resetPlayerToLobby($player);
            }
        }
        $this->plugin->getSessionManager()->saveGamePlayers($world->getPlayers());
    }

    public function removePlayerFromGame(Player $player): void {
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session === null || $session->isInLobby()) {
            return;
        }
        $gameId = $session->getGameId();
        unset($this->invulnerableWinners[$player->getId()]);

        if ($gameId !== null) {
            $playerKey = strtolower($player->getName());
            unset($this->playerSpawnIndex[$gameId][$playerKey]);

            if (isset($this->activeGames[$gameId])) {
                $key = array_search($player, $this->activeGames[$gameId]['players'], true);
                if ($key !== false) {
                    unset($this->activeGames[$gameId]['players'][$key]);
                    $this->activeGames[$gameId]['players'] = array_values($this->activeGames[$gameId]['players']);
                    if (count($this->activeGames[$gameId]['players']) <= 1) {
                        $this->checkGameEnd($gameId);
                    }
                }
            }

            if (isset($this->spectators[$gameId])) {
                $key = array_search($player, $this->spectators[$gameId], true);
                if ($key !== false) {
                    unset($this->spectators[$gameId][$key]);
                    $this->spectators[$gameId] = array_values($this->spectators[$gameId]);
                }
            }

            if ($session->isInQueue()) {
                $wm = $this->plugin->getServer()->getWorldManager();
                $world = $wm->getWorldByName($gameId);
                if ($world !== null) {
                    $currentCount = count(array_filter(
                        $world->getPlayers(),
                        fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
                    )) - 1;
                    $maxPlayers = $this->games[$gameId]['max_players'] ?? 12;
                    $minPlayers = $this->games[$gameId]['min_players'] ?? 2;
                    if (($this->games[$gameId]['status'] ?? '') === 'waiting') {
                        $this->broadcastToGame($gameId, "§c{$player->getName()} left the queue! (§6{$currentCount}/{$maxPlayers}§c)");
                    }
                    if ($currentCount < $minPlayers && isset($this->countdownTasks[$gameId])) {
                        $this->safeCancelCountdown($gameId);
                    }
                }
            }
        }

        $this->resetPlayerToLobby($player);
        $this->teleportToLobby($player);
    }

    private function resetPlayerToLobby(Player $player): void {
        $session = $this->plugin->getSessionManager()->get($player);
        if ($session !== null) {
            $session->setState('lobby');
            $session->setGameId(null);
            $session->setSpawnIndex(null);
        }
        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();
        $player->setHealth($player->getMaxHealth());
        $player->getEffects()->clear();
        $player->setInvisible(false);
        $player->setSilent(false);
        $player->setAllowFlight(false);
        $player->setFlying(false);
        $player->setNoClientPredictions(false);
        $player->getHungerManager()->setEnabled(true);
        $player->getHungerManager()->setFood(20);
        $player->getHungerManager()->setSaturation(20);
    }

    public function teleportToLobby(Player $player): void {
        unset($this->invulnerableWinners[$player->getId()]);
        $lm = $this->plugin->getLobbyManager();
        $lm->teleportToLobby($player);
        $this->plugin->getScheduler()->scheduleDelayedTask(
            new class($player, $lm) extends Task {
                private Player $player;
                private \TheWindows\Pillars\Lobby\LobbyManager $lm;
                public function __construct(Player $player, \TheWindows\Pillars\Lobby\LobbyManager $lm) {
                    $this->player = $player;
                    $this->lm = $lm;
                }
                public function onRun(): void {
                    if ($this->player->isOnline()) {
                        $this->lm->prepareLobbyPlayer($this->player);
                        $this->lm->giveLobbyItems($this->player);
                    }
                }
            },
            5
        );
    }

    public function isPlayerInvulnerable(Player $player): bool {
        return isset($this->invulnerableWinners[$player->getId()]) && time() < $this->invulnerableWinners[$player->getId()];
    }

    public function getMapSetting(string $gameId, string $key, mixed $default = null): mixed {
        return $this->games[$gameId][$key] ?? $this->plugin->getConfigManager()->getMapSetting($gameId, $key, $default);
    }

    public function getMapMaxPlayers(string $gameId): int {
        return (int) $this->getGameData($gameId, 'max_players', 12);
    }

    public function getCountdownTime(string $gameId): int {
        if (!isset($this->countdownTasks[$gameId])) {
            return 0;
        }
        $handler = $this->countdownTasks[$gameId];
        $task = $handler->getTask();
        if ($task instanceof CountdownTask) {
            return $task->getCountdown();
        }
        return 0;
    }

    public function broadcastToGame(string $gameId, string $message): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world !== null) {
            foreach ($world->getPlayers() as $player) {
                if ($player->isOnline()) {
                    $player->sendMessage($message);
                }
            }
        }
    }

    public function setPersistentActionBar(string $gameId, string $message): void {
        $this->persistentActionBars[$gameId] = $message;
        $this->sendActionBarToGame($gameId, $message);
    }

    public function clearPersistentActionBar(string $gameId): void {
        if (isset($this->persistentActionBars[$gameId])) {
            $this->sendActionBarToGame($gameId, '');
            unset($this->persistentActionBars[$gameId]);
        }
    }

    public function updatePersistentActionBars(): void {
        foreach ($this->persistentActionBars as $gameId => $message) {
            $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
            if ($world !== null) {
                foreach ($world->getPlayers() as $player) {
                    if ($player->isOnline()) {
                        $player->sendActionBarMessage($message);
                    }
                }
            }
        }
    }

    public function sendActionBarToGame(string $gameId, string $message): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world !== null) {
            foreach ($world->getPlayers() as $player) {
                if ($player->isOnline()) {
                    $player->sendActionBarMessage($message);
                }
            }
        }
    }

    public function safeCancelCountdown(string $gameId): void {
        if (!isset($this->countdownTasks[$gameId])) {
            return;
        }
        $this->countdownTasks[$gameId]->cancel();
        unset($this->countdownTasks[$gameId]);

        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world !== null) {
            $currentCount = count(array_filter(
                $world->getPlayers(),
                fn(Player $p) => $p->getGamemode()->equals(GameMode::ADVENTURE())
            ));
            $maxPlayers = $this->games[$gameId]['max_players'] ?? 12;
            foreach ($world->getPlayers() as $player) {
                if ($player->isOnline()) {
                    $player->sendMessage('§cCountdown cancelled! Waiting for more players...');
                    $player->sendTitle('', '§cNeed more players!', 0, 40, 0);
                    $player->sendActionBarMessage('');
                }
            }
            $this->setPersistentActionBar($gameId, "§cWaiting for players... (§6{$currentCount}/{$maxPlayers}§c)");
        }
    }

    public function cancelCountdown(string $gameId): void {
        $this->safeCancelCountdown($gameId);
    }

    public function cleanupExpiredInvulnerability(): void {
        $now = time();
        foreach ($this->invulnerableWinners as $id => $expiry) {
            if ($now >= $expiry) {
                unset($this->invulnerableWinners[$id]);
            }
        }
    }

    private function showGameStatistics(string $gameId): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($gameId);
        if ($world === null) {
            return;
        }
        $this->broadcastToGame($gameId, '§6§l--- Game Ended ---');
        $this->broadcastToGame($gameId, '§aFinal Kill Counts:');
        foreach ($world->getPlayers() as $player) {
            if (!$player->isOnline()) {
                continue;
            }
            $session = $this->plugin->getSessionManager()->get($player);
            $kills = $session?->getKills() ?? 0;
            $this->broadcastToGame($gameId, "§e{$player->getName()}: §c{$kills} kills");
        }
        $this->broadcastToGame($gameId, '§6§l-----------------------');
        foreach ($world->getPlayers() as $player) {
            if ($player->isOnline()) {
                $player->sendMessage('§7Participation reward: §6+5 coins');
            }
        }
    }
}
