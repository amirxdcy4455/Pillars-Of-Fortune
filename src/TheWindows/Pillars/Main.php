<?php
declare(strict_types=1);
namespace TheWindows\Pillars;

use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\entity\Human;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use TheWindows\Pillars\Commands\MainCommand;
use TheWindows\Pillars\Database\DatabaseProvider;
use TheWindows\Pillars\Database\JsonDatabaseProvider;
use TheWindows\Pillars\Database\MySQLDatabaseProvider;
use TheWindows\Pillars\Entity\PillarsNPC;
use TheWindows\Pillars\Listeners\LobbyListener;
use TheWindows\Pillars\Listeners\PlayerDamageListener;
use TheWindows\Pillars\Listeners\PlayerGameListener;
use TheWindows\Pillars\Listeners\PlayerQuitListener;
use TheWindows\Pillars\Lobby\LobbyManager;
use TheWindows\Pillars\Managers\ConfigManager;
use TheWindows\Pillars\Managers\GameManager;
use TheWindows\Pillars\Managers\MapManager;
use TheWindows\Pillars\Managers\NPCManager;
use TheWindows\Pillars\Managers\ScoreboardManager;
use TheWindows\Pillars\Managers\SpawnManager;
use TheWindows\Pillars\Session\SessionManager;
use TheWindows\Pillars\Tasks\ScoreboardTask;

class Main extends PluginBase {

    use SingletonTrait;

    private ConfigManager $configManager;
    private DatabaseProvider $databaseProvider;
    private SessionManager $sessionManager;
    private LobbyManager $lobbyManager;
    private MapManager $mapManager;
    private SpawnManager $spawnManager;
    private GameManager $gameManager;
    private NPCManager $npcManager;
    private ScoreboardManager $scoreboardManager;

    protected function onLoad(): void {
        self::setInstance($this);
    }

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->saveResource('config.yml', false);

        $this->configManager     = new ConfigManager($this);
        $this->databaseProvider  = $this->buildDatabaseProvider();
        $this->sessionManager    = new SessionManager($this->databaseProvider);
        $this->lobbyManager      = new LobbyManager($this);
        $this->lobbyManager->loadLobbyWorld();

        $this->mapManager = new MapManager($this, $this->getFile());
        $this->mapManager->setupTemplateWorlds();

        $this->spawnManager      = new SpawnManager($this);
        $this->gameManager       = new GameManager($this);
        $this->scoreboardManager = new ScoreboardManager($this);

        EntityFactory::getInstance()->register(
            PillarsNPC::class,
            function(World $world, CompoundTag $nbt): PillarsNPC {
                return new PillarsNPC(
                    EntityDataHelper::parseLocation($nbt, $world),
                    Human::parseSkinNBT($nbt),
                    $nbt
                );
            },
            ['PillarsNPC']
        );

        $this->npcManager = new NPCManager($this);

        $pm = $this->getServer()->getPluginManager();
        $pm->registerEvents(new LobbyListener($this), $this);
        $pm->registerEvents(new PlayerGameListener($this), $this);
        $pm->registerEvents(new PlayerDamageListener($this), $this);
        $pm->registerEvents(new PlayerQuitListener($this), $this);

        $this->getServer()->getCommandMap()->register('pillars', new MainCommand($this));

        $sbCfg    = $this->getConfig()->get('scoreboard', []);
        $interval = (int) ($sbCfg['update-interval'] ?? 20);
        if ((bool) ($sbCfg['enabled'] ?? true)) {
            $this->getScheduler()->scheduleRepeatingTask(new ScoreboardTask($this), max(1, $interval));
        }

        $this->getLogger()->info('Pillars of Fortune enabled successfully.');
    }

    protected function onDisable(): void {
        if (isset($this->gameManager, $this->mapManager)) {
            foreach (array_keys($this->gameManager->getAllGames()) as $gameId) {
                $this->mapManager->deleteInstance($gameId);
            }
        }
        if (isset($this->sessionManager)) {
            $this->sessionManager->saveAll($this->getServer()->getOnlinePlayers());
        }
        if (isset($this->npcManager)) {
            $this->npcManager->cleanup();
        }
        if (isset($this->databaseProvider)) {
            $this->databaseProvider->close();
        }
        if (isset($this->configManager)) {
            $this->configManager->saveAll();
        }
        $this->getLogger()->info('Pillars of Fortune disabled.');
    }

    private function buildDatabaseProvider(): DatabaseProvider {
        $dbConfig = $this->getConfig()->get('database', []);
        $provider = strtolower((string) ($dbConfig['provider'] ?? 'json'));
        if ($provider === 'mysql') {
            $mysql = $dbConfig['mysql'] ?? [];
            try {
                return new MySQLDatabaseProvider(
                    (string) ($mysql['host'] ?? '127.0.0.1'),
                    (int)    ($mysql['port'] ?? 3306),
                    (string) ($mysql['user'] ?? 'root'),
                    (string) ($mysql['password'] ?? ''),
                    (string) ($mysql['database'] ?? 'pillars')
                );
            } catch (\Exception $e) {
                $this->getLogger()->warning('MySQL connection failed, falling back to JSON: ' . $e->getMessage());
            }
        }
        return new JsonDatabaseProvider($this->getDataFolder());
    }

    public function getConfigManager(): ConfigManager           { return $this->configManager; }
    public function getSessionManager(): SessionManager         { return $this->sessionManager; }
    public function getLobbyManager(): LobbyManager             { return $this->lobbyManager; }
    public function getMapManager(): MapManager                 { return $this->mapManager; }
    public function getSpawnManager(): SpawnManager             { return $this->spawnManager; }
    public function getGameManager(): GameManager               { return $this->gameManager; }
    public function getNPCManager(): NPCManager                 { return $this->npcManager; }
    public function getScoreboardManager(): ScoreboardManager   { return $this->scoreboardManager; }
}
