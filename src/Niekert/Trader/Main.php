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
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Tile;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as C;
use onebone\economyapi\EconomyAPI;
use pocketmine\tile\Chest;

/**
 * Class Main
 * @package Niekert\Trader
 */
class Main extends PluginBase implements Listener
{
    public $trades = [];
    public function onLoad()
    {
        $this->getLogger()->info(C::AQUA."Trader loading");
    }

    public function onEnable()
    {
        $config = new Config($this->getDataFolder()."Trades.yml", Config::YAML, array());
        $trades = $config->get("Trades");
        if(isset($trades)){
            $this->trades = $trades;
        }
        $this->saveDefaultConfig();
        $this->reloadConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info(C::AQUA."Trader enabled");
    }

    public function onDisable()
    {
        $this->getLogger()->info(C::AQUA."Trader disabled");
        $config = new Config($this->getDataFolder()."Trades.yml", Config::YAML, array());
        $config->set("Trades", $this->trades);
        $config->save();
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, $label, array $args)
    {
        switch ($command->getName()){
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
                            elseif ($sender->getInventory()->getItemInHand() === Item::get(Item::AIR, 0, 0)) {
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."Please put an item in your hand!");
                                break;
                            }
                            $player = $this->getServer()->getPlayerExact($args[1]);
                            if ($player !== null) {
                                $this->TradePlayer($sender, $player, $player->getInventory()->getItemInHand(), $args[2]);
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
                            if (!isset($args[1])){
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."Usage: /trade accept Player");
                                return true;
                            }
                            $player = $this->getServer()->getPlayerExact($args[1]);
                            if ($player !== null) {
                                $this->AcceptTradePlayer($player, $sender);
                            } else {
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."That player doesn't exist");
                            }
                            break;
                        case "cancel":
                            if (!isset($args[1])){
                                $sender->sendMessage(C::AQUA."[Trader] ".C::RED."Usage: /trade cancel Player");
                                break;
                            }
                            elseif($args[1] === "global"){
                                $this->TradeCancelServer($sender);
                                break;
                            }
                            $player = $this->getServer()->getPlayerExact($args[1]);
                            if ($player !== null) {
                                $this->TradeCancelPlayer($player, $sender);
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

    /**
     * @param EntityInventoryChangeEvent $event
     */
    public function onCheck(EntityInventoryChangeEvent $event)
    {
        $player = $event->getEntity();
        $newItem = $event->getNewItem();
        if($this->contains($newItem->getCustomName(), "Price")){
            $tradername = $this->get_string_between($newItem->getCustomName(), "From: ", "\n");
            $trader = $this->getServer()->getPlayerExact($tradername);
            if($trader === null){
                return;
            }
            $response = $this->AcceptTradeServer($trader, $player);
            if(!$response){
                $event->setCancelled();
            }
        }
    }

    /**
     * @param PlayerInteractEvent $event
     */
    public function onUse(PlayerInteractEvent $event){
        $item = $event->getItem();
        $player = $event->getPlayer();
        if($this->contains($item->getCustomName(), "Traded")){
            $player->sendMessage(C::RED."You can't use this item, because its traded!");
            $event->setCancelled();
        }
    }

    private function get_string_between($string, $start, $end){
        $string = ' ' . $string;
        $ini = strpos($string, $start);
        if ($ini == 0) return '';
        $ini += strlen($start);
        $len = strpos($string, $end, $ini) - $ini;
        return substr($string, $ini, $len);
    }

    private function contains($string, $find){
        $check = strpos($string, $find);
        if ($check === false) {
            return false;
        }else{
            return true;
        }
    }

    /**
     * @param Player $trader
     * @param Player $player
     * @param Item $item
     * @param bool $price
     */
    public function TradePlayer(Player $trader, Player $player, Item $item, bool $price){
        $playername = $player->getName();
        $trademessage = str_replace("{Trader}", $trader->getName(), $this->getConfig()->get("TradeMessage"));
        $this->trades[$trader->getName()][$playername]["Id"] = $item->getId();
        $this->trades["global"][$trader->getName()]["Meta"] = $item->getDamage();
        $this->trades[$trader->getName()][$playername]["Count"] = $item->getCount();
        $this->trades[$trader->getName()][$playername]["Tag"] = $item->getCompoundTag();
        $this->trades[$trader->getName()][$playername]["Price"] = $price;
        $player->sendMessage(C::AQUA."[Trader] ".C::GREEN.$trademessage);
        $item->setCustomName(C::AQUA."Traded to $playername!");
        $trader->getInventory()->setItemInHand($item);
    }

    /**
     * @param Player $trader
     * @param Item $item
     * @param bool $price
     */
    public function TradeServer(Player $trader, Item $item, bool $price){
        $trademessage = str_replace("{Trader}", $trader->getName(), $this->getConfig()->get("TradeMessageGlobal"));
        $this->trades["global"][$trader->getName()]["Id"] = $item->getId();
        $this->trades["global"][$trader->getName()]["Meta"] = $item->getDamage();
        $this->trades["global"][$trader->getName()]["Count"] = $item->getCount();
        $this->trades["global"][$trader->getName()]["Tag"] = $item->getCompoundTag();
        $this->trades["global"][$trader->getName()]["Price"] = $price;
        $this->getServer()->broadcastMessage(C::AQUA."[Trader] ".C::GREEN.$trademessage);
        $item->setCustomName(C::AQUA."Globally traded!");
        $trader->getInventory()->setItemInHand($item);
    }

    /**
     * @param Player $trader
     * @param Player $receiver
     */
    public function AcceptTradePlayer(Player $trader, Player $receiver){
        if (isset($this->trades[$trader->getName()][$receiver->getName()])){
            $tradername = $trader->getName();
            $trade = $this->trades[$trader->getName()][$receiver->getName()];
            $item = Item::get($trade["Id"], $trade["Meta"], $trade["Count"], $trade["Tag"]);
            $price = $trade["Price"];
            if (!$trader->getInventory()->contains($item)){
                $receiver->sendMessage(C::AQUA."[Trader] ".C::RED."The trader doesn't have the item. Please contact $tradername");
                return;
            } else {
                $economy = EconomyAPI::getInstance();
                if ($receiver->getInventory()->canAddItem($item)){
                    $economy->reduceMoney($receiver, $price);
                    $economy->addMoney($trader, $price);
                    $receiver->getInventory()->addItem($item);
                    $trader->getInventory()->remove($item);
                    unset($this->trades[$trader->getName()][$receiver->getName()]);
                } else {
                    $receiver->sendMessage(C::AQUA."[Trader] ".C::RED."You're inventory is full!");
                }
            }
        } else {
            $receiver->sendMessage(C::AQUA."[Trader] ".C::RED."That player doesn't have an trade with you");
        }
    }

    /**
     * @param Player $trader
     * @param Player $receiver
     * @return bool
     */
    public function AcceptTradeServer(Player $trader, Player $receiver){
        $tradername = $trader->getName();
        $trade = $this->trades["global"][$trader->getName()];
        $item = Item::get($trade["Id"], $trade["Meta"], $trade["Count"], $trade["Tag"]);
        $price = $trade["Price"];
        if (!$trader->getInventory()->contains($item)){
            $receiver->sendTip(C::AQUA."[Trader] ".C::RED."The trader doesn't have the item. Please contact $tradername");
            return false;
        } else {
            $economy = EconomyAPI::getInstance();
            if ($receiver->getInventory()->canAddItem($item)){
                $economy->reduceMoney($receiver, $price);
                $economy->addMoney($trader, $price);
                $receiver->getInventory()->addItem($item);
                $trader->getInventory()->remove($item);
                unset($this->trades["global"][$trader->getName()]);
                return true;
            } else {
                $receiver->sendTip(C::AQUA."[Trader] ".C::RED."You're inventory is full!");
                return false;
            }
        }
    }

    /**
     * @param Player $trader
     * @param Player $receiver
     */
    public function TradeCancelPlayer(Player $trader, Player $receiver)
    {
        if (isset($this->trades[$trader->getName()][$receiver->getName()])) {
            unset($this->trades[$trader->getName()][$receiver->getName()]);
        } else {
            $receiver->sendMessage(C::AQUA ."[Trader] ".C::RED."You don't have an trade with that player");
        }
    }

    /**
     * @param Player $trader
     */
    public function TradeCancelServer(Player $trader){
        if (isset($this->trades["global"][$trader->getName()])){
            unset($this->trades["global"][$trader->getName()]);
        } else {
            $trader->sendMessage(C::AQUA."[Trader] ".C::RED."You don't have an trade with that player");
        }
    }

    /**
     * @param Player $player
     */
    public function sendChest(Player $player){
        $nbt = new CompoundTag('', [
            new StringTag('id', Tile::CHEST),
            new IntTag('Trades', 1),
            new IntTag('x', intval(floor($player->x))),
            new IntTag('y', intval(floor($player->y))),
            new IntTag('z', intval(floor($player->z)))
        ]);
        /** @var Chest $tile */
        $tile = Tile::createTile('Chest', $player->getLevel(), $nbt);
        $block = Block::get(Block::CHEST);
        $block->x = intval(floor($tile->x));
        $block->y = intval(floor($tile->y));
        $block->z = intval(floor($tile->z));
        $block->level = $tile->getLevel();
        $block->level->sendBlocks([$player], [$block]);
        $economy = EconomyAPI::getInstance();
        $globaltrades = $this->trades["global"];
        if(is_array($globaltrades)){
            foreach ($globaltrades as $trader => $trade):
                $item = Item::get($trade["Id"], $trade["Meta"], $trade["Count"], $trade["Tag"]);
                $price = $economy->getMonetaryUnit().$trade["Price"];
                $item->setCustomName(C::RED."From: ".$trader."\n".C::AQUA."Price: ".$price);
                $tile->getInventory()->addItem($item);
            endforeach;
            $player->addWindow($tile->getInventory());
        }
        else{
            $player->sendMessage(C::AQUA ."[Trader] ".C::RED."There aren't any global trades");
        }
    }
}