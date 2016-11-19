<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
*/

/**
 * Implementation of MCPE-style chunks with subchunks with XZY ordering.
 */
declare(strict_types = 1);

namespace pocketmine\level\format\generic;

use pocketmine\block\Block;
use pocketmine\entity\Entity;
use pocketmine\level\format\Chunk;
use pocketmine\level\format\LevelProvider;
use pocketmine\level\Level;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\Player;
use pocketmine\tile\Tile;
use pocketmine\utils\BinaryStream;

class GenericChunk implements Chunk{

	/** @var LevelProvider */
	protected $provider;

	protected $x;
	protected $z;

	protected $hasChanged = false;

	protected $isInit = false;

	protected $lightPopulated = false;
	protected $terrainGenerated = false;
	protected $terrainPopulated = false;
	
	protected $height = 16;

	/** @var SubChunk[] */
	protected $subChunks = [];

	/** @var Tile[] */
	protected $tiles = [];
	protected $tileList = [];

	/** @var Entity[] */
	protected $entities = [];

	/** @var int[256] */
	protected $heightMap = [];

	/** @var int[256] */
	protected $biomeColors = [];

	protected $extraData = [];

	/** @var CompoundTag[] */
	protected $NBTtiles = [];

	/** @var CompoundTag[] */
	protected $NBTentities = [];

	/**
	 * @param LevelProvider $provider
	 * @param int           $chunkX
	 * @param int           $chunkZ
	 * @param SubChunk[]    $subChunks
	 * @param CompoundTag[] $entities
	 * @param CompoundTag[] $tiles
	 * @param int[256]      $biomeColors
	 * @param int[256]      $heightMap
	 */
	public function __construct($provider, int $chunkX, int $chunkZ, array $subChunks = [], array $entities = [], array $tiles = [], array $biomeColors = [], array $heightMap = []){
		$this->provider = $provider;
		$this->x = $chunkX;
		$this->z = $chunkZ;

		$this->height = $provider !== null ? ($provider->getWorldHeight() >> 4) : 16;

		foreach($subChunks as $subChunk){
			$y = $subChunk->getY();
			if($y < 0 or $y >= $this->height){
				throw new ChunkException("Invalid subchunk index $y!");
			}
			if($subChunk->isEmpty()){
				$this->subChunks[$y] = new EmptySubChunk($y);
			}else{
				$this->subChunks[$y] = $subChunk;
			}
		}

		for($i = 0; $i < $this->height; ++$i){
			if(!isset($this->subChunks[$i])){
				$this->subChunks[$i] = new EmptySubChunk($i);
			}
		}

		if(count($heightMap) === 256){
			$this->heightMap = $heightMap;
		}else{
			assert(count($heightMap) === 0, "Wrong HeightMap value count, expected 256, got " . count($heightMap));
			$this->heightMap = array_fill(0, 256, 0);
		}

		if(count($biomeColors) === 256){
			$this->biomeColors = $biomeColors;
		}else{
			assert(count($biomeColors) === 0, "Wrong HeightMap value count, expected 256, got " . count($biomeColors));
			$this->biomeColors = array_fill(0, 256, 0);
		}

		$this->NBTtiles = $tiles;
		$this->NBTentities = $entities;
	}

	public function getX() : int{
		return $this->x;
	}

	public function getZ() : int{
		return $this->z;
	}

	public function setX(int $x){
		$this->x = $x;
	}

	public function setZ(int $z){
		$this->z = $z;
	}

	public function getProvider(){
		return $this->provider;
	}

	public function setProvider(LevelProvider $provider){
		$this->provider = $provider;
	}

	public function getHeight() : int{
		return $this->height;
	}

