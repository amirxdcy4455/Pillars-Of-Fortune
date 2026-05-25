<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Listeners;

use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\player\Player;
use TheWindows\Pillars\Forms\GameMenuForm;
use TheWindows\Pillars\Forms\MarketForm;
use TheWindows\Pillars\Forms\SpectateMapForm;
use TheWindows\Pillars\Main;

class LobbyListener implements Listener {

    private Main $plugin;
    private array $lastInteract = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->plugin->getSessionManager()->create($player);
        $this->plugin->getLobbyManager()->prepareLobbyPlayer($player);
        $this->plugin->getLobbyManager()->giveLobbyItems($player);
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }
        if ($this->plugin->getLobbyManager()->isLobbyWorld($entity)) {
            $event->cancel();
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        if ($this->plugin->getLobbyManager()->isLobbyWorld($player)) {
            $event->cancel();
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        if ($this->plugin->getLobbyManager()->isLobbyWorld($player)) {
            $event->cancel();
        }
    }

    public function onPlayerDropItem(PlayerDropItemEvent $event): void {
        $player = $event->getPlayer();
        if (!$this->plugin->getLobbyManager()->isLobbyWorld($player)) {
            return;
        }
        $state = $this->plugin->getSessionManager()->getState($player);
        if ($state !== 'lobby') {
            return;
        }
        if ($this->plugin->getLobbyManager()->isLobbyItem($player, $event->getItem()->getCustomName())) {
            $event->cancel();
        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        if (!$this->plugin->getLobbyManager()->isLobbyWorld($player)) {
            return;
        }
        $state = $this->plugin->getSessionManager()->getState($player);
        if ($state !== 'lobby') {
            return;
        }
        foreach ($event->getTransaction()->getActions() as $action) {
            if (!$action instanceof SlotChangeAction) {
                continue;
            }
            $name = $action->getSourceItem()->getCustomName();
            if ($this->plugin->getLobbyManager()->isLobbyItem($player, $name)) {
                $event->cancel();
                return;
            }
        }
    }

    public function onItemUse(PlayerItemUseEvent $event): void {
        $player = $event->getPlayer();
        if (!$this->plugin->getLobbyManager()->isLobbyWorld($player)) {
            return;
        }
        $state = $this->plugin->getSessionManager()->getState($player);
        if ($state !== 'lobby') {
            return;
        }
        $item = $event->getItem();
        $name = $item->getCustomName();
        $settings = $this->plugin->getConfig()->get('settings', []);
        $menuName = (string) ($settings['game_menu']['name'] ?? '§4Game Menu §7(Right Click)');
        $marketName = (string) ($settings['market']['name'] ?? '§d§lMarket §7(Right Click)');
        $spectateMapName = (string) ($settings['spectate_map']['name'] ?? '§eSpectate Map');

        $now = time();
        $pid = $player->getId();

        if ($name === $menuName) {
            if (isset($this->lastInteract['menu'][$pid]) && ($now - $this->lastInteract['menu'][$pid]) < 1) {
                $event->cancel();
                return;
            }
            $this->lastInteract['menu'][$pid] = $now;
            $player->sendForm(GameMenuForm::createForm($this->plugin, $player));
            $event->cancel();
            return;
        }

        if ($name === $marketName) {
            if (isset($this->lastInteract['market'][$pid]) && ($now - $this->lastInteract['market'][$pid]) < 1) {
                $event->cancel();
                return;
            }
            $this->lastInteract['market'][$pid] = $now;
            $player->sendForm(MarketForm::createForm());
            $event->cancel();
            return;
        }

        if ($name === $spectateMapName) {
            if (isset($this->lastInteract['specgame'][$pid]) && ($now - $this->lastInteract['specgame'][$pid]) < 1) {
                $event->cancel();
                return;
            }
            $this->lastInteract['specgame'][$pid] = $now;
            $player->sendForm(SpectateMapForm::createForm($this->plugin, $player));
            $event->cancel();
        }
    }
}
