<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class GameMenuForm {

    public static function createForm(Main $plugin, Player $player): SimpleForm {
        $form = new SimpleForm(function (Player $player, $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            match ($data) {
                0 => $plugin->getGameManager()->findOrCreateGame($player),
                1 => $player->sendForm(LeaderboardForm::createForm($plugin, $player)),
                2 => $player->sendForm(StatsForm::createForm($plugin, $player)),
                default => null,
            };
        });

        $session = $plugin->getSessionManager()->get($player);
        $wins = $session?->getWins() ?? 0;
        $kills = $session?->getKills() ?? 0;
        $games = $plugin->getGameManager()->getAvailableGames();
        $waitingCount = count(array_filter($games, fn($g) => str_starts_with($g['status'], '§a')));

        $form->setTitle('§4§lPillars of Fortune');
        $form->setContent(
            "§8--------------------\n" .
            "§aWaiting games: §f{$waitingCount}\n" .
            "§6Your wins: §f{$wins} §8| §6Kills: §f{$kills}\n" .
            "§8--------------------"
        );
        $form->addButton("§aQuick Join\n§7Find or create a game");
        $form->addButton("§eLeaderboards\n§7Top players");
        $form->addButton("§bStats\n§7Your statistics");
        return $form;
    }
}
