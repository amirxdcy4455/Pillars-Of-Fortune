<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Database;

class MySQLDatabaseProvider implements DatabaseProvider {

    private \mysqli $db;

    private static array $defaultStats = [
        'wins' => 0,
        'coins' => 0,
        'kills' => 0,
        'deaths' => 0,
        'games_played' => 0,
    ];

    private static array $allowedColumns = ['wins', 'coins', 'kills', 'deaths', 'games_played'];

    public function __construct(string $host, int $port, string $user, string $password, string $database) {
        $this->db = new \mysqli($host, $user, $password, $database, $port);
        if ($this->db->connect_error) {
            throw new \RuntimeException('MySQL connection failed: ' . $this->db->connect_error);
        }
        $this->db->query("
            CREATE TABLE IF NOT EXISTS pillars_players (
                player_name VARCHAR(64) PRIMARY KEY,
                wins INT NOT NULL DEFAULT 0,
                coins INT NOT NULL DEFAULT 0,
                kills INT NOT NULL DEFAULT 0,
                deaths INT NOT NULL DEFAULT 0,
                games_played INT NOT NULL DEFAULT 0
            )
        ");
    }

    public function loadPlayer(string $playerName): array {
        $key = strtolower($playerName);
        $stmt = $this->db->prepare('SELECT wins, coins, kills, deaths, games_played FROM pillars_players WHERE player_name = ?');
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return [
                'wins' => (int) $row['wins'],
                'coins' => (int) $row['coins'],
                'kills' => (int) $row['kills'],
                'deaths' => (int) $row['deaths'],
                'games_played' => (int) $row['games_played'],
            ];
        }
        return self::$defaultStats;
    }

    public function savePlayer(string $playerName, array $data): void {
        $key = strtolower($playerName);
        $stmt = $this->db->prepare('
            INSERT INTO pillars_players (player_name, wins, coins, kills, deaths, games_played)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE wins=VALUES(wins), coins=VALUES(coins), kills=VALUES(kills),
            deaths=VALUES(deaths), games_played=VALUES(games_played)
        ');
        $stmt->bind_param(
            'siiiii',
            $key,
            $data['wins'],
            $data['coins'],
            $data['kills'],
            $data['deaths'],
            $data['games_played']
        );
        $stmt->execute();
    }

    public function getTopByColumn(string $column, int $limit = 10): array {
        if (!in_array($column, self::$allowedColumns, true)) {
            return [];
        }
        $result = $this->db->query("SELECT player_name, {$column} AS value FROM pillars_players ORDER BY {$column} DESC LIMIT {$limit}");
        $entries = [];
        while ($row = $result->fetch_assoc()) {
            $entries[] = [
                'player_name' => $row['player_name'],
                'value' => (int) $row['value'],
            ];
        }
        return $entries;
    }

    public function close(): void {
        $this->db->close();
    }
}
