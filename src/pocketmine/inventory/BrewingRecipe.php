<?php

declare(strict_types=1);

namespace pocketmine\inventory;

use pocketmine\item\Item;
use pocketmine\network\mcpe\protocol\types\PotionTypeRecipe;

class BrewingRecipe extends Recipe {

	public $input;
	public $output;
	public $ingredient;

	public function __construct(int $inputPotionType, int $outputPotionType, int $ingredient) {
		$this->input = $inputPotionType;
		$this->output = $outputPotionType;
		$this->ingredient = $ingredient;
	}

	public function getInput() {
		return $this->input;
	}

	public function getOutput() {
		return $this->output;
	}

	public function getIngredient() {
		return $this->ingredient;
	}

	public function toPotionTypeRecipe() {
		return new PotionTypeRecipe(Item::POTION, $this->getInput(), $this->getIngredient(), 0, Item::POTION, $this->getOutput());
	}

	public function registerToCraftingManager(CraftingManager $manager) {
		$manager->registerBrewingPotion($this);
	}

}
