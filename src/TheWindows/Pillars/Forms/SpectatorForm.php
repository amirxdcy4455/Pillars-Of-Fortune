<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class SpectatorForm {

    private static array $lastOpen = [];

    public static function createForm(Main $plugin, Player $spectator, array $alivePlayers): SimpleForm {
        $form = new SimpleForm(function (Player $player, $data) use ($plugin, $alivePlayers) {
            if ($data === null) {
                return;
            }
            $now = time();
            $pid = $player->getId();
            if (isset(self::$lastOpen[$pid]) && ($now - self::$lastOpen[$pid]) < 1) {
                return;
            }
            self::$lastOpen[$pid] = $now;
            if (isset($alivePlayers[$data])) {
                $target = $alivePlayers[$data];
                if ($target->isOnline() && !$target->isClosed()) {
                    $plugin->getGameManager()->teleportSpectatorToPlayer($player, $target);
                } else {
                    $player->sendMessage('§cThat player is no longer in the game!');
                }
            }
        });
        $form->setTitle('§4§lSpectate Players');
        $form->setContent('§8Select a player to teleport to:\n§7' . count($alivePlayers) . ' players alive');
        foreach ($alivePlayers as $p) {
            if ($p->isOnline()) {
                $form->addButton("§c{$p->getName()}\n§8Click to teleport");
            }
        }
        return $form;
    }
}
