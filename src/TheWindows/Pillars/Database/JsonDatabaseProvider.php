<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Database;

class JsonDatabaseProvider implements DatabaseProvider {

    private string $path;
    private array $data = [];

    private static array $defaultStats = [
        'wins' => 0,
        'coins' => 0,
        'kills' => 0,
        'deaths' => 0,
        'games_played' => 0,
    ];

    public function __construct(string $dataFolder) {
        $this->path = $dataFolder . 'players.json';
        if (file_exists($this->path)) {
            $decoded = json_decode(file_get_contents($this->path), true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    $this->data[(string) $k] = $v;
                }
            }
        }
    }

    public function loadPlayer(string $playerName): array {
        $key = strtolower(trim($playerName));
        $row = $this->data[$key] ?? self::$defaultStats;
        return [
            'wins'         => (int) ($row['wins']         ?? 0),
            'coins'        => (int) ($row['coins']        ?? 0),
            'kills'        => (int) ($row['kills']        ?? 0),
            'deaths'       => (int) ($row['deaths']       ?? 0),
            'games_played' => (int) ($row['games_played'] ?? 0),
        ];
    }

    public function savePlayer(string $playerName, array $data): void {
        $key = strtolower(trim($playerName));
        $this->data[$key] = [
            'wins'         => (int) ($data['wins']         ?? 0),
            'coins'        => (int) ($data['coins']        ?? 0),
            'kills'        => (int) ($data['kills']        ?? 0),
            'deaths'       => (int) ($data['deaths']       ?? 0),
            'games_played' => (int) ($data['games_played'] ?? 0),
        ];
        file_put_contents($this->path, json_encode($this->data, JSON_PRETTY_PRINT));
    }

    public function getTopByColumn(string $column, int $limit = 10): array {
        $entries = [];
        foreach ($this->data as $name => $stats) {
            $entries[] = [
                'player_name' => (string) $name,
                'value'       => (int) ($stats[$column] ?? 0),
            ];
        }
        usort($entries, fn($a, $b) => $b['value'] <=> $a['value']);
        return array_slice($entries, 0, $limit);
    }

    public function close(): void {}
}
