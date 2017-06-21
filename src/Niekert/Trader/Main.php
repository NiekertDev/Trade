<?php
/**
 * Created by PhpStorm.
 * User: Roelof Dell
 * Date: 20-6-2017
 * Time: 19:39
 */

namespace Niekert\Trader;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\entity\EntityInventoryChangeEvent;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Tile;
use pocketmine\utils\TextFormat as C;
use onebone\economyapi\EconomyAPI;
use pocketmine\tile\Chest;

class Main extends PluginBase
{
    public $trades = [];
    public function onLoad()
    {
        $this->getLogger()->info(C::AQUA."Trader loading");
    }

    public function onEnable()
    {
        $this->saveDefaultConfig();
        $this->getLogger()->info(C::AQUA."Trader enabled");
    }

    public function trade(Player $trader,Player $player, Item $item, bool $price){
        $trademessage = str_replace("{Trader}", $trader->getName(), $this->getConfig()->get("TradeMessage"));
        $this->trades[$trader->getName()][$player->getName()]["Item"] = $item;
        $this->trades[$trader->getName()][$player->getName()]["Price"] = $price;
        $this->trades[$trader->getName()][$player->getName()]["Name"] = $item->getName();
        $player->sendMessage(C::AQUA."[Trader]".C::GREEN.$trademessage);
    }

    public function tradeserver(Player $trader, Item $item, bool $price){
        $trademessage = str_replace("{Trader}", $trader->getName(), $this->getConfig()->get("TradeMessageGlobal"));
        $this->trades["global"][$trader->getName()]["Item"] = $item;
        $this->trades["global"][$trader->getName()]["Price"] = $price;
        $this->getServer()->broadcastMessage(C::AQUA."[Trader]".C::GREEN.$trademessage);
    }

    public function tradetoplayer(Player $trader, Player $receiver){
        if (isset($this->trades[$trader->getName()][$receiver->getName()])){
            $trade = $this->trades[$trader->getName()][$receiver->getName()];
            $item = $trade["Item"];
            $price = $trade["Price"];
            $orginalname = $trade["Name"];
            $economy = EconomyAPI::getInstance();
            $economy->reduceMoney($receiver, $price);
            $economy->addMoney($trader, $price);
            if ($receiver->getInventory()->canAddItem($item)){
                $item->setCostumName($orginalname);
                $receiver->getInventory()->addItem($item);
                $trader->getInventory()->remove($item);
                unset($this->trades[$trader->getName()][$receiver->getName()]);
                unset($this->trades[$trader->getName()][$receiver->getName()]);
            } else {
                $receiver->sendMessage(C::AQUA."[Trader]".C::RED."You're inventory is full!");
            }
        } else {
            $receiver->sendMessage(C::AQUA."[Trader]".C::RED."That player doesn't have an trade with you");
        }
    }

    public function tradetofromserver(Player $trader, Player $receiver){
        $trade = $this->trades["global"][$trader->getName()];
        $item = $trade["Item"];
        $price = $trade["Price"];
        $orginalname = $trade["Name"];
        $economy = EconomyAPI::getInstance();
        if ($receiver->getInventory()->canAddItem($item)){
            $economy->reduceMoney($receiver, $price);
            $economy->addMoney($trader, $price);
            $originalitem = $item->setCostumName($orginalname);
            $receiver->getInventory()->addItem($originalitem);
            $trader->getInventory()->remove($item);
            unset($this->trades["global"][$trader->getName()]);
        } else {
            $receiver->sendMessage(C::AQUA."[Trader]".C::RED."You're inventory is full!");
        }
    }

    public function sendChest(Player $player){
        $nbt = new CompoundTag('', [
            new StringTag('id', Tile::CHEST),
            new IntTag('Trades', 1),
            new IntTag('x', floor($player->x)),
            new IntTag('y', floor($player->y)),
            new IntTag('z', floor($player->z))
        ]);
        /** @var Chest $tile */
        $tile = Tile::createTile('Chest', $player->getLevel(), $nbt);
        $block = Block::get(Block::CHEST);
        $block->x = floor($tile->x);
        $block->y = floor($tile->y);
        $block->z = floor($tile->z);
        $block->level = $tile->getLevel();
        $block->level->sendBlocks([$player], [$block]);
        $player->addWindow($tile->getInventory());
        $economy = EconomyAPI::getInstance();
        $slot = +0;
        foreach ($this->trades["global"] as $trader => $trade){
            $item = $trade["Item"];
            $price = $economy->getMonetaryUnit().$trade["Price"];
            $item->setCustomName(C::RED."From: ".$trader."\n".C::AQUA."Price: ".$price);
            $tile->getInventory()->setItem($slot, $item);
            $slot++;
        }
        $player->addWindow($tile->getInventory());
    }

    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        switch ($command){
            case "trade":
                if (!isset($args[0]) OR $args[0] === "help"){
                    $sender->sendMessage(C::AQUA."---------Trader by Niekert--------");
                    $sender->sendMessage(C::RED."- /trade item Player Price - Trade item in hand to player");
                    $sender->sendMessage(C::RED."- /trade item global Price - Trade item in hand to whole server");
                    $sender->sendMessage(C::RED."- /trade accept Player - Accept trade from player");
                    $sender->sendMessage(C::RED."- /trades - Show all global trades");
                    $sender->sendMessage(C::RED."- /trade help - Show this help");
                }
                elseif($sender instanceof Player){
                    switch ($args[0]){
                        case "item":
                            if (!isset($args[1]) OR !isset($args[2])){
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."Usage: /trade item Player/global Price");
                                 return true;
                            }
                            if ($this->getServer()->getPlayer($args[1]) instanceof Player) {
                                $player = $this->getServer()->getPlayer($args[1]);
                                $this->trade($sender, $player, $player->getInventory()->getItemInHand(), $args[2]);
                                $player->getInventory()->getItemInHand()->setCustomName(C::AQUA."Traded to $args[1]!");
                            }
                            elseif ($args[1] === "global"){
                                $this->tradeserver($sender, $sender->getInventory()->getItemInHand(), $args[2]);
                                $sender->getInventory()->getItemInHand()->setCustomName(C::AQUA."Globally traded!");
                            }
                            else{
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."That player doesn't exist");
                            }
                            break;
                        case "accept":
                            $economy = EconomyAPI::getInstance();
                            $player = $this->getServer()->getPlayer($args[1]);
                            if ($player instanceof Player) {
                                $this->tradetoplayer($player, $sender);
                            } else {
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."That player doesn't exist");
                            }
                            break;
                    }
                }
                else{
                    $this->getLogger()->info(C::RED."You can only use this command in-game!");
                }
                break;
            case "trades":
                if($sender instanceof Player){
                    $this->sendChest($sender);
                }
                else{
                    $this->getLogger()->info(C::RED."You can only use this command in-game!");
                }
                break;
        }
        return true;
    }

    public function onCheck(EntityInventoryChangeEvent $event)
    {
        $player = $event->getEntity();
        $newItem = $event->getNewItem();
        foreach ($this->trades["global"] as $trader => $trade){
            if($newItem == $trade["Item"]){
                $trader = $this->getServer()->getPlayer($trader);
                $this->tradetofromserver($trader, $player);
            }
        }
    }
}