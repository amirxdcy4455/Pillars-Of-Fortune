<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class SpectateMapForm {

    public static function createForm(Main $plugin, Player $player): SimpleForm {
        $activeGames = $plugin->getGameManager()->getActiveGames();
        $gameIds     = array_keys($activeGames);

        $form = new SimpleForm(function (Player $player, $data) use ($plugin, $gameIds) {
            if ($data === null || !isset($gameIds[$data])) return;
            $gameId = $gameIds[$data];

            $wm    = $plugin->getServer()->getWorldManager();
            $world = $wm->getWorldByName($gameId);
            if ($world === null) {
                $player->sendMessage('§cWorld not found.');
                return;
            }

            $session = $plugin->getSessionManager()->get($player);
            if ($session === null) return;

            $session->setState('spectating');
            $session->setGameId($gameId);

            $player->getInventory()->clearAll();
            $player->getArmorInventory()->clearAll();
            $player->getCursorInventory()->clearAll();
            $player->setGamemode(GameMode::SPECTATOR());
            $player->setInvisible(true);
            $player->setSilent(true);
            $player->setAllowFlight(true);
            $player->setFlying(true);
            $player->setNoClientPredictions(false);
            $player->getEffects()->clear();
            $player->getHungerManager()->setEnabled(false);
            $player->setHealth($player->getMaxHealth());

            $leaveItem    = \pocketmine\block\VanillaBlocks::BED()->setColor(\pocketmine\block\utils\DyeColor::RED())->asItem()->setCustomName('§cLeave Game');
            $spectateItem = \pocketmine\item\VanillaItems::COMPASS()->setCustomName('§bSpectate Players');
            $mapsItem     = \pocketmine\item\VanillaItems::CLOCK()->setCustomName('§eSpectate Map');
            $player->getInventory()->setItem(0, $leaveItem);
            $player->getInventory()->setItem(4, $mapsItem);
            $player->getInventory()->setItem(8, $spectateItem);

            $player->teleport($world->getSpawnLocation());
            $player->sendMessage("§aNow spectating §e{$gameId}§a.");
        });

        $form->setTitle('§6§lSpectate Game');

        if (empty($gameIds)) {
            $form->setContent('§cNo active games right now.');
        } else {
            $form->setContent('§7Select a game to spectate:');
            foreach ($gameIds as $gameId) {
                $world       = $plugin->getServer()->getWorldManager()->getWorldByName($gameId);
                $playerCount = $world !== null ? count($world->getPlayers()) : 0;
                $timeLeft    = $plugin->getGameManager()->getTimeLeft($gameId);
                $form->addButton("§e{$gameId} \n §7Players: §a{$playerCount} §8| §7Time: §c" . sprintf('%02d:%02d', intdiv($timeLeft, 60), $timeLeft % 60));
            }
        }

        return $form;
    }
}
