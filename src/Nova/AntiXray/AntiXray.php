<?php

namespace Nova\AntiXray;

use pocketmine\Player;
use pocketmine\block\Block;
use pocketmine\event\Handler;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockUpdateEvent;
use pocketmine\event\level\LevelUnloadEvent;
use pocketmine\event\player\PlayerChunkRequestEvent;
//use pocketmine.level\GlobalBlockPalette;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\math\Vector3;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
/*import com.google.common.collect.Lists;
import com.google.common.collect.Maps;

import java.util.Collections;
import java.util.List;
import java.util.Map;*/ // Hmm... what is this ._.

class AntiXray extends PluginBase implements Listener {

    public int $height = 4;
    public int $maxY;
    public boolean $obfuscatorMode = true;
    public boolean $memoryCache = false;
    private array $worlds;

    /*final boolean[] filter = new boolean[256];
    final boolean[] ore = new boolean[256];
    final int[] dimension = new int[]{Block.STONE, Block.NETHERRACK, Block.AIR, Block.AIR};*/
	public boolean $filter;
	public boolean $ore;
	public array $dimension = array(Block::STONE, BLOCK::NETHERRACK, Block::AIR, Block::AIR); 
    //private final Map<Level, WorldHandler> handlers = Maps.newHashMap();

    //@Override
    public function onEnable() :void {
        try {
            new MetricsLite($this, 5123);
        } catch (Throwable $ignore) {

        }

        $this->saveDefaultConfig();
        $config = $this->getConfig();

        $node = "scan-chunk-height-limit";
        if ($config->exists($node)) {
            try {
                $this->height = $config->get($node, $this->height);
            } catch (Exception $e) {
                $this->logLoadException($node, $e);
            }
        } else { //compatible
            $node = "scan-height-limit";
            try {
                $this->height = $config->get($node, 64) >> 4;
            } catch (Exception $e) {
                $this->logLoadException("scan-chunk-height-limit", $e);
            }
        }
        $this->height = max([min(this.height, 15), 1]);
        $this->maxY = $this->height << 4;

        $node = "memory-cache";
        if ($config->exists($node)) {
            try {
                $this->memoryCache = $config->get($node, $this->memoryCache);
            } catch (Exception $e) {
                $this->logLoadException($node, $e);
            }
        } else { //compatible
            $node = "cache-chunks";
            try {
                $this->memoryCache = $config->get($node, $this->memoryCache);
            } catch (Exception $e) {
                $this->logLoadException("memory-cache", $e);
            }
        }

        $node = "obfuscator-mode";
        try {
            $this->obfuscatorMode = $config->get($node, $this->obfuscatorMode);
        } catch (Exception $e) {
            $this->logLoadException($node, $e);
        }
        $node = "overworld-fake-block";
        try {
            $this->dimension = $config->get($node, $this->dimension) & 0xff;
            //GlobalBlockPalette.getOrCreateRuntimeId(this.dimension[0], 0);
        } catch (Exception $e) {
            $this->dimension = Block::STONE;
            $this->logLoadException($node, $e);
        }
        $node = "nether-fake-block";
        try {
            $this->dimension = $config->get($node, $this->dimension) & 0xff;
            //GlobalBlockPalette.getOrCreateRuntimeId(this.dimension[1], 0);
        } catch (Exception $e) {
            $this->dimension = Block::NETHERRACK;
            $this->logLoadException($node, $e);
        }
        $node = "protect-worlds";
        try {
            $this->worlds = $config->get($node);
        } catch (Exception $e) {
            $this->logLoadException($node, $e);
        }
        $node = "ores";
        $ores = array();
        try {
            $ores = $config->get($node);
        } catch (Exception $e) {
            $this->logLoadException($node, $e);
        }

        if ($this->worlds != null && !empty($this->worlds) && ($this->obfuscatorMode || $ores != null && !empty($ores))) {
            $node = "filters";
            $filters = array();
            try {
                $filters = $config->get($node);
            } catch (Exception $e) {
                $filters = array(null);
                $this->logLoadException($node, $e);
            }

            foreach($filters as $id) {
                if ($id > -1 && $id < 256) {
                    $this->filter[$id] = true;
                }
            }
            if (!$this->obfuscatorMode) {
                for ($ores as $id) {
                    if ($id > -1 && $id < 256) {
                        $this->ore[$id] = true;
                    }
                }
            }

            (new WorldHandler)->init();

            $this->getServer()->getPluginManager()->registerEvents($this, $this);
        }
    }

    //@EventHandler(priority = EventPriority.LOWEST)
    public function onPlayerChunkRequest(PlayerChunkRequestEvent $event) :void {
        $player = $event->getPlayer();
        $level = $player->getLevel();
        if (!is_null($this->getLevelByName($level->getName()))) {
            $event->setCancelled();
            $handler = $this->handlers->get($level);
            if ($handler == null) {
                $handler = new WorldHandler($this, $level);
                $this->handlers->set($level, $handler);
            }
            $handler->requestChunk($event->getChunkX(), $event->getChunkZ(), $player);
        }
    }

    //@EventHandler
    //TODO: Use BlockBreakEvent instead of BlockUpdateEvent
    public function onBlockUpdate(BlockUpdateEvent $event) :void {
        $position = $event->getBlock()->getPosition();
        $level = $position->getLevel();
        if (!is_null($this->getLevelByName($level->getName()))) {
            $packets = array();
            foreach ([$position->asVector3()->add(1),
                  $position->asVector3()->add(-1),
                  $position->asVector3()->add(0, 1),
                  $position->asVector3()->add(0, -1),
                  $position->asVector3()->add(0, 0, 1),
                  $position->asVector3()->add(0, 0, -1)
            ] as $vector) {
                $y = $vector->getFloorY();
                if ($y > 255 || $y < 0) {
                    continue;
                }
                $x = $vector->getFloorX();
                $z = $vector->getFloorZ();
                $packet = new UpdateBlockPacket();
                try {
                    $packet->blockRuntimeId = $level->getFullBlock($x, $y, $z)->getRuntimeId();
                } catch (Exception $tryAgain) {
                    try {
                        //$packet.blockRuntimeId = GlobalBlockPalette.getOrCreateRuntimeId(level.getBlockIdAt(x, y, z), 0);
						$packet->blockRuntimeId = $level->getBlockIdAt($x, $y, $z)->getRuntimeId();
                    } catch (Exception $ex) {
                        continue;
                    }
                }
                $packet->x = $x;
                $packet->y = $y;
                $packet->z = $z;
                $packet->flags = UpdateBlockPacket::FLAG_ALL_PRIORITY;
                $packets->add($packet);
            }

            if (sizeof($packets) > 0) {
                $this->getServer()->batchPackets($level->getChunkPlayers($position->getChunkX(), $position->getChunkZ()), $packets);
            }
        }
    }

    //@EventHandler
    public function onLevelUnload(LevelUnloadEvent $event) :void {
        $this->handlers->remove($event->getLevel());
    }

    private function logLoadException(String $node, Exception $ex) :void {
        $this->getLogger()->alert("Failed to get the configuration '" + $node + "'. Use the default value.", $ex);
    }
}
