<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Lobby;

use pocketmine\player\Player;
use pocketmine\player\GameMode;
use pocketmine\item\VanillaItems;
use TheWindows\Pillars\Main;

class LobbyManager {

    private Main $plugin;
    private string $lobbyWorldName;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->lobbyWorldName = (string) $plugin->getConfig()->get('lobby-world', 'world');
    }

    public function getLobbyWorldName(): string {
        return $this->lobbyWorldName;
    }

    public function isLobbyWorld(Player $player): bool {
        return $player->getWorld()->getFolderName() === $this->lobbyWorldName;
    }

    public function loadLobbyWorld(): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        if (!$wm->isWorldLoaded($this->lobbyWorldName)) {
            $wm->loadWorld($this->lobbyWorldName);
        }
    }

    public function teleportToLobby(Player $player): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        $world = $wm->getWorldByName($this->lobbyWorldName) ?? $wm->getDefaultWorld();
        if ($world !== null) {
            $player->teleport($world->getSpawnLocation());
        }
    }

    public function giveLobbyItems(Player $player): void {
        $settings = $this->plugin->getConfig()->get('settings', []);
        $menuSettings = $settings['game_menu'] ?? ['slot' => 0, 'name' => '§4Game Menu §7(Right Click)'];
        $marketSettings = $settings['market'] ?? ['slot' => 8, 'name' => '§d§lMarket §7(Right Click)'];

        $player->getInventory()->clearAll();
        $player->getArmorInventory()->clearAll();
        $player->getCursorInventory()->clearAll();

        $menuItem = VanillaItems::COMPASS()->setCustomName((string) $menuSettings['name']);
        $player->getInventory()->setItem((int) $menuSettings['slot'], $menuItem);

        $marketItem = VanillaItems::NETHER_STAR()->setCustomName((string) $marketSettings['name']);
        $player->getInventory()->setItem((int) $marketSettings['slot'], $marketItem);

        $spectateMapSettings = $settings['spectate_map'] ?? ['slot' => 4, 'name' => '§eSpectate Map'];
        $clockItem = VanillaItems::CLOCK()->setCustomName((string) $spectateMapSettings['name']);
        $player->getInventory()->setItem((int) $spectateMapSettings['slot'], $clockItem);
    }

    public function prepareLobbyPlayer(Player $player): void {
        $player->setGamemode(GameMode::ADVENTURE());
        $player->setNoClientPredictions(false);
        $player->setHealth($player->getMaxHealth());
        $player->getEffects()->clear();
        $player->setInvisible(false);
        $player->setSilent(false);
        $player->setAllowFlight(false);
        $player->setFlying(false);
        $player->getHungerManager()->setEnabled(true);
        $player->getHungerManager()->setFood(20);
        $player->getHungerManager()->setSaturation(20);
    }

    public function isLobbyItem(Player $player, string $itemName): bool {
        $settings = $this->plugin->getConfig()->get('settings', []);
        $menuName = (string) ($settings['game_menu']['name'] ?? '§4Game Menu §7(Right Click)');
        $marketName = (string) ($settings['market']['name'] ?? '§d§lMarket §7(Right Click)');
        $spectateMapName = (string) ($settings['spectate_map']['name'] ?? '§eSpectate Map');
        return $itemName === $menuName || $itemName === $marketName || $itemName === $spectateMapName;
    }
}
