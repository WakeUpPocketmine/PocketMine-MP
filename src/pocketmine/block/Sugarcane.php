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

namespace pocketmine\block;

use pocketmine\event\block\BlockGrowEvent;
use pocketmine\item\Item;
use pocketmine\level\Level;
use pocketmine\math\Vector3;
use pocketmine\Player;
use pocketmine\Server;

class Sugarcane extends Flowable{

	protected $id = Block::SUGARCANE_BLOCK;

	public function __construct(int $meta = 0){
		$this->meta = $meta;
	}

	public function getName() : string{
		return "Sugarcane";
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function getDrops(Item $item) : array{
		return [
			Item::get(Item::SUGARCANE, 0, 1)
		];
	}

	public function onActivate(Item $item, Player $player = null) : bool{
		if($item->getId() === Item::DYE and $item->getDamage() === 0x0F){ //Bonemeal
			if($this->getSide(Vector3::SIDE_DOWN)->getId() !== Block::SUGARCANE_BLOCK){
				for($y = 1; $y < 3; ++$y){
					$b = $this->getLevel()->getBlock(new Vector3($this->x, $this->y + $y, $this->z));
					if($b->getId() === Block::AIR){
						Server::getInstance()->getPluginManager()->callEvent($ev = new BlockGrowEvent($b, Block::get(Block::SUGARCANE_BLOCK)));
						if(!$ev->isCancelled()){
							$this->getLevel()->setBlock($b, $ev->getNewState(), true);
						}
						break;
					}
				}
				$this->meta = 0;
				$this->getLevel()->setBlock($this, $this, true);
			}
			if(($player->gamemode & 0x01) === 0){
				$item->count--;
			}

			return true;
		}

		return false;
	}

	public function onUpdate(int $type){
		if($type === Level::BLOCK_UPDATE_NORMAL){
			$down = $this->getSide(Vector3::SIDE_DOWN);
			if($down->isTransparent() === true and $down->getId() !== Block::SUGARCANE_BLOCK){
				$this->getLevel()->useBreakOn($this);

				return Level::BLOCK_UPDATE_NORMAL;
			}
		}elseif($type === Level::BLOCK_UPDATE_RANDOM){
			if($this->getSide(Vector3::SIDE_DOWN)->getId() !== Block::SUGARCANE_BLOCK){
				if($this->meta === 0x0F){
					for($y = 1; $y < 3; ++$y){
						$b = $this->getLevel()->getBlock(new Vector3($this->x, $this->y + $y, $this->z));
						if($b->getId() === Block::AIR){
							$this->getLevel()->setBlock($b, Block::get(Block::SUGARCANE_BLOCK), true);
							break;
						}
					}
					$this->meta = 0;
					$this->getLevel()->setBlock($this, $this, true);
				}else{
					++$this->meta;
					$this->getLevel()->setBlock($this, $this, true);
				}

				return Level::BLOCK_UPDATE_RANDOM;
			}
		}

		return false;
	}

	public function place(Item $item, Block $block, Block $target, int $face, float $fx, float $fy, float $fz, Player $player = null) : bool{
		$down = $this->getSide(Vector3::SIDE_DOWN);
		if($down->getId() === Block::SUGARCANE_BLOCK){
			$this->getLevel()->setBlock($block, Block::get(Block::SUGARCANE_BLOCK), true);

			return true;
		}elseif($down->getId() === Block::GRASS or $down->getId() === Block::DIRT or $down->getId() === Block::SAND){
			$block0 = $down->getSide(Vector3::SIDE_NORTH);
			$block1 = $down->getSide(Vector3::SIDE_SOUTH);
			$block2 = $down->getSide(Vector3::SIDE_WEST);
			$block3 = $down->getSide(Vector3::SIDE_EAST);
			if(($block0 instanceof FlowingWater) or ($block1 instanceof FlowingWater) or ($block2 instanceof FlowingWater) or ($block3 instanceof FlowingWater)){
				$this->getLevel()->setBlock($block, Block::get(Block::SUGARCANE_BLOCK), true);

				return true;
			}
		}

		return false;
	}
}