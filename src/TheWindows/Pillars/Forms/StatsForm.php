<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class StatsForm {

    public static function createForm(Main $plugin, Player $player): SimpleForm {
        $session = $plugin->getSessionManager()->get($player);
        $wins = $session?->getWins() ?? 0;
        $coins = $session?->getCoins() ?? 0;
        $kills = $session?->getKills() ?? 0;
        $deaths = $session?->getDeaths() ?? 0;
        $played = $session?->getGamesPlayed() ?? 0;
        $kdr = $session?->getKDR() ?? 0.0;
        $winRate = $session?->getWinRate() ?? 0.0;

        $form = new SimpleForm(function (Player $player, $data) {});
        $form->setTitle('§4§lPlayer Statistics');
        $form->setContent(
            "§8--------------------\n" .
            "§4Player: §f{$player->getName()}\n" .
            "§8--------------------\n\n" .
            "§6Wins:         §e{$wins}\n" .
            "§6Coins:        §e{$coins}\n" .
            "§6Kills:        §e{$kills}\n" .
            "§6Deaths:       §e{$deaths}\n" .
            "§6K/D Ratio:    §e{$kdr}\n" .
            "§6Games Played: §e{$played}\n" .
            "§6Win Rate:     §e{$winRate}%\n\n" .
            "§8--------------------"
        );
        $form->addButton('§cClose');
        return $form;
    }
}
