<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use TheWindows\Pillars\Forms\AdminSettingsForm;
use TheWindows\Pillars\Forms\GameMenuForm;
use TheWindows\Pillars\Forms\StatsForm;
use TheWindows\Pillars\Main;

class MainCommand extends Command {

    private Main $plugin;

    public function __construct(Main $plugin) {
        parent::__construct('pillars', 'Main Pillars command', '/pillars [join|leave|list|stats|info|admin|npc|reset]');
        $this->setPermission('pillars.join');
        $this->setAliases(['p']);
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!$this->testPermission($sender)) {
            return false;
        }
        if (!$sender instanceof Player) {
            $sender->sendMessage('This command can only be used in-game.');
            return false;
        }
        if (empty($args)) {
            $sender->sendForm(GameMenuForm::createForm($this->plugin, $sender));
            return true;
        }

        $sub = strtolower($args[0]);
        $gm = $this->plugin->getGameManager();

        match($sub) {
            'join', 'j' => (function () use ($sender, $args, $gm) {
                if (isset($args[1])) {
                    $session = $this->plugin->getSessionManager()->get($sender);
                    if ($session !== null && !$session->isInLobby()) {
                        $sender->sendMessage('§cYou are already in a game! Use /pillars leave first.');
                        return;
                    }
                    if ($gm->gameExists($args[1])) {
                        $gm->addPlayerToGame($sender, $args[1]);
                    } else {
                        $sender->sendMessage("§cMap '{$args[1]}' not found!");
                        $games = $gm->getAvailableGames();
                        if (!empty($games)) {
                            $names = implode('§7, §6', array_column($games, 'world'));
                            $sender->sendMessage("§aAvailable: §6{$names}");
                        }
                    }
                } else {
                    $sender->sendForm(GameMenuForm::createForm($this->plugin, $sender));
                }
            })(),
            'leave', 'l', 'quit' => (function () use ($sender, $gm) {
                $session = $this->plugin->getSessionManager()->get($sender);
                if ($session === null || $session->isInLobby()) {
                    $sender->sendMessage('§cYou are not in any game!');
                    return;
                }
                $gm->removePlayerFromGame($sender);
                $sender->sendMessage('§aYou have left the game!');
            })(),
            'list', 'ls', 'games' => (function () use ($sender, $gm) {
                $games = $gm->getAvailableGames();
                if (empty($games)) {
                    $sender->sendMessage('§cNo games available!');
                    return;
                }
                $sender->sendMessage('§6§lPillars Games:');
                foreach ($games as $game) {
                    $sender->sendMessage("§e{$game['world']} §7- §a{$game['players']}§7/§c{$game['max_players']} §7- {$game['status']}");
                }
            })(),
            'stats', 'stat', 's' => $sender->sendForm(StatsForm::createForm($this->plugin, $sender)),
            'info', 'i' => (function () use ($sender, $gm) {
                $games = $gm->getAvailableGames();
                $total = count($games);
                $players = array_sum(array_column($games, 'players'));
                $sender->sendMessage('§6§lPillars Plugin:');
                $sender->sendMessage("§eVersion: §a" . $this->plugin->getDescription()->getVersion());
                $sender->sendMessage("§eAuthors: §a" . implode(', ', $this->plugin->getDescription()->getAuthors()));
                $sender->sendMessage("§eTotal Games: §a{$total} | Players: §a{$players}");
            })(),
            'admin', 'a' => (function () use ($sender) {
                if (!$sender->hasPermission('pillars.admin')) {
                    $sender->sendMessage('§cNo permission!');
                    return;
                }
                $sender->sendForm(AdminSettingsForm::createForm($this->plugin, $sender));
            })(),
            'npc', 'n' => (function () use ($sender, $args) {
                if (!$sender->hasPermission('pillars.admin')) {
                    $sender->sendMessage('§cNo permission!');
                    return;
                }
                $nm = $this->plugin->getNPCManager();
                $npcSub = strtolower($args[1] ?? '');
                match($npcSub) {
                    'create', 'c' => $nm->createNPC($sender),
                    'list', 'ls', 'l' => (function () use ($sender, $nm) {
                        $npcs = $nm->getNPCs();
                        if (empty($npcs)) {
                            $sender->sendMessage('§cNo NPCs found.');
                            return;
                        }
                        $sender->sendMessage('§aNPC List:');
                        foreach ($npcs as $id => $data) {
                            $sender->sendMessage("§7#{$id}: {$data['world']} ({$data['x']}, {$data['y']}, {$data['z']})");
                        }
                    })(),
                    'remove', 'r' => (function () use ($sender, $args, $nm) {
                        if (!isset($args[2]) || !is_numeric($args[2])) {
                            $sender->sendMessage('§cUsage: /p npc remove <index>');
                            return;
                        }
                        $nm->removeNPC((int) $args[2])
                            ? $sender->sendMessage('§aNPC removed!')
                            : $sender->sendMessage('§cInvalid NPC index.');
                    })(),
                    'removeall', 'ra' => (function () use ($sender, $nm) {
                        $nm->removeAllNPCs();
                        $sender->sendMessage('§aAll NPCs removed!');
                    })(),
                    default => $sender->sendMessage('§cUsage: /p npc <create|list|remove|removeall>'),
                };
            })(),
            'reset' => (function () use ($sender, $args, $gm) {
                if (!$sender->hasPermission('pillars.admin')) {
                    $sender->sendMessage('§cNo permission!');
                    return;
                }
                if (empty($args[1])) {
                    $sender->sendMessage('§eUsage: /pillars reset <mapname|all>');
                    return;
                }
                if ($args[1] === 'all') {
                    $count = 0;
                    foreach ($gm->getAvailableGames() as $game) {
                        if ($this->plugin->getMapManager()->resetWorld($game['world'])) {
                            $count++;
                        }
                    }
                    $sender->sendMessage("§aReset §6{$count} §amaps.");
                } else {
                    $this->plugin->getMapManager()->resetWorld($args[1])
                        ? $sender->sendMessage("§aReset map: §6{$args[1]}")
                        : $sender->sendMessage("§cFailed to reset: §6{$args[1]}");
                }
            })(),
            default => $sender->sendMessage('§cUnknown command. Use /pillars for help.'),
        };

        return true;
    }
}
