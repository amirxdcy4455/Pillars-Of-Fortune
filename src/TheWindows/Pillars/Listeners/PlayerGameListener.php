<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Listeners;

use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\inventory\InventoryTransactionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerItemUseEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\transaction\action\SlotChangeAction;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\player\Player;
use pocketmine\world\Position;
use TheWindows\Pillars\Forms\GameMenuForm;
use TheWindows\Pillars\Forms\SpectateMapForm;
use TheWindows\Pillars\Main;

class PlayerGameListener implements Listener {

    private Main $plugin;
    private array $lastInteract = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    private function isProtectedItem(Item $item): bool {
        $name = $item->getCustomName();
        return in_array($name, ['§cLeave Game', '§cLeave Queue', '§bSpectate Players', '§eSpectate Map'], true);
    }

    public function onPlayerDropItem(PlayerDropItemEvent $event): void {
        $state = $this->plugin->getSessionManager()->getState($event->getPlayer());
        if (in_array($state, ['playing', 'spectating', 'queue'], true)) {
            if ($this->isProtectedItem($event->getItem())) {
                $event->cancel();
            }
        }
    }

    public function onInventoryTransaction(InventoryTransactionEvent $event): void {
        $player = $event->getTransaction()->getSource();
        $state  = $this->plugin->getSessionManager()->getState($player);
        if (!in_array($state, ['playing', 'spectating', 'queue'], true)) return;
        foreach ($event->getTransaction()->getActions() as $action) {
            if (!$action instanceof SlotChangeAction) continue;
            if ($this->isProtectedItem($action->getSourceItem()) || $this->isProtectedItem($action->getTargetItem())) {
                $event->cancel();
                return;
            }
        }
    }

    public function onItemUse(PlayerItemUseEvent $event): void {
        $player = $event->getPlayer();
        $name   = $event->getItem()->getCustomName();
        $state  = $this->plugin->getSessionManager()->getState($player);

        if ($state === 'queue') {
            if ($name === '§cLeave Queue') {
                $this->plugin->getGameManager()->removePlayerFromGame($player);
                $event->cancel();
            }
            return;
        }

        if ($state === 'spectating') {
            if ($name === '§cLeave Game') {
                $this->plugin->getGameManager()->removePlayerFromGame($player);
                $event->cancel();
                return;
            }
            if ($name === '§bSpectate Players') {
                $pid = $player->getId();
                $now = time();
                if (isset($this->lastInteract['spectate'][$pid]) && ($now - $this->lastInteract['spectate'][$pid]) < 1) {
                    $event->cancel();
                    return;
                }
                $this->lastInteract['spectate'][$pid] = $now;
                $gameId = $this->plugin->getSessionManager()->getGameId($player);
                if ($gameId !== null) {
                    $this->plugin->getGameManager()->showSpectatorMenu($player);
                }
                $event->cancel();
                return;
            }
            $event->cancel();
            return;
        }

        if ($state === 'playing' && $name === '§cLeave Game') {
            $this->plugin->getGameManager()->removePlayerFromGame($player);
            $event->cancel();
        }
    }

