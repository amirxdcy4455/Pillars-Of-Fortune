<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Listeners;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class PlayerDamageListener implements Listener {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if (!$entity instanceof Player) {
            return;
        }

        $gm = $this->plugin->getGameManager();

        if ($gm->isPlayerInvulnerable($entity)) {
            $event->cancel();
            return;
        }

        $state = $this->plugin->getSessionManager()->getState($entity);

        if ($state === 'spectating' || $state === 'queue' || $state === null || $state === 'lobby') {
            $event->cancel();
            return;
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            $damager = $event->getDamager();
            if ($damager instanceof Player) {
                if ($gm->isPlayerInvulnerable($damager)) {
                    $event->cancel();
                    return;
                }
                $damagerState = $this->plugin->getSessionManager()->getState($damager);
                if ($damagerState === 'queue' || $damagerState === 'spectating') {
                    $event->cancel();
                    return;
                }
            }
        }

        if ($state === 'playing') {
            $newHealth = $entity->getHealth() - $event->getFinalDamage();
            if ($newHealth <= 0.1) {
                $event->cancel();
                $entity->setHealth($entity->getMaxHealth());
                $gameId = $this->plugin->getSessionManager()->getGameId($entity);
                if ($gameId !== null) {
                    $gm->handlePlayerDeath($entity, $gameId);
                }
            }
        }
    }
}