	public function getFullBlock(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> 4)->getFullBlock($x, $y & 0x0f, $z);
	}

	public function setBlock(int $x, int $y, int $z, $blockId = null, $meta = null) : bool{
		return $this->getSubChunk($y >> 4, true)->setBlock($x, $y & 0x0f, $z, $blockId !== null ? ($blockId & 0xff) : null, $meta !== null ? ($meta & 0x0f) : null);
	}

	public function getBlockId(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> 4)->getBlockId($x, $y & 0x0f, $z);
	}

	public function setBlockId(int $x, int $y, int $z, int $id){
		$this->getSubChunk($y >> 4, true)->setBlockId($x, $y & 0x0f, $z, $id);
	}

	public function getBlockData(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> 4)->getBlockData($x, $y & 0x0f, $z);
	}

	public function setBlockData(int $x, int $y, int $z, int $data){
		$this->getSubChunk($y >> 4, true)->setBlockData($x, $y & 0x0f, $z, $data);
	}

	public function getBlockExtraData(int $x, int $y, int $z) : int{
		return $this->extraData[Level::chunkBlockHash($x, $y, $z)] ?? 0;
	}

	public function setBlockExtraData(int $x, int $y, int $z, int $data){
		if($data === 0){
			unset($this->extraData[Level::chunkBlockHash($x, $y, $z)]);
		}else{
			$this->extraData[Level::chunkBlockHash($x, $y, $z)] = $data;
		}

		$this->hasChanged = true;
	}

	public function getBlockSkyLight(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> 4)->getBlockSkyLight($x, $y & 0x0f, $z);
	}

	public function setBlockSkyLight(int $x, int $y, int $z, int $level){
		$this->getSubChunk($y >> 4, true)->setBlockSkyLight($x, $y & 0x0f, $z, $level);
	}

	public function getBlockLight(int $x, int $y, int $z) : int{
		return $this->getSubChunk($y >> 4)->getBlockLight($x, $y & 0x0f, $z);
	}

	public function setBlockLight(int $x, int $y, int $z, int $level){
		$this->getSubChunk($y >> 4, true)->setBlockLight($x, $y & 0x0f, $z, $level);
	}

	public function getHighestBlockAt(int $x, int $z, bool $useHeightMap = true) : int{
		if($useHeightMap){
			$height = $this->getHeightMap($x, $z);

			if($height !== 0 and $height !== 255){
				return $height;
			}
		}

		$index = $this->getHighestSubChunkIndex();
		if($index < 0){
			return 0;
		}

		$height = $index << 4;

		for($y = $index; $y >= 0; --$y){
			$height = $this->getSubChunk($y)->getHighestBlockAt($x, $z) | ($y << 4);
			if($height !== -1){
				break;
			}
		}

		$this->setHeightMap($x, $z, $height);
		return $height;
	}

	public function getHeightMap(int $x, int $z) : int{
		return $this->heightMap[($z << 4) | $x];
	}

	public function setHeightMap(int $x, int $z, int $value){
		$this->heightMap[($z << 4) | $x] = $value;
	}

	public function recalculateHeightMap(){
		for($z = 0; $z < 16; ++$z){
			for($x = 0; $x < 16; ++$x){
				$this->setHeightMap($x, $z, $this->getHighestBlockAt($x, $z, false));
			}
		}
	}

	public function populateSkyLight(){
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$top = $this->getHeightMap($x, $z);
				for($y = 127; $y > $top; --$y){
					$this->setBlockSkyLight($x, $y, $z, 15);
				}

				for($y = $top; $y >= 0; --$y){
					if(Block::$solid[$this->getBlockId($x, $y, $z)]){
						break;
					}

					$this->setBlockSkyLight($x, $y, $z, 15);
				}

				$this->setHeightMap($x, $z, $this->getHighestBlockAt($x, $z, false));
			}
		}
	}

	public function getBiomeId(int $x, int $z) : int{
		return ($this->biomeColors[($z << 4) | $x] & 0xFF000000) >> 24;
	}

	public function setBiomeId(int $x, int $z, int $biomeId){
		$this->hasChanged = true;
		$this->biomeColors[($z << 4) | $x] = ($this->biomeColors[($z << 4) | $x] & 0xFFFFFF) | ($biomeId << 24);
	}

	public function getBiomeColor(int $x, int $z) : int{
		$color = $this->biomeColors[($z << 4) | $x] & 0xFFFFFF;

		return [$color >> 16, ($color >> 8) & 0xFF, $color & 0xFF];
	}

	public function setBiomeColor(int $x, int $z, int $R, int $G, int $B){
		$this->hasChanged = true;
		$this->biomeColors[($z << 4) | $x] = ($this->biomeColors[($z << 4) | $x] & 0xFF000000) | (($R & 0xFF) << 16) | (($G & 0xFF) << 8) | ($B & 0xFF);
	}

	public function getBlockIdColumn(int $x, int $z) : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockIdColumn($x, $z);
		}
		return $result;
	}

	public function getBlockDataColumn(int $x, int $z) : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockDataColumn($x, $z);
		}
		return $result;
	}

	public function getBlockSkyLightColumn(int $x, int $z) : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getSkyLightColumn($x, $z);
		}
		return $result;
	}

	public function getBlockLightColumn(int $x, int $z) : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockLightColumn($x, $z);
		}
		return $result;
	}

	public function isLightPopulated() : bool{
		return $this->lightPopulated;
	}

	public function setLightPopulated(bool $value = true){
		$this->lightPopulated = $value;
	}

	public function isPopulated() : bool{
		return $this->terrainPopulated;
	}

	public function setPopulated(bool $value = true){
		$this->terrainPopulated = $value;
	}

	public function isGenerated() : bool{
		return $this->terrainGenerated;
	}

	public function setGenerated(bool $value = true){
		$this->terrainGenerated = $value;
	}

	public function addEntity(Entity $entity){
		$this->entities[$entity->getId()] = $entity;
		if(!($entity instanceof Player) and $this->isInit){
			$this->hasChanged = true;
		}
	}

	public function removeEntity(Entity $entity){
		unset($this->entities[$entity->getId()]);
		if(!($entity instanceof Player) and $this->isInit){
			$this->hasChanged = true;
		}
	}

	public function addTile(Tile $tile){
		$this->tiles[$tile->getId()] = $tile;
		if(isset($this->tileList[$index = (($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]) and $this->tileList[$index] !== $tile){
			$this->tileList[$index]->close();
		}
		$this->tileList[$index] = $tile;
		if($this->isInit){
			$this->hasChanged = true;
		}
	}

	public function removeTile(Tile $tile){
		unset($this->tiles[$tile->getId()]);
		unset($this->tileList[(($tile->x & 0x0f) << 12) | (($tile->z & 0x0f) << 8) | ($tile->y & 0xff)]);
		if($this->isInit){
			$this->hasChanged = true;
		}
	}

	public function getEntities() : array{
		return $this->entities;
	}

	public function getTiles() : array{
		return $this->tiles;
	}

	public function getTile(int $x, int $y, int $z){
		$index = ($x << 12) | ($z << 8) | $y;
		return $this->tileList[$index] ?? null;
	}

	public function isLoaded() : bool{
		return $this->getProvider() === null ? false : $this->getProvider()->isChunkLoaded($this->getX(), $this->getZ());
	}

	public function load(bool $generate = true) : bool{
		return $this->getProvider() === null ? false : $this->getProvider()->getChunk($this->getX(), $this->getZ(), true) instanceof GenericChunk;
	}

	public function unload(bool $save = true, bool $safe = true) : bool{
		$level = $this->getProvider();
		if($level === null){
			return true;
		}
		if($save === true and $this->hasChanged){
			$level->saveChunk($this->getX(), $this->getZ());
		}
		if($safe === true){
			foreach($this->getEntities() as $entity){
				if($entity instanceof Player){
					return false;
				}
			}
		}

		foreach($this->getEntities() as $entity){
			if($entity instanceof Player){
				continue;
			}
			$entity->close();
		}
		foreach($this->getTiles() as $tile){
			$tile->close();
		}
		$this->provider = null;
		return true;
	}

	public function initChunk(){
		if($this->getProvider() instanceof LevelProvider and !$this->isInit){
			$changed = false;
			if($this->NBTentities !== null){
				$this->getProvider()->getLevel()->timings->syncChunkLoadEntitiesTimer->startTiming();
				foreach($this->NBTentities as $nbt){
					if($nbt instanceof CompoundTag){
						if(!isset($nbt->id)){
							$changed = true;
							continue;
						}

						if(($nbt["Pos"][0] >> 4) !== $this->x or ($nbt["Pos"][2] >> 4) !== $this->z){
							$changed = true;
							continue; //Fixes entities allocated in wrong chunks.
						}

						if(($entity = Entity::createEntity($nbt["id"], $this, $nbt)) instanceof Entity){
							$entity->spawnToAll();
						}else{
							$changed = true;
							continue;
						}
					}
				}
				$this->getProvider()->getLevel()->timings->syncChunkLoadEntitiesTimer->stopTiming();

				$this->getProvider()->getLevel()->timings->syncChunkLoadTileEntitiesTimer->startTiming();
				foreach($this->NBTtiles as $nbt){
					if($nbt instanceof CompoundTag){
						if(!isset($nbt->id)){
							$changed = true;
							continue;
						}

						if(($nbt["x"] >> 4) !== $this->x or ($nbt["z"] >> 4) !== $this->z){
							$changed = true;
							continue; //Fixes tiles allocated in wrong chunks.
						}

						if(Tile::createTile($nbt["id"], $this, $nbt) === null){
							$changed = true;
							continue;
						}
					}
				}

				$this->getProvider()->getLevel()->timings->syncChunkLoadTileEntitiesTimer->stopTiming();

				$this->NBTentities = null;
				$this->NBTtiles = null;
			}

			$this->hasChanged = $changed;

			$this->isInit = true;
		}
	}

	public function getBiomeIdArray() : string{
		$ids = str_repeat("\x00", 256);
		foreach($this->biomeColors as $i => $d){
			$ids{$i} = chr(($d & 0xFF000000) >> 24);
		}
		return $ids;
	}

	public function getBiomeColorArray() : array{
		return $this->biomeColors;
	}

	public function getHeightMapArray() : array{
		return $this->heightMap;
	}

	public function getBlockIdArray() : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockIdArray();
		}
		return $result;
	}

	public function getBlockDataArray() : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockDataArray();
		}
		return $result;
	}

	public function getBlockExtraDataArray() : array{
		return $this->extraData;
	}

	public function getBlockSkyLightArray() : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getSkyLightArray();
		}
		return $result;
	}

	public function getBlockLightArray() : string{
		$result = "";
		foreach($this->subChunks as $subChunk){
			$result .= $subChunk->getBlockLightArray();
		}
		return $result;
	}

	public function hasChanged() : bool{
		return $this->hasChanged;
	}

	public function setChanged(bool $value = true){
		$this->hasChanged = $value;
	}

	public function getSubChunk(int $fY, bool $generateNew = false) : SubChunk{
		if($fY < 0 or $fY >= self::MAX_SUBCHUNKS){
			return new EmptySubChunk($fY);
		}elseif($generateNew and $this->subChunks[$fY] instanceof EmptySubChunk){
			$this->subChunks[$fY] = new SubChunk($fY);
		}
		return $this->subChunks[$fY];
	}

	public function setSubChunk(int $fY, SubChunk $subChunk = null, bool $allowEmpty = false) : bool{
		if($fY < 0 or $fY >= self::MAX_SUBCHUNKS){
			return false;
		}
		if($subChunk === null or ($subChunk->isEmpty() and !$allowEmpty)){
			$this->subChunks[$fY] = new EmptySubChunk();
		}else{
			$this->subChunks[$fY] = $subChunk;
		}
		$this->hasChanged = true;
		return true;
	}

	public function getSubChunks() : array{
		return $this->subChunks;
	}

	public function getHighestSubChunkIndex() : int{
		for($y = count($this->subChunks) - 1; $y >= 0; --$y){
			if($this->subChunks[$y] === null or $this->subChunks[$y]->isEmpty()){
				continue;
			}
			break;
		}

		return $y;
	}

	public function getSubChunkSendCount() : int{
		return $this->getHighestSubChunkIndex() + 1;
	}

	public function getNonEmptySubChunkCount() : int{
		$result = 0;
		foreach($this->subChunks as $subChunk){
			if($subChunk->isEmpty()){
				continue;
			}
			++$result;
		}
		return $result;
	}

	public function clearEmptySubChunks(){
		foreach($this->subChunks as $y => $subChunk){
			if($subChunk->isEmpty()){
				if($y < 0 or $y > self::MAX_SUBCHUNKS){
					assert(false, "Invalid subchunk index");
					unset($this->subChunks[$y]);
				}elseif($subChunk instanceof EmptySubChunk){
					continue;
				}else{
					$this->subChunks[$y] = new EmptySubChunk($y);
				}
				$this->hasChanged = true;
			}
		}
	}

	public function networkSerialize() : string{
		$result = "";
		$subChunkCount = $this->getSubChunkSendCount();
		$result .= chr($subChunkCount);
		for($y = 0; $y < $subChunkCount; ++$y){
			$subChunk = $this->subChunks[$y];
			$result .= "\x00" //unknown byte!
				. $subChunk->getBlockIdArray()
				. $subChunk->getBlockDataArray()
				. $subChunk->getSkyLightArray()
				. $subChunk->getBlockLightArray();
		}

		//TODO: heightmaps, tile data
		return $result;
	}

	public static function fastSerialize(Chunk $chunk) : string{
		$stream = new BinaryStream();
		$stream->putInt($chunk->x);
		$stream->putInt($chunk->z);
		$count = 0;
		$subChunks = "";
		foreach($chunk->subChunks as $subChunk){
			if($subChunk->isEmpty()){
				continue;
			}
			++$count;
			$subChunks .= chr($subChunk->getY())
				. $subChunk->getBlockIdArray()
				. $subChunk->getBlockDataArray()
				. $subChunk->getSkyLightArray()
				. $subChunk->getBlockLightArray();
		}
		$stream->putByte($count);
		$stream->put($subChunks);
		$stream->put(pack("C*", ...$chunk->getHeightMapArray()) .
			pack("N*", ...$chunk->getBiomeColorArray()) .
			chr(($chunk->lightPopulated ? 1 << 2 : 0) | ($chunk->terrainPopulated ? 1 << 1 : 0) | ($chunk->terrainGenerated ? 1 : 0)));
		//TODO: tiles and entities
		return $stream->getBuffer();
	}

	public static function fastDeserialize(string $data, LevelProvider $provider = null){
		$stream = new BinaryStream();
		$stream->setBuffer($data);
		$data = null;
		$x = $stream->getInt();
		$z = $stream->getInt();
		$subChunks = [];
		$count = $stream->getByte();
		for($y = 0; $y < $count; ++$y){
			$subChunks[] = new SubChunk(
				$stream->getByte(), //y
				$stream->get(4096), //blockIds
				$stream->get(2048), //blockData
				$stream->get(2048), //skyLight
				$stream->get(2048)  //blockLight
			);
		}
		$heightMap = array_values(unpack("C*", $stream->get(256)));
		$biomeColors = array_values(unpack("N*", $stream->get(1024)));

		$chunk = new GenericChunk($provider, $x, $z, $subChunks, $heightMap, $biomeColors);
		$flags = $stream->getByte();
		$chunk->lightPopulated = (bool) ($flags & 4);
		$chunk->terrainPopulated = (bool) ($flags & 2);
		$chunk->terrainGenerated = (bool) ($flags & 1);
		return $chunk;
	}

	public static function getEmptyChunk(int $x, int $z, LevelProvider $provider = null) : Chunk{
		return new GenericChunk($provider, $x, $z);
	}

	/**
	 * Re-orders a byte array (YZX -> XZY and vice versa)
	 *
	 * @param string $array length 4096
	 *
	 * @return string length 4096
	 */
	public static final function reorderByteArray(string $array) : string{
		$result = str_repeat("\x00", 4096);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$xz = (($x << 8) | ($z << 4));
				$zx = (($z << 4) | $x);
				for($y = 0; $y < 16; ++$y){
					$result{$xz | $y} = $array{($y << 8) | $zx};
				}
			}
		}
		return $result;
	}
	
	/**
	 * Re-orders a nibble array (YZX -> XZY and vice versa)
	 *
	 * @param string $array length 2048
	 *
	 * @return string length 2048
	 */
	public static final function reorderNibbleArray(string $array) : string{
		$result = str_repeat("\x00", 2048);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$xz = (($x << 7) | ($z << 3));
				$zx = (($z << 3) | ($x >> 1));
				for($y = 0; $y < 16; ++$y){
					$inputIndex = (($y << 7) | $zx);
					$outputIndex = ($xz | ($y >> 1));
					$current = ord($result{$outputIndex});
					$byte = ord($array{$inputIndex});

					if(($y & 1) === 0){
						if(($x & 1) === 0){
							$current |= ($byte & 0x0f);
						}else{
							$current |= (($byte >> 4) & 0x0f);
						}
					}else{
						if(($x & 1) === 0){
							$current |= (($byte << 4) & 0xf0);
						}else{
							$current |= ($byte & 0xf0);
						}
					}
					$result{$outputIndex} = chr($current);
				}
			}
		}
		return $result;
	}

}