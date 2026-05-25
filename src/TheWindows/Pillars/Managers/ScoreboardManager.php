<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Managers;

use pocketmine\network\mcpe\protocol\RemoveObjectivePacket;
use pocketmine\network\mcpe\protocol\SetDisplayObjectivePacket;
use pocketmine\network\mcpe\protocol\SetScorePacket;
use pocketmine\network\mcpe\protocol\types\ScorePacketEntry;
use pocketmine\player\Player;
use TheWindows\Pillars\Main;

class ScoreboardManager {

    private const OBJECTIVE = 'pillars_sb';
    private Main $plugin;
    private array $active = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function sendLobby(Player $player): void {
        $cfg = $this->section('lobby');
        if (!($cfg['enabled'] ?? true)) return;
        $this->send($player, $cfg, []);
    }

    public function sendWaiting(Player $player, string $gameId): void {
        $cfg = $this->section('waiting');
        if (!($cfg['enabled'] ?? true)) return;
        $this->send($player, $cfg, ['gameId' => $gameId]);
    }

    public function sendGame(Player $player, string $gameId): void {
        $cfg = $this->section('game');
        if (!($cfg['enabled'] ?? true)) return;
        $this->send($player, $cfg, ['gameId' => $gameId]);
    }

    public function sendSpectator(Player $player, string $gameId): void {
        $cfg = $this->section('spectator');
        if (!($cfg['enabled'] ?? true)) return;
        $this->send($player, $cfg, ['gameId' => $gameId]);
    }

    public function remove(Player $player): void {
        $pid = $player->getId();
        if (!isset($this->active[$pid])) return;
        $player->getNetworkSession()->sendDataPacket(RemoveObjectivePacket::create($this->active[$pid]));
        unset($this->active[$pid]);
    }

    public function refresh(Player $player): void {
        if (!$player->isOnline()) return;
        $state  = $this->plugin->getSessionManager()->getState($player);
        $gameId = $this->plugin->getSessionManager()->getGameId($player);

        match ($state) {
            'lobby'      => $this->sendLobby($player),
            'queue'      => $this->sendWaiting($player, $gameId ?? ''),
            'playing'    => $this->sendGame($player, $gameId ?? ''),
            'spectating' => $this->sendSpectator($player, $gameId ?? ''),
            default      => null,
        };
    }

    private function send(Player $player, array $cfg, array $ctx): void {
        $pid      = $player->getId();
        $objectId = self::OBJECTIVE . '_' . $pid;
        $title    = $this->resolve($cfg['title'] ?? '§6Pillars', $player, $ctx, $cfg);
        $rawLines = $cfg['lines'] ?? [];

        if (isset($this->active[$pid])) {
            $player->getNetworkSession()->sendDataPacket(RemoveObjectivePacket::create($this->active[$pid]));
        }
        $this->active[$pid] = $objectId;

        $player->getNetworkSession()->sendDataPacket(
            SetDisplayObjectivePacket::create(
                SetDisplayObjectivePacket::DISPLAY_SLOT_SIDEBAR,
                $objectId,
                $title,
                'dummy',
                SetDisplayObjectivePacket::SORT_ORDER_ASCENDING
            )
        );

        $entries   = [];
        $usedTexts = [];
        foreach (array_reverse($rawLines) as $i => $line) {
            $text = $this->resolve((string) $line, $player, $ctx, $cfg);
            while (in_array($text, $usedTexts, true)) {
                $text .= '§r';
            }
            $usedTexts[] = $text;

            $entry = new ScorePacketEntry();
            $entry->objectiveName = $objectId;
            $entry->type          = ScorePacketEntry::TYPE_FAKE_PLAYER;
            $entry->customName    = $text;
            $entry->score         = count($rawLines) - $i;
            $entry->scoreboardId  = $pid * 100 + $i;
            $entries[]            = $entry;
        }

        if (!empty($entries)) {
            $player->getNetworkSession()->sendDataPacket(SetScorePacket::create(SetScorePacket::TYPE_CHANGE, $entries));
        }
    }

    private function resolve(string $text, Player $player, array $ctx, array $cfg): string {
        $session = $this->plugin->getSessionManager()->getSession($player);
        $gm      = $this->plugin->getGameManager();
        $gameId  = $ctx['gameId'] ?? null;
        $now     = time();

        $replacements = [
            '{player_name}'      => $player->getName(),
            '{online_players}'   => (string) count($this->plugin->getServer()->getOnlinePlayers()),
            '{player_coins}'     => $session !== null ? (string) $session->getCoins()       : '0',
            '{player_kills}'     => $session !== null ? (string) $session->getKills()       : '0',
            '{player_deaths}'    => $session !== null ? (string) $session->getDeaths()      : '0',
            '{player_wins}'      => $session !== null ? (string) $session->getWins()        : '0',
            '{player_kdr}'       => $session !== null ? (string) $session->getKDR()         : '0',
            '{player_games}'     => $session !== null ? (string) $session->getGamesPlayed() : '0',
            '{datetime}'         => date((string) ($cfg['datetime-format'] ?? 'Y/m/d H:i:s'), $now),
            '{date}'             => date((string) ($cfg['date-format']     ?? 'Y/m/d'),        $now),
            '{time}'             => date((string) ($cfg['time-format']     ?? 'H:i:s'),        $now),
            '{game_id}'          => $gameId ?? '-',
            '{game_players}'     => $gameId !== null ? (string) $gm->getPlayerCount($gameId)               : '0',
            '{game_max_players}' => $gameId !== null ? (string) $gm->getMapSetting($gameId, 'max_players', 0) : '0',
            '{game_min_players}' => $gameId !== null ? (string) $gm->getMapSetting($gameId, 'min_players', 0) : '0',
            '{game_time_left}'   => $gameId !== null ? $this->formatTime($gm->getTimeLeft($gameId))        : '--:--',
            '{game_status}'      => $gameId !== null ? ($gm->getGameStatus($gameId) ?? 'waiting')          : 'lobby',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $text);
    }

    private function formatTime(int $seconds): string {
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    private function section(string $name): array {
        return (array) ($this->plugin->getConfig()->get('scoreboard', [])[$name] ?? []);
    }
}
