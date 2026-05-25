<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Forms;

use jojoe77777\FormAPI\CustomForm;
use jojoe77777\FormAPI\SimpleForm;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\utils\Filesystem;
use TheWindows\Pillars\Main;

class AdminSettingsForm {

    public static function createForm(Main $plugin, Player $player): SimpleForm {
        $form = new SimpleForm(function (Player $player, $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            match($data) {
                0 => self::createGameForm($plugin, $player),
                1 => (function () use ($player) {
                    $player->getInventory()->addItem(VanillaItems::BLAZE_ROD()->setCustomName('§4Set Spawn Wand'));
                    $player->sendMessage('§aYou received the spawn wand.');
                })(),
                2 => (function () use ($player) {
                    $player->getInventory()->addItem(VanillaItems::REDSTONE_DUST()->setCustomName('§4Remove Spawn Tool'));
                    $player->sendMessage('§aYou received the remove tool.');
                })(),
                3 => $plugin->getNPCManager()->createNPC($player),
                4 => (function () use ($plugin, $player) {
                    $plugin->getNPCManager()->removeAllNPCs();
                    $player->sendMessage('§aAll NPCs removed!');
                })(),
                5 => self::removeArenaForm($plugin, $player),
                6 => self::particleSettingsForm($plugin, $player),
                default => null,
            };
        });
        $form->setTitle('§4§lPillars Admin Menu');
        $form->addButton("§4Create Game\n§8Setup new game arena");
        $form->addButton("§4Get Spawn Wand\n§8Set spawn points");
        $form->addButton("§4Get Remove Tool\n§8Remove spawn points");
        $form->addButton("§4Create NPC\n§8Spawn game joining NPC");
        $form->addButton("§4Remove All NPCs\n§8Remove all game NPCs");
        $form->addButton("§4Remove Arena\n§8Delete a game arena");
        $form->addButton("§4Particle Settings\n§8Customize NPC effects");
        return $form;
    }

    private static function particleSettingsForm(Main $plugin, Player $player): void {
        $nm = $plugin->getNPCManager();
        $styles = ['rotating_ring', 'spiral', 'double_helix', 'pulse', 'rain', 'crown'];
        $styleIndex = array_search($nm->getParticleStyle(), $styles) ?: 0;

        $form = new CustomForm(function (Player $player, $data) use ($plugin, $nm, $styles) {
            if ($data === null) {
                return;
            }
            if ($data[1] !== null) {
                $nm->setParticleStyle($styles[(int) $data[1]] ?? 'rotating_ring');
            }
            if ($data[2] !== null) {
                $nm->setParticleColor((int) $data[2]);
            }
            if ($data[3] !== null) {
                $nm->setParticleSpeed((float) $data[3]);
            }
            if ($data[4] !== null) {
                $nm->setParticleDensity((int) $data[4]);
            }
            if ($data[5] !== null) {
                $nm->setParticlesEnabled((bool) $data[5]);
            }
            $nm->saveConfig();
            $player->sendMessage('§aParticle settings updated!');
        });
        $form->setTitle('§4§lParticle Settings');
        $form->addLabel('§8Customize NPC particle effects');
        $form->addDropdown('§4Particle Style', [
            '§4Rotating Ring§8 - Particles circle around',
            '§4Spiral§8 - Particles spiral upward',
            '§4Double Helix§8 - Two intertwined spirals',
            '§4Pulse Wave§8 - Pulsing rings',
            '§4Particle Rain§8 - Falling particles',
            '§4Crown§8 - Crown-like formation',
        ], (int) $styleIndex);
        $form->addDropdown('§4Particle Color', [
            '§4Dark Red', '§2Dark Green', '§1Dark Blue',
            '§6Gold', '§5Dark Purple', '§6Orange', '§7Gray',
        ], $nm->getParticleColorIndex());
        $form->addSlider('§4Particle Speed', 1, 10, 1, $nm->getParticleSpeed());
        $form->addSlider('§4Particle Density', 2, 12, 1, $nm->getParticleDensity());
        $form->addToggle('§4Enable Particles', $nm->isParticlesEnabled());
        $player->sendForm($form);
    }

    private static function createGameForm(Main $plugin, Player $player): void {
        $form = new CustomForm(function (Player $player, $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            $worldName = trim((string) $data[0]);
            if (empty($worldName)) {
                $player->sendMessage('§cWorld name cannot be empty!');
                return;
            }
            if ($plugin->getGameManager()->gameExists($worldName)) {
                $player->sendMessage("§cGame already exists in '$worldName'!");
                return;
            }
            $maxPlayers = max(2, min(24, (int) $data[1]));
            $minPlayers = max(2, min($maxPlayers, (int) $data[2]));
            $countdownTime = max(5, min(60, (int) $data[3]));
            $itemInterval = max(3, min(300, (int) $data[4]));

            $worldsPath = $plugin->getServer()->getDataPath() . 'worlds/';
            $mapsPath = $plugin->getDataFolder() . 'Maps/';
            $sourcePath = $worldsPath . $worldName;
            $templatePath = $mapsPath . $worldName;

            if (!is_dir($sourcePath)) {
                $player->sendMessage("§cWorld '$worldName' not found in worlds folder!");
                return;
            }

            $wm = $plugin->getServer()->getWorldManager();
            if ($wm->isWorldLoaded($worldName)) {
                $world = $wm->getWorldByName($worldName);
                if ($world !== null) {
                    foreach ($world->getPlayers() as $p) {
                        $p->teleport($wm->getDefaultWorld()->getSpawnLocation());
                    }
                    $wm->unloadWorld($world);
                }
            }
            if (!is_dir($templatePath)) {
                mkdir($templatePath, 0755, true);
            }
            try {
                $plugin->getMapManager()->recursiveCopy($sourcePath, $templatePath);
            } catch (\Exception $e) {
                $player->sendMessage('§cFailed to create template: ' . $e->getMessage());
                return;
            }

            $wm->loadWorld($worldName);
            if ($plugin->getGameManager()->createGame($worldName, $maxPlayers, $minPlayers, 1200, $countdownTime, $itemInterval)) {
                $player->sendMessage("§aGame created in '$worldName'!");
                $player->sendMessage("§6Max: §e{$maxPlayers} §6Min: §e{$minPlayers}");
                $player->getInventory()->addItem(VanillaItems::BLAZE_ROD()->setCustomName('§4Set Spawn Wand'));
                $player->getInventory()->addItem(VanillaItems::REDSTONE_DUST()->setCustomName('§4Remove Spawn Tool'));
                $world = $wm->getWorldByName($worldName);
                if ($world !== null) {
                    $player->teleport($world->getSpawnLocation());
                    $plugin->getMapManager()->autoSetupSpawnPoints($worldName, $worldName);
                }
            } else {
                $player->sendMessage("§cFailed to create game in '$worldName'!");
            }
        });
        $form->setTitle('§4§lCreate New Game');
        $form->addInput('§4World Name:', 'world_name');
        $form->addInput('§4Max Players (2-24):', '8', '8');
        $form->addInput('§4Min Players to Start (2-Max):', '2', '2');
        $form->addInput('§4Countdown Time seconds (5-60):', '30', '30');
        $form->addInput('§4Item Interval seconds (3-300):', '10', '10');
        $player->sendForm($form);
    }

    private static function removeArenaForm(Main $plugin, Player $player): void {
        $form = new CustomForm(function (Player $player, $data) use ($plugin) {
            if ($data === null) {
                return;
            }
            $worldName = trim((string) $data[0]);
            if (empty($worldName)) {
                $player->sendMessage('§cWorld name cannot be empty!');
                return;
            }
            if ($plugin->getGameManager()->removeGame($worldName)) {
                $mapsPath = $plugin->getDataFolder() . 'Maps/' . $worldName;
                if (is_dir($mapsPath)) {
                    try {
                        Filesystem::recursiveUnlink($mapsPath);
                    } catch (\Exception $e) {
                        $player->sendMessage('§cFailed to remove map template: ' . $e->getMessage());
                    }
                }
                $player->sendMessage("§aArena '$worldName' removed successfully!");
            } else {
                $player->sendMessage("§cArena '$worldName' not found!");
            }
        });
        $form->setTitle('§4§lRemove Arena');
        $form->addInput('§4World Name to Remove:', 'world_name');
        $player->sendForm($form);
    }
}
