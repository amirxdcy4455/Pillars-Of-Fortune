<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\SimpleForm;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class LeaderboardForm {

    public static function createForm(Main $plugin, Player $player): SimpleForm {
        $form = new SimpleForm(function (Player $player, $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            match ($data) {
                0 => $player->sendForm(self::createLeaderboardList($plugin, $player, 'wins', 'Top Wins')),
                1 => $player->sendForm(self::createLeaderboardList($plugin, $player, 'kills', 'Top Kills')),
                2 => $player->sendForm(self::createLeaderboardList($plugin, $player, 'games_played', 'Most Games Played')),
                3 => $player->sendForm(self::createLeaderboardList($plugin, $player, 'coins', 'Top Coins')),
                default => null,
            };
        });

        $form->setTitle('§4§lLeaderboards');
        $form->setContent('§8Select a leaderboard to view:');
        $form->addButton("§6Wins\n§7Top winners");
        $form->addButton("§cKills\n§7Top killers");
        $form->addButton("§aGames Played\n§7Most active");
        $form->addButton("§eCoins\n§7Richest players");
        return $form;
    }

    private static function createLeaderboardList(Main $plugin, Player $player, string $column, string $title): SimpleForm {
        $entries = $plugin->getSessionManager()->getDatabase()->getTopByColumn($column, 10);

        $content = "§8====================\n";

        if (empty($entries)) {
            $content .= "§7No data available yet.\n";
        } else {
            $medals = ['§6#1', '§7#2', '§7#3'];
            foreach ($entries as $rank => $entry) {
                $prefix = $medals[$rank] ?? "§8#" . ($rank + 1);
                $name = (string) $entry['player_name'];
                $value = (int) $entry['value'];
                $label = match ($column) {
                    'wins' => "{$value} wins",
                    'kills' => "{$value} kills",
                    'games_played' => "{$value} games",
                    'coins' => "{$value} coins",
                    default => (string) $value,
                };
                $highlight = strtolower($player->getName()) === strtolower($name) ? '§e' : '§f';
                $content .= "{$prefix} {$highlight}{$name} §7- §a{$label}\n";
            }
        }

        $content .= "§8====================";

        $form = new SimpleForm(function (Player $player, $data) use ($plugin) {
            if ($data === null || $data === 0) {
                $player->sendForm(self::createForm($plugin, $player));
            }
        });

        $form->setTitle("§4§l{$title}");
        $form->setContent($content);
        $form->addButton('§cBack');
        return $form;
    }
}
