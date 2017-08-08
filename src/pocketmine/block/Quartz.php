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

use pocketmine\item\Item;
use pocketmine\item\TieredTool;
use pocketmine\item\Tool;
use pocketmine\math\Vector3;
use pocketmine\Player;

class Quartz extends Solid{

	const QUARTZ_NORMAL = 0;
	const QUARTZ_CHISELED = 1;
	const QUARTZ_PILLAR = 2;
	const QUARTZ_PILLAR2 = 3;

	protected $id = Block::QUARTZ_BLOCK;

	public function __construct(int $meta = 0){
		$this->meta = $meta;
	}

	public function getHardness() : float{
		return 0.8;
	}

	public function getName() : string{
		static $names = [
			self::QUARTZ_NORMAL => "Quartz Block",
			self::QUARTZ_CHISELED => "Chiseled Quartz Block",
			self::QUARTZ_PILLAR => "Quartz Pillar",
			self::QUARTZ_PILLAR2 => "Quartz Pillar",
		];
		return $names[$this->meta & 0x03];
	}

	public function getToolType() : int{
		return Tool::TYPE_PICKAXE;
	}

	public function getRequiredHarvestLevel() : int{
		return TieredTool::TIER_WOODEN;
	}

	public function getVariantBitmask() : int{
		return 0x03;
	}

	public function place(Item $item, Block $block, Block $target, int $face, float $fx, float $fy, float $fz, Player $player = null) : bool{
		$this->meta &= 0x03;

		if($this->meta !== 0){
			$faces = [
				Vector3::SIDE_DOWN => 0,
				Vector3::SIDE_WEST => 0x04,
				Vector3::SIDE_NORTH => 0x08
			];

			$this->meta = ($this->meta & 0x03) | $faces[$face & ~0x01];
		}

		return $block->getLevel()->setBlock($block, $this, true, true);
	}
}