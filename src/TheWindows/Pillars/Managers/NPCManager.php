<?php
declare(strict_types=1);
namespace TheWindows\Pillars\Managers;

use pocketmine\color\Color;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\world\particle\DustParticle;
use SQLite3;
use TheWindows\Pillars\Entity\PillarsNPC;
use TheWindows\Pillars\Main;

class NPCManager {

    private Main $plugin;
    private array $npcs = [];
    private array $knownNPCs = [];
    private array $lookAtPlayers = [];
    private SQLite3 $database;

    private bool $particlesEnabled = true;
    private string $particleStyle = 'rotating_ring';
    private int $particleColor = 7;
    private int $particleSpeed = 5;
    private int $particleDensity = 6;

    private array $colors = [];

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
        $this->initColors();
        $this->loadConfig();
        $this->initDatabase();
        $this->loadNPCs();
        $this->spawnAllNPCs();
        $this->startRotationTask();
    }

    private function initColors(): void {
        $this->colors = [
            new Color(255, 0, 0),
            new Color(0, 255, 0),
            new Color(0, 0, 255),
            new Color(255, 255, 0),
            new Color(255, 0, 255),
            new Color(255, 165, 0),
            new Color(255, 255, 255),
        ];
    }

    private function loadConfig(): void {
        $settings = $this->plugin->getConfig()->get('settings', []);
        $p = $settings['npc_particles'] ?? [];
        $this->particlesEnabled = (bool) ($p['enabled'] ?? true);
        $this->particleStyle = (string) ($p['style'] ?? 'rotating_ring');
        $this->particleColor = (int) ($p['color'] ?? 7);
        $this->particleSpeed = (int) ($p['speed'] ?? 5);
        $this->particleDensity = (int) ($p['density'] ?? 6);
    }

    public function saveConfig(): void {
        $config = $this->plugin->getConfig();
        $settings = $config->get('settings', []);
        $settings['npc_particles'] = [
            'enabled' => $this->particlesEnabled,
            'style' => $this->particleStyle,
            'color' => $this->particleColor,
            'speed' => $this->particleSpeed,
            'density' => $this->particleDensity,
        ];
        $config->set('settings', $settings);
        $config->save();
    }

    private function initDatabase(): void {
        $this->database = new SQLite3($this->plugin->getDataFolder() . 'npcs.db');
        $this->database->exec('
            CREATE TABLE IF NOT EXISTS npcs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                world TEXT NOT NULL,
                x REAL NOT NULL,
                y REAL NOT NULL,
                z REAL NOT NULL,
                yaw REAL NOT NULL,
                pitch REAL NOT NULL,
                scale REAL NOT NULL,
                skin_id TEXT,
                skin_data BLOB,
                cape_data BLOB,
                geometry_name TEXT,
                geometry_data TEXT
            )
        ');
        $result = $this->database->query('PRAGMA table_info(npcs)');
        $existingColumns = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $existingColumns[] = $row['name'];
        }
        foreach (['skin_id', 'skin_data', 'cape_data', 'geometry_name', 'geometry_data'] as $col) {
            if (!in_array($col, $existingColumns, true)) {
                $type = in_array($col, ['skin_data', 'cape_data'], true) ? 'BLOB' : 'TEXT';
                $this->database->exec("ALTER TABLE npcs ADD COLUMN {$col} {$type}");
            }
        }
    }

    private function loadNPCs(): void {
        $this->npcs = [];
        $result = $this->database->query('SELECT * FROM npcs');
        if ($result) {
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $this->npcs[(int) $row['id']] = $this->hydrateRow($row);
            }
        }
    }

    private function hydrateRow(array $row): array {
        return [
            'id' => (int) $row['id'],
            'world' => $row['world'],
            'x' => (float) $row['x'],
            'y' => (float) $row['y'],
            'z' => (float) $row['z'],
            'yaw' => (float) ($row['yaw'] ?? 0),
            'pitch' => (float) ($row['pitch'] ?? 0),
            'scale' => (float) ($row['scale'] ?? 1.5),
            'skin_id' => $row['skin_id'] ?? 'Standard_Custom',
            'skin_data' => $row['skin_data'] ?? str_repeat("\x00\x00\x00\x00", 64 * 64),
            'cape_data' => $row['cape_data'] ?? '',
            'geometry_name' => $row['geometry_name'] ?? 'geometry.humanoid.custom',
            'geometry_data' => $row['geometry_data'] ?? '{"geometry":{"default":"geometry.humanoid.custom"}}',
        ];
    }

    public function spawnAllNPCs(): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        foreach ($wm->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof PillarsNPC) {
                    $entity->flagForDespawn();
                }
            }
        }
        $this->knownNPCs = [];

        foreach ($this->npcs as $npcData) {
            $worldName = trim($npcData['world']);
            if (!$wm->isWorldLoaded($worldName)) {
                try {
                    $wm->loadWorld($worldName, true);
                } catch (\Exception) {
                    continue;
                }
            }
            $world = $wm->getWorldByName($worldName);
            if ($world === null) {
                continue;
            }
            $this->spawnNPCEntity($npcData, $world);
        }
    }

    public function spawnNPCsInWorld(string $worldName): void {
        $wm = $this->plugin->getServer()->getWorldManager();
        $world = $wm->getWorldByName($worldName);
        if ($world === null) {
            return;
        }
        foreach ($this->npcs as $npcData) {
            if ($npcData['world'] === $worldName) {
                $this->spawnNPCEntity($npcData, $world);
            }
        }
    }

    private function spawnNPCEntity(array $npcData, \pocketmine\world\World $world): void {
        $location = new Location(
            $npcData['x'], $npcData['y'], $npcData['z'],
            $world, $npcData['yaw'], $npcData['pitch']
        );
        $nbt = CompoundTag::create()
            ->setString('CustomName', '§4Pillars Minigame' . "\n" . '§7Click To Join')
            ->setByte('CustomNameVisible', 1)
            ->setByte('NoAI', 1)
            ->setByte('Silent', 1)
            ->setByte('Invulnerable', 1)
            ->setByte('NoGravity', 1)
            ->setByte('Immobile', 1)
            ->setFloat('Scale', $npcData['scale'])
            ->setTag('Pos', new ListTag([
                new FloatTag($npcData['x']),
                new FloatTag($npcData['y']),
                new FloatTag($npcData['z']),
            ]))
            ->setTag('Rotation', new ListTag([
                new FloatTag($npcData['yaw']),
                new FloatTag($npcData['pitch']),
            ]));
        try {
            $skin = new \pocketmine\entity\Skin(
                $npcData['skin_id'],
                $npcData['skin_data'],
                $npcData['cape_data'],
                $npcData['geometry_name'],
                $npcData['geometry_data']
            );
            $entity = new PillarsNPC($location, $skin, $nbt);
            $entity->setScale($npcData['scale']);
            $entity->spawnToAll();
            $this->knownNPCs[] = $entity;
        } catch (\Exception $e) {
            $this->plugin->getLogger()->error('Failed to spawn NPC: ' . $e->getMessage());
        }
    }

    public function createNPC(Player $player): void {
        $loc = $player->getLocation();
        $skin = $player->getSkin();
        $stmt = $this->database->prepare('
            INSERT INTO npcs (world, x, y, z, yaw, pitch, scale, skin_id, skin_data, cape_data, geometry_name, geometry_data)
            VALUES (:world, :x, :y, :z, :yaw, :pitch, :scale, :skin_id, :skin_data, :cape_data, :geometry_name, :geometry_data)
        ');
        $worldName = $loc->getWorld()->getFolderName();
        $stmt->bindValue(':world', $worldName, SQLITE3_TEXT);
        $stmt->bindValue(':x', $loc->getX(), SQLITE3_FLOAT);
        $stmt->bindValue(':y', $loc->getY(), SQLITE3_FLOAT);
        $stmt->bindValue(':z', $loc->getZ(), SQLITE3_FLOAT);
        $stmt->bindValue(':yaw', $loc->getYaw(), SQLITE3_FLOAT);
        $stmt->bindValue(':pitch', $loc->getPitch(), SQLITE3_FLOAT);
        $stmt->bindValue(':scale', 1.5, SQLITE3_FLOAT);
        $stmt->bindValue(':skin_id', $skin->getSkinId(), SQLITE3_TEXT);
        $stmt->bindValue(':skin_data', $skin->getSkinData(), SQLITE3_BLOB);
        $stmt->bindValue(':cape_data', $skin->getCapeData(), SQLITE3_BLOB);
        $stmt->bindValue(':geometry_name', $skin->getGeometryName(), SQLITE3_TEXT);
        $stmt->bindValue(':geometry_data', $skin->getGeometryData(), SQLITE3_TEXT);
        $stmt->execute();

        $id = $this->database->lastInsertRowID();
        $npcData = [
            'id' => $id,
            'world' => $worldName,
            'x' => $loc->getX(),
            'y' => $loc->getY(),
            'z' => $loc->getZ(),
            'yaw' => $loc->getYaw(),
            'pitch' => $loc->getPitch(),
            'scale' => 1.5,
            'skin_id' => $skin->getSkinId(),
            'skin_data' => $skin->getSkinData(),
            'cape_data' => $skin->getCapeData(),
            'geometry_name' => $skin->getGeometryName(),
            'geometry_data' => $skin->getGeometryData(),
        ];
        $this->npcs[$id] = $npcData;
        $this->spawnNPCEntity($npcData, $loc->getWorld());
        $player->sendMessage("§aNPC created at {$loc->getX()}, {$loc->getY()}, {$loc->getZ()} in {$worldName}");
    }

    public function removeNPC(int $id): bool {
        if (!isset($this->npcs[$id])) {
            return false;
        }
        $data = $this->npcs[$id];
        $world = $this->plugin->getServer()->getWorldManager()->getWorldByName($data['world']);
        if ($world !== null) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof PillarsNPC &&
                    abs($entity->getPosition()->x - $data['x']) < 0.1 &&
                    abs($entity->getPosition()->y - $data['y']) < 0.1 &&
                    abs($entity->getPosition()->z - $data['z']) < 0.1
                ) {
                    $entity->flagForDespawn();
                    $this->knownNPCs = array_values(array_filter($this->knownNPCs, fn($e) => $e !== $entity));
                    break;
                }
            }
        }
        $stmt = $this->database->prepare('DELETE FROM npcs WHERE id = :id');
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        $stmt->execute();
        unset($this->npcs[$id]);
        return true;
    }

    public function removeAllNPCs(): void {
        foreach ($this->knownNPCs as $entity) {
            if (!$entity->isClosed()) {
                $entity->flagForDespawn();
            }
        }
        foreach ($this->plugin->getServer()->getWorldManager()->getWorlds() as $world) {
            foreach ($world->getEntities() as $entity) {
                if ($entity instanceof PillarsNPC) {
                    $entity->flagForDespawn();
                }
            }
        }
        $this->database->exec('DELETE FROM npcs');
        $this->npcs = [];
        $this->knownNPCs = [];
        $this->lookAtPlayers = [];
    }

    public function getNPCs(): array {
        return $this->npcs;
    }

    public function getKnownNPCs(): array {
        return $this->knownNPCs;
    }

    public function cleanKnownNPCs(): void {
        $this->knownNPCs = array_values(array_filter(
            $this->knownNPCs,
            fn($e) => !$e->isClosed() && !$e->isFlaggedForDespawn()
        ));
    }

    public function updateNPCRotations(Entity $npc): void {
        $world = $npc->getWorld();
        foreach ($world->getPlayers() as $player) {
            $dist = $npc->getPosition()->distance($player->getPosition());
            if ($dist <= 15.0) {
                $dx = $player->getPosition()->x - $npc->getPosition()->x;
                $dz = $player->getPosition()->z - $npc->getPosition()->z;
                $yaw = rad2deg(atan2($dz, $dx)) - 90;
                $this->lookAtPlayers[$player->getId()][$npc->getId()] = $yaw;
            } else {
                unset($this->lookAtPlayers[$player->getId()][$npc->getId()]);
            }
        }
        foreach ($world->getPlayers() as $player) {
            if (isset($this->lookAtPlayers[$player->getId()][$npc->getId()])) {
                $npc->setRotation($this->lookAtPlayers[$player->getId()][$npc->getId()], 0);
            }
        }
    }

    private function startRotationTask(): void {
        $this->plugin->getScheduler()->scheduleRepeatingTask(new class($this) extends Task {
            private NPCManager $npcManager;
            private int $tick = 0;

            public function __construct(NPCManager $npcManager) {
                $this->npcManager = $npcManager;
            }

            public function onRun(): void {
                $this->tick++;
                $this->npcManager->cleanKnownNPCs();
                foreach ($this->npcManager->getKnownNPCs() as $entity) {
                    $this->npcManager->updateNPCRotations($entity);
                    if ($this->tick % 2 === 0 && $this->npcManager->isParticlesEnabled()) {
                        $this->spawnParticles($entity);
                    }
                }
            }

            private function spawnParticles(Entity $entity): void {
                $pos = $entity->getPosition();
                $world = $entity->getWorld();
                $style = $this->npcManager->getParticleStyle();
                $speed = $this->npcManager->getParticleSpeed() / 2;
                $density = $this->npcManager->getParticleDensity();

                match($style) {
                    'spiral' => $this->spawnSpiral($pos, $world, $density, $speed),
                    'double_helix' => $this->spawnDoubleHelix($pos, $world, $density, $speed),
                    'pulse' => $this->spawnPulse($pos, $world, $density, $speed),
                    'rain' => $this->spawnRain($pos, $world, $density, $speed),
                    'crown' => $this->spawnCrown($pos, $world, $density, $speed),
                    default => $this->spawnRotatingRing($pos, $world, $density, $speed),
                };
            }

            private function spawnRotatingRing(Vector3 $pos, $world, int $density, float $speed): void {
                for ($i = 0; $i < $density; $i++) {
                    $angle = ($i * (360 / $density)) + ($this->tick * $speed);
                    $r = deg2rad($angle);
                    $yOff = sin(deg2rad($this->tick * 6)) * 0.5;
                    $world->addParticle(
                        new Vector3($pos->x + 0.8 * cos($r), $pos->y + 1.2 + $yOff, $pos->z + 0.8 * sin($r)),
                        new DustParticle($this->npcManager->getParticleColor($i))
                    );
                }
            }

            private function spawnSpiral(Vector3 $pos, $world, int $density, float $speed): void {
                for ($i = 0; $i < $density; $i++) {
                    $prog = $i / $density;
                    $r = deg2rad($prog * 720 + $this->tick * $speed);
                    $world->addParticle(
                        new Vector3($pos->x + 0.8 * cos($r), $pos->y + 0.5 + $prog * 1.5, $pos->z + 0.8 * sin($r)),
                        new DustParticle($this->npcManager->getParticleColor($i))
                    );
                }
            }

            private function spawnDoubleHelix(Vector3 $pos, $world, int $density, float $speed): void {
                for ($i = 0; $i < $density; $i++) {
                    $prog = $i / $density;
                    $r1 = deg2rad($prog * 720 + $this->tick * $speed);
                    $r2 = $r1 + M_PI;
                    $y = $pos->y + 0.5 + $prog * 1.5;
                    $world->addParticle(new Vector3($pos->x + 0.7 * cos($r1), $y, $pos->z + 0.7 * sin($r1)), new DustParticle($this->npcManager->getParticleColor($i * 2)));
                    $world->addParticle(new Vector3($pos->x + 0.7 * cos($r2), $y, $pos->z + 0.7 * sin($r2)), new DustParticle($this->npcManager->getParticleColor($i * 2 + 1)));
                }
            }

            private function spawnPulse(Vector3 $pos, $world, int $density, float $speed): void {
                $size = 0.5 + sin(deg2rad($this->tick * $speed * 2)) * 0.3;
                for ($i = 0; $i < $density; $i++) {
                    $r = deg2rad($i * (360 / $density));
                    $world->addParticle(
                        new Vector3($pos->x + $size * cos($r), $pos->y + 1.2, $pos->z + $size * sin($r)),
                        new DustParticle($this->npcManager->getParticleColor($i))
                    );
                }
            }

            private function spawnRain(Vector3 $pos, $world, int $density, float $speed): void {
                for ($i = 0; $i < $density; $i++) {
                    $off = ($i / $density) * 360;
                    $yOff = ($this->tick % 40) / 20;
                    $world->addParticle(
                        new Vector3(
                            $pos->x + 1.2 * sin(deg2rad($this->tick * $speed + $off)),
                            $pos->y + 2.0 - $yOff,
                            $pos->z + 1.2 * cos(deg2rad($this->tick * $speed + $off))
                        ),
                        new DustParticle($this->npcManager->getParticleColor($i))
                    );
                }
            }

            private function spawnCrown(Vector3 $pos, $world, int $density, float $speed): void {
                for ($i = 0; $i < $density; $i++) {
                    $r = deg2rad($i * (360 / $density) + $this->tick * $speed);
                    if ($i % 2 === 0) {
                        $x = 0.9 * cos($r); $z = 0.9 * sin($r); $y = 1.5;
                    } else {
                        $x = 0.5 * cos($r); $z = 0.5 * sin($r); $y = 1.0;
                    }
                    $world->addParticle(
                        new Vector3($pos->x + $x, $pos->y + $y, $pos->z + $z),
                        new DustParticle($this->npcManager->getParticleColor($i))
                    );
                }
            }
        }, 1);
    }

    public function getParticleColor(int $index = 0): Color {
        if ($this->particleColor === 7) {
            $rainbow = [
                new Color(255, 0, 0), new Color(255, 127, 0), new Color(255, 255, 0),
                new Color(0, 255, 0), new Color(0, 0, 255), new Color(75, 0, 130), new Color(148, 0, 211),
            ];
            return $rainbow[$index % count($rainbow)];
        }
        return $this->colors[$this->particleColor] ?? $this->colors[0];
    }

    public function isParticlesEnabled(): bool { return $this->particlesEnabled; }
    public function setParticlesEnabled(bool $v): void { $this->particlesEnabled = $v; }
    public function getParticleStyle(): string { return $this->particleStyle; }
    public function setParticleStyle(string $v): void { $this->particleStyle = $v; }
    public function getParticleColorIndex(): int { return $this->particleColor; }
    public function setParticleColor(int $v): void { $this->particleColor = $v; }
    public function getParticleSpeed(): int { return $this->particleSpeed; }
    public function setParticleSpeed(float $v): void { $this->particleSpeed = (int) $v; }
    public function getParticleDensity(): int { return $this->particleDensity; }
    public function setParticleDensity(int $v): void { $this->particleDensity = $v; }

    public function cleanup(): void {
        $this->database->close();
    }
}