    public function onInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        if ($player->hasPermission('pillars.admin') && $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            $item      = $event->getItem();
            $block     = $event->getBlock();
            $worldName = $player->getWorld()->getFolderName();
            $pos       = new Position(
                $block->getPosition()->getX() + 0.5,
                $block->getPosition()->getY() + 1,
                $block->getPosition()->getZ() + 0.5,
                $block->getPosition()->getWorld()
            );

            if ($item->equals(VanillaItems::BLAZE_ROD(), true, false)) {
                $pid = $player->getId();
                $now = time();
                if (isset($this->lastInteract['setup'][$pid]) && ($now - $this->lastInteract['setup'][$pid]) < 1) {
                    $event->cancel();
                    return;
                }
                $this->lastInteract['setup'][$pid] = $now;
                $maxPlayers = $this->plugin->getGameManager()->getMapMaxPlayers($worldName);
                if ($this->plugin->getSpawnManager()->addSpawnPoint($worldName, $pos)) {
                    $current = count($this->plugin->getSpawnManager()->getSpawnPointsForWorld($worldName));
                    $player->sendMessage("§aSpawn point added! (§6{$current}/{$maxPlayers}§a)");
                    if ($current >= $maxPlayers) {
                        $player->sendMessage("§a§lAll {$maxPlayers} spawn points set.");
                        $player->getInventory()->remove(VanillaItems::BLAZE_ROD());
                        $player->getInventory()->remove(VanillaItems::REDSTONE_DUST());
                        $this->plugin->getLobbyManager()->teleportToLobby($player);
                    }
                } else {
                    $player->sendMessage('§cMaximum spawn points reached!');
                }
                $event->cancel();
                return;
            }

            if ($item->equals(VanillaItems::REDSTONE_DUST(), true, false)) {
                $pid = $player->getId();
                $now = time();
                if (isset($this->lastInteract['setup'][$pid]) && ($now - $this->lastInteract['setup'][$pid]) < 1) {
                    $event->cancel();
                    return;
                }
                $this->lastInteract['setup'][$pid] = $now;
                $maxPlayers = $this->plugin->getGameManager()->getMapMaxPlayers($worldName);
                if ($this->plugin->getSpawnManager()->removeSpawnPoint($worldName, $pos)) {
                    $current = count($this->plugin->getSpawnManager()->getSpawnPointsForWorld($worldName));
                    $player->sendMessage("§cSpawn point removed! (§6{$current}/{$maxPlayers}§c)");
                } else {
                    $player->sendMessage('§cNo spawn point found here!');
                }
                $event->cancel();
            }
        }
    }

    public function onNPCHit(EntityDamageByEntityEvent $event): void {
        $entity  = $event->getEntity();
        $damager = $event->getDamager();
        if (!$damager instanceof Player) return;
        if (!str_contains($entity->getNameTag(), 'Pillars Minigame')) return;
        $event->cancel();
        $pid = $damager->getId();
        $now = time();
        if (isset($this->lastInteract['npc'][$pid]) && ($now - $this->lastInteract['npc'][$pid]) < 1) return;
        $this->lastInteract['npc'][$pid] = $now;
        $damager->sendForm(GameMenuForm::createForm($this->plugin, $damager));
    }

    public function onPacketReceive(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();
        $player = $event->getOrigin()->getPlayer();
        if ($player === null) return;
        $state = $this->plugin->getSessionManager()->getState($player);
        if ($state !== 'spectating') return;
        if (!($packet instanceof InventoryTransactionPacket) || !($packet->trData instanceof UseItemTransactionData)) return;

        $name = $player->getInventory()->getItemInHand()->getCustomName();
        $pid  = $player->getId();
        $now  = time();

        if ($name === '§cLeave Game') {
            $this->plugin->getGameManager()->removePlayerFromGame($player);
            $event->cancel();
            return;
        }
        if ($name === '§bSpectate Players') {
            if (isset($this->lastInteract['spectate'][$pid]) && ($now - $this->lastInteract['spectate'][$pid]) < 1) {
                $event->cancel();
                return;
            }
            $this->lastInteract['spectate'][$pid] = $now;
            $gameId = $this->plugin->getSessionManager()->getGameId($player);
            if ($gameId !== null) {
                $this->plugin->getGameManager()->showSpectatorMenu($player);
            }
            $event->cancel();
            return;
        }
        if ($name === '§eSpectate Map') {
            if (isset($this->lastInteract['specmap'][$pid]) && ($now - $this->lastInteract['specmap'][$pid]) < 1) {
                $event->cancel();
                return;
            }
            $this->lastInteract['specmap'][$pid] = $now;
            $player->sendForm(SpectateMapForm::createForm($this->plugin, $player));
            $event->cancel();
        }
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $state = $this->plugin->getSessionManager()->getState($event->getPlayer());
        if ($state === 'spectating') $event->cancel();
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        $state = $this->plugin->getSessionManager()->getState($event->getPlayer());
        if ($state === 'spectating') $event->cancel();
    }
}
