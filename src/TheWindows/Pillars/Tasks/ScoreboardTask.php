<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Tasks;

use pocketmine\scheduler\Task;
use TheWindows\Pillars\Main;

class ScoreboardTask extends Task {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $sbm = $this->plugin->getScoreboardManager();
        foreach ($this->plugin->getServer()->getOnlinePlayers() as $player) {
            if ($player->isOnline() && !$player->isClosed()) {
                $sbm->refresh($player);
            }
        }
    }
}
