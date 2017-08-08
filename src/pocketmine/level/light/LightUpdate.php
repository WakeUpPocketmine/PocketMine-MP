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

declare(strict_types=1);

namespace pocketmine\level\light;

use pocketmine\block\Block;
use pocketmine\level\ChunkManager;
use pocketmine\level\format\SubChunk;
use pocketmine\level\Level;

//TODO: make light updates asynchronous
abstract class LightUpdate{

	/** @var ChunkManager */
	protected $level;

	/** @var \SplQueue */
	protected $spreadQueue;
	/** @var bool[] */
	protected $spreadVisited = [];

	/** @var \SplQueue */
	protected $removalQueue;
	/** @var bool[] */
	protected $removalVisited = [];

	/** @var SubChunk */
	protected $currentSubChunk;

	/** @var int */
	protected $currentSubX;
	/** @var int */
	protected $currentSubY;
	/** @var int */
	protected $currentSubZ;

	public function __construct(ChunkManager $level){
		$this->level = $level;
		$this->removalQueue = new \SplQueue();
		$this->spreadQueue = new \SplQueue();
	}

	abstract protected function getLight(int $x, int $y, int $z) : int;

	abstract protected function setLight(int $x, int $y, int $z, int $level) : bool;

	public function setAndUpdateLight(int $x, int $y, int $z, int $newLevel){
		if(!$this->level->isInWorld($x, $y, $z)){
			throw new \InvalidArgumentException("Coordinates x=$x, y=$y, z=$z are out of range");
		}

		if(isset($this->spreadVisited[$index = Level::blockHash($x, $y, $z)]) or isset($this->removalVisited[$index])){
			throw new \InvalidArgumentException("Already have a visit ready for this block");
		}

		if($this->checkCurrentSubChunk($x, $y, $z)){
			$oldLevel = $this->getLight($x, $y, $z);

			if($oldLevel !== $newLevel){
				$this->setLight($x, $y, $z, $newLevel);
				if($oldLevel < $newLevel){ //light increased
					$this->spreadVisited[$index] = true;
					$this->spreadQueue->enqueue([$x, $y, $z]);
				}else{ //light removed
					$this->removalVisited[$index] = true;
					$this->removalQueue->enqueue([$x, $y, $z, $oldLevel]);
				}
			}
		}
	}

	public function execute(){
		while(!$this->removalQueue->isEmpty()){
			list($x, $y, $z, $oldAdjacentLight) = $this->removalQueue->dequeue();

			$this->computeRemoveLight($x + 1, $y, $z, $oldAdjacentLight);
			$this->computeRemoveLight($x - 1, $y, $z, $oldAdjacentLight);
			$this->computeRemoveLight($x, $y + 1, $z, $oldAdjacentLight);
			$this->computeRemoveLight($x, $y - 1, $z, $oldAdjacentLight);
			$this->computeRemoveLight($x, $y, $z + 1, $oldAdjacentLight);
			$this->computeRemoveLight($x, $y, $z - 1, $oldAdjacentLight);
		}

		while(!$this->spreadQueue->isEmpty()){
			list($x, $y, $z) = $this->spreadQueue->dequeue();

			$this->checkCurrentSubChunk($x, $y, $z);
			$newAdjacentLight = $this->getLight($x, $y, $z);
			if($newAdjacentLight <= 0){
				continue;
			}

			$this->computeSpreadLight($x + 1, $y, $z, $newAdjacentLight);
			$this->computeSpreadLight($x - 1, $y, $z, $newAdjacentLight);
			$this->computeSpreadLight($x, $y + 1, $z, $newAdjacentLight);
			$this->computeSpreadLight($x, $y - 1, $z, $newAdjacentLight);
			$this->computeSpreadLight($x, $y, $z + 1, $newAdjacentLight);
			$this->computeSpreadLight($x, $y, $z - 1, $newAdjacentLight);
		}
	}

	protected function computeRemoveLight(int $x, int $y, int $z, int $oldAdjacentLevel){
		if($this->checkCurrentSubChunk($x, $y, $z)){
			$current = $this->getLight($x, $y, $z);

			if($current !== 0 and $current < $oldAdjacentLevel){
				if($this->setLight($x, $y, $z, 0)){
					if(!isset($this->removalVisited[$index = Level::blockHash($x, $y, $z)])){
						$this->removalVisited[$index] = true;
						if($current > 1){
							$this->removalQueue->enqueue([$x, $y, $z, $current]);
						}
					}
				}
			}elseif($current >= $oldAdjacentLevel){
				if(!isset($this->spreadVisited[$index = Level::blockHash($x, $y, $z)])){
					$this->spreadVisited[$index] = true;
					$this->spreadQueue->enqueue([$x, $y, $z]);
				}
			}
		}
	}

	private function checkCurrentSubChunk(int $x, int $y, int $z) : bool{
		if($this->currentSubChunk === null or ($x >> 4) !== $this->currentSubX or ($y >> 4) !== $this->currentSubY or ($z >> 4) !== $this->currentSubZ){
			$this->currentSubX = $x >> 4;
			$this->currentSubY = $y >> 4;
			$this->currentSubZ = $z >> 4;
			$chunk = $this->level->getChunk($this->currentSubX, $this->currentSubZ);
			if($chunk !== null){
				$this->currentSubChunk = $chunk->getSubChunk($this->currentSubY, true);
				return $this->currentSubChunk !== null; //will be null if out of bounds
			}

			$this->currentSubChunk = null;
			return false;
		}

		return true;
	}

	protected function computeSpreadLight(int $x, int $y, int $z, int $newAdjacentLevel){
		if($this->checkCurrentSubChunk($x, $y, $z)){
			$current = $this->getLight($x, $y, $z);
			$potentialLight = $newAdjacentLevel - Block::$lightFilter[$this->currentSubChunk->getBlockId($x & 0x0f, $y & 0x0f, $z & 0x0f)];

			if($current < $potentialLight and $this->setLight($x, $y, $z, $potentialLight)){
				if(!isset($this->spreadVisited[$index = Level::blockHash($x, $y, $z)]) and $potentialLight > 1){
					$this->spreadVisited[$index] = true;
					$this->spreadQueue->enqueue([$x, $y, $z]);
				}
			}
		}
	}
}