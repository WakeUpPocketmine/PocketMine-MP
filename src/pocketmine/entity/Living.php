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

namespace pocketmine\entity;

use pocketmine\block\Block;
use pocketmine\event\entity\EntityDamageByChildEntityEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Timings;
use pocketmine\item\Armor;
use pocketmine\item\Consumable;
use pocketmine\item\Item as ItemItem;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\network\mcpe\protocol\EntityEventPacket;
use pocketmine\Player;
use pocketmine\utils\BlockIterator;

abstract class Living extends Entity implements Damageable{

	protected $gravity = 0.08;
	protected $drag = 0.02;

	protected $attackTime = 0;

	protected $invisible = false;

	protected $jumpVelocity = 0.42;

	protected function initEntity(){
		parent::initEntity();

		if(isset($this->namedtag->HealF)){
			$this->namedtag->Health = new FloatTag("Health", (float) $this->namedtag["HealF"]);
			unset($this->namedtag->HealF);
		}elseif(isset($this->namedtag->Health)){
			if(!($this->namedtag->Health instanceof FloatTag)){
				$this->namedtag->Health = new FloatTag("Health", (float) $this->namedtag->Health->getValue());
			}
		}else{
			$this->namedtag->Health = new FloatTag("Health", (float) $this->getMaxHealth());
		}

		$this->setHealth((float) $this->namedtag["Health"]);

		if(isset($this->namedtag->AbsorptionAmount) and $this->namedtag->AbsorptionAmount instanceof FloatTag){
			$this->setAbsorption((float) $this->namedtag->AbsorptionAmount->getValue());
		}
	}

	protected function addAttributes(){
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::HEALTH));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::FOLLOW_RANGE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::KNOCKBACK_RESISTANCE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::MOVEMENT_SPEED));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::ATTACK_DAMAGE));
		$this->attributeMap->addAttribute(Attribute::getAttribute(Attribute::ABSORPTION));
	}

	public function setHealth(float $amount){
		$wasAlive = $this->isAlive();
		parent::setHealth($amount);
		$this->attributeMap->getAttribute(Attribute::HEALTH)->setValue(ceil($this->getHealth()), true);
		if($this->isAlive() and !$wasAlive){
			$pk = new EntityEventPacket();
			$pk->entityRuntimeId = $this->getId();
			$pk->event = EntityEventPacket::RESPAWN;
			$this->server->broadcastPacket($this->hasSpawned, $pk);
		}
	}

	public function getMaxHealth(){
		return $this->attributeMap->getAttribute(Attribute::HEALTH)->getMaxValue();
	}

	public function setMaxHealth($amount){
		$this->attributeMap->getAttribute(Attribute::HEALTH)->setMaxValue($amount);
	}

	public function getAbsorption() : float{
		return $this->attributeMap->getAttribute(Attribute::ABSORPTION)->getValue();
	}

	public function setAbsorption(float $absorption){
		$this->attributeMap->getAttribute(Attribute::ABSORPTION)->setValue($absorption);
	}

	public function saveNBT(){
		parent::saveNBT();

		$this->namedtag->Health = new FloatTag("Health", $this->getHealth());
		$this->namedtag->AbsorptionAmount = new FloatTag("AbsorptionAmount", $this->getAbsorption());
	}

	abstract public function getName();

	public function canBeNamed() : bool{
		return true;
	}

	public function hasLineOfSight(Entity $entity){
		//TODO: head height
		return true;
		//return $this->getLevel()->rayTraceBlocks(Vector3::createVector($this->x, $this->y + $this->height, $this->z), Vector3::createVector($entity->x, $entity->y + $entity->height, $entity->z)) === null;
	}

	public function heal(EntityRegainHealthEvent $source){
		parent::heal($source);
		if($source->isCancelled()){
			return;
		}

		$this->attackTime = 0;
	}

	public function consume(Consumable $consumable){
		foreach($consumable->getAdditionalEffects() as $effect){
			$this->addEffect($effect);
		}

		$consumable->onConsume($this);

		$pk = new EntityEventPacket();
		$pk->entityRuntimeId = $this->id;
		$pk->event = EntityEventPacket::USE_ITEM;
		$this->server->broadcastPacket($this->hasSpawned, $pk);

		return $consumable->getResidue();
	}

	/**
	 * Returns the initial upwards velocity of a jumping entity in blocks/tick, including additional velocity due to effects.
	 * @return float
	 */
	public function getJumpVelocity() : float{
		return $this->jumpVelocity + ($this->hasEffect(Effect::JUMP) ? ($this->getEffect(Effect::JUMP)->getEffectLevel() / 10) : 0);
	}

	/**
	 * Called when the entity jumps from the ground. This method adds upwards velocity to the entity.
	 */
	public function jump(){
		if($this->onGround){
			$this->motionY = $this->getJumpVelocity(); //Y motion should already be 0 if we're jumping from the ground.
		}
	}

	/**
	 * Returns the amount of armor points the mob has. Some mobs have armor points by default regardless of whether they
	 * are wearing armor or not, such as zombies.
	 *
	 * @return int
	 */
	public function getArmorPoints() : int{
		return 0;
	}

	/**
	 * Applies durability reduction to armor, if any armor is worn.
	 * @param float $damage
	 */
	public function damageArmor(float $damage){
		//TODO
	}

	/**
	 * Applies damage modifiers to an EntityDamageEvent, such as armor, armor enchantments, absorption...
	 * @param EntityDamageEvent $source
	 */
	public function applyDamageModifiers(EntityDamageEvent $source){
		//Armor damage reduction and enchantments currently use the system from before PC 1.9

		if($source->canBeReducedByArmor()){
			$points = $this->getArmorPoints();
			$armorReduction = $source->getFinalDamage() * $points * 0.04;
			$source->setDamage(-$armorReduction, EntityDamageEvent::MODIFIER_ARMOR);
		}

		if($this instanceof Player){
			//TODO: clean up armor inventory

			$totalEpf = 0;
			foreach($this->getInventory()->getArmorContents() as $item){
				if($item instanceof Armor){
					$totalEpf += $item->getEnchantmentProtectionFactor($source);
				}
			}

			$totalEpf = min(ceil(min($totalEpf, 25) * (mt_rand(50, 100) / 100)), 20);
			$enchantmentReduction = $source->getFinalDamage() * $totalEpf * 0.04;
			$source->setDamage(-$enchantmentReduction, EntityDamageEvent::MODIFIER_ARMOR_ENCHANTMENTS);
		}

		$source->setDamage(-min($this->getAbsorption(), $source->getFinalDamage()), EntityDamageEvent::MODIFIER_ABSORPTION);

		$cause = $source->getCause();
		if($cause !== EntityDamageEvent::CAUSE_VOID and $cause !== EntityDamageEvent::CAUSE_SUICIDE){
			if($this->hasEffect(Effect::DAMAGE_RESISTANCE)){
				$multiplier = 0.2 * ($this->getEffect(Effect::DAMAGE_RESISTANCE)->getEffectLevel());
				$source->setDamage(-($source->getFinalDamage() * $multiplier), EntityDamageEvent::MODIFIER_RESISTANCE);
			}
		}
	}

	public function attack(EntityDamageEvent $source){
		if($this->attackTime > 0 or $this->noDamageTicks > 0){
			$lastCause = $this->getLastDamageCause();
			if($lastCause !== null and $lastCause->getDamage() >= $source->getDamage()){
				$source->setCancelled();
			}
		}

		$this->applyDamageModifiers($source);

		parent::attack($source);

		if($source->isCancelled()){
			return;
		}

		if($source instanceof EntityDamageByEntityEvent){
			$e = $source->getDamager();
			if($source instanceof EntityDamageByChildEntityEvent){
				$e = $source->getChild();
			}

			if($e !== null){
				if($e->isOnFire() > 0){
					$this->setOnFire(2 * $this->server->getDifficulty());
				}

				$deltaX = $this->x - $e->x;
				$deltaZ = $this->z - $e->z;
				$this->knockBack($e, $source->getDamage(), $deltaX, $deltaZ, $source->getKnockBack());
			}
		}

		$this->setAbsorption($this->getAbsorption() + $source->getDamage(EntityDamageEvent::MODIFIER_ABSORPTION));

		//All damage sources cause durability reduction to armor in PE, regardless of whether the armor absorbed some
		//damage or not.
		$this->damageArmor($source->getDamage(EntityDamageEvent::MODIFIER_BASE)); //TODO: check difficulty damage increase/reduce

		$pk = new EntityEventPacket();
		$pk->entityRuntimeId = $this->getId();
		$pk->event = $this->getHealth() <= 0 ? EntityEventPacket::DEATH_ANIMATION : EntityEventPacket::HURT_ANIMATION; //Ouch!
		$this->server->broadcastPacket($this->hasSpawned, $pk);

		$this->attackTime = 10; //0.5 seconds cooldown
	}

	public function knockBack(Entity $attacker, $damage, $x, $z, $base = 0.4){
		$f = sqrt($x * $x + $z * $z);
		if($f <= 0){
			return;
		}

		$f = 1 / $f;

		$motion = new Vector3($this->motionX, $this->motionY, $this->motionZ);

		$motion->x /= 2;
		$motion->y /= 2;
		$motion->z /= 2;
		$motion->x += $x * $f * $base;
		$motion->y += $base;
		$motion->z += $z * $f * $base;

		if($motion->y > $base){
			$motion->y = $base;
		}

		$this->setMotion($motion);
	}

	public function kill(){
		if(!$this->isAlive()){
			return;
		}
		parent::kill();
		$this->callDeathEvent();
	}

	protected function callDeathEvent(){
		$this->server->getPluginManager()->callEvent($ev = new EntityDeathEvent($this, $this->getDrops()));
		foreach($ev->getDrops() as $item){
			$this->getLevel()->dropItem($this, $item);
		}
	}

	public function entityBaseTick($tickDiff = 1){
		Timings::$timerLivingEntityBaseTick->startTiming();

		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isAlive()){
			if($this->isInsideOfSolid()){
				$hasUpdate = true;
				$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_SUFFOCATION, 1);
				$this->attack($ev);
			}

			if(!$this->canBreathe()){
				if($this->isBreathing()){
					$this->setBreathing(false);
				}
				$this->doAirSupplyTick($tickDiff);
			}elseif(!$this->isBreathing()){
				$this->setBreathing(true);
				$this->setAirSupplyTicks($this->getMaxAirSupplyTicks());
			}
		}

		if($this->attackTime > 0){
			$this->attackTime -= $tickDiff;
		}

		Timings::$timerLivingEntityBaseTick->stopTiming();

		return $hasUpdate;
	}

	/**
	 * Ticks the entity's air supply when it cannot breathe.
	 * @param int $tickDiff
	 */
	protected function doAirSupplyTick(int $tickDiff){
		$ticks = $this->getAirSupplyTicks() - $tickDiff;

		if($ticks <= -20){
			$this->setAirSupplyTicks(0);
			$this->onAirExpired();
		}else{
			$this->setAirSupplyTicks($ticks);
		}
	}

	/**
	 * Returns whether the entity can currently breathe.
	 * @return bool
	 */
	public function canBreathe() : bool{
		return $this->hasEffect(Effect::WATER_BREATHING) or !$this->isInsideOfWater();
	}

	/**
	 * Returns whether the entity is currently breathing or not. If this is false, the entity's air supply will be used.
	 * @return bool
	 */
	public function isBreathing() : bool{
		return $this->getDataFlag(self::DATA_FLAGS, self::DATA_FLAG_BREATHING);
	}

	/**
	 * Sets whether the entity is currently breathing. If false, it will cause the entity's air supply to be used.
	 * For players, this also shows the oxygen bar.
	 *
	 * @param bool $value
	 */
	public function setBreathing(bool $value = true){
		$this->setDataFlag(self::DATA_FLAGS, self::DATA_FLAG_BREATHING, $value);
	}

	/**
	 * Returns the number of ticks remaining in the entity's air supply. Note that the entity may survive longer than
	 * this amount of time without damage due to enchantments such as Respiration.
	 *
	 * @return int
	 */
	public function getAirSupplyTicks() : int{
		return $this->getDataProperty(self::DATA_AIR);
	}

	/**
	 * Sets the number of air ticks left in the entity's air supply.
	 * @param int $ticks
	 */
	public function setAirSupplyTicks(int $ticks){
		$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, $ticks);
	}

	/**
	 * Returns the maximum amount of air ticks the entity's air supply can contain.
	 * @return int
	 */
	public function getMaxAirSupplyTicks() : int{
		return $this->getDataProperty(self::DATA_MAX_AIR);
	}

	/**
	 * Sets the maximum amount of air ticks the air supply can hold.
	 * @param int $ticks
	 */
	public function setMaxAirSupplyTicks(int $ticks){
		$this->setDataProperty(self::DATA_AIR, self::DATA_TYPE_SHORT, $ticks);
	}

	/**
	 * Called when the entity's air supply ticks reaches -20 or lower. The entity will usually take damage at this point
	 * and then the supply is reset to 0, so this method will be called roughly every second.
	 */
	public function onAirExpired(){
		$ev = new EntityDamageEvent($this, EntityDamageEvent::CAUSE_DROWNING, 2);
		$this->attack($ev);
	}

	/**
	 * @return ItemItem[]
	 */
	public function getDrops() : array{
		return [];
	}

	/**
	 * @param int   $maxDistance
	 * @param int   $maxLength
	 * @param array $transparent
	 *
	 * @return Block[]
	 */
	public function getLineOfSight($maxDistance, $maxLength = 0, array $transparent = []){
		if($maxDistance > 120){
			$maxDistance = 120;
		}

		if(count($transparent) === 0){
			$transparent = null;
		}

		$blocks = [];
		$nextIndex = 0;

		$itr = new BlockIterator($this->level, $this->getPosition(), $this->getDirectionVector(), $this->getEyeHeight(), $maxDistance);

		while($itr->valid()){
			$itr->next();
			$block = $itr->current();
			$blocks[$nextIndex++] = $block;

			if($maxLength !== 0 and count($blocks) > $maxLength){
				array_shift($blocks);
				--$nextIndex;
			}

			$id = $block->getId();

			if($transparent === null){
				if($id !== 0){
					break;
				}
			}else{
				if(!isset($transparent[$id])){
					break;
				}
			}
		}

		return $blocks;
	}

	/**
	 * @param int   $maxDistance
	 * @param array $transparent
	 *
	 * @return Block|null
	 */
	public function getTargetBlock($maxDistance, array $transparent = []){
		try{
			$block = $this->getLineOfSight($maxDistance, 1, $transparent)[0];
			if($block instanceof Block){
				return $block;
			}
		}catch(\ArrayOutOfBoundsException $e){
		}

		return null;
	}
}
