<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Tasks;

use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\ClickSound;
use TheWindows\Pillars\Main;

class CountdownTask extends Task {

    private Main $plugin;
    private array $players;
    private string $gameId;
    private int $countdown;

    public function __construct(Main $plugin, array $players, string $gameId, int $countdown) {
        $this->plugin = $plugin;
        $this->players = $players;
        $this->gameId = $gameId;
        $this->countdown = $countdown;
        $this->plugin->getGameManager()->clearPersistentActionBar($this->gameId);
    }

    public function getCountdown(): int {
        return $this->countdown;
    }

    public function onRun(): void {
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($this->gameId);
        if ($world === null) {
            $this->plugin->getGameManager()->cancelCountdown($this->gameId);
            $this->getHandler()?->cancel();
            return;
        }

        $this->players = array_values(array_filter(
            $world->getPlayers(),
            fn(Player $p) => $p->isOnline() && !$p->isClosed() && $p->getGamemode()->equals(GameMode::ADVENTURE())
        ));

        $minPlayers = $this->plugin->getGameManager()->getMapSetting($this->gameId, 'min_players', 2);
        $maxPlayers = $this->plugin->getGameManager()->getMapSetting($this->gameId, 'max_players', 12);
        $count = count($this->players);

        if ($count < $minPlayers) {
            $this->plugin->getGameManager()->safeCancelCountdown($this->gameId);
            $this->getHandler()?->cancel();
            return;
        }

        if ($this->countdown <= 0) {
            $this->plugin->getGameManager()->startGame($this->players, $this->gameId);
            $this->getHandler()?->cancel();
            return;
        }

        $color = match(true) {
            $this->countdown <= 5 => TextFormat::RED,
            $this->countdown <= 10 => TextFormat::YELLOW,
            default => TextFormat::GREEN,
        };

        $actionBar = "{$color}Starting in {$this->countdown}s... (§6{$count}/{$maxPlayers}§r{$color})";
        foreach ($world->getPlayers() as $player) {
            if ($player->isOnline() && $player->getGamemode()->equals(GameMode::ADVENTURE())) {
                $player->sendActionBarMessage($actionBar);
            }
        }

        if ($this->countdown <= 10) {
            $chatColor = match(true) {
                $this->countdown <= 5 => '§c',
                default => '§e',
            };
            foreach ($world->getPlayers() as $player) {
                if ($player->isOnline() && $player->getGamemode()->equals(GameMode::ADVENTURE())) {
                    $player->sendMessage("§eGame starting in {$chatColor}{$this->countdown}§e seconds...");
                }
            }
            foreach ($this->players as $player) {
                if ($player->isOnline()) {
                    $player->getWorld()->addSound($player->getPosition(), new ClickSound(), [$player]);
                }
            }
            $titleColor = match(true) {
                $this->countdown <= 5 => TextFormat::RED,
                $this->countdown <= 8 => TextFormat::YELLOW,
                default => TextFormat::GREEN,
            };
            foreach ($this->players as $player) {
                if ($player->isOnline()) {
                    $player->sendTitle(
                        $titleColor . $this->countdown,
                        TextFormat::GOLD . 'Game starting...',
                        0, 20, 0
                    );
                }
            }
        }

        $this->countdown--;
    }
}
