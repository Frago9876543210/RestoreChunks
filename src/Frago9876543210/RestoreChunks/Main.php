<?php

namespace Frago9876543210\RestoreChunks;

use DirectoryIterator;
use pocketmine\block\tile\TileFactory;
use pocketmine\event\Listener;
use pocketmine\network\mcpe\protocol\{BlockEntityDataPacket, FullChunkDataPacket, StartGamePacket};
use pocketmine\network\mcpe\serializer\NetworkNbtSerializer;
use pocketmine\plugin\PluginBase;
use pocketmine\world\generator\Flat;
use Throwable;
use function file_get_contents;

class Main extends PluginBase implements Listener{

	public function onEnable() : void{
		$chunksFolder = "/home/alex/projects/mcpelauncher-mods/WorldDownloader/chunks";

		$packet = new StartGamePacket(file_get_contents($chunksFolder . "/.table"));
		try{
			$packet->decode(); //1.11 shit
		}catch(Throwable $e){
		}

		NetworkChunkDeserializer::loadRuntimeIdTable($packet->runtimeIdTable);

		try{
			foreach(glob("$chunksFolder/*") as $directory){
				if($this->isDirectoryEmpty($directory)){
					rmdir($directory);
					continue;
				}

				$manager = $this->getServer()->getWorldManager();
				if(!$manager->loadWorld($worldName = basename($directory))){
					$manager->generateWorld($worldName, 0, Flat::class, ["0x0;0x0;0x0"], false);
				}
				$world = $manager->getWorldByName($worldName);

				foreach(glob("$directory/*") as $filename){
					$pk = new FullChunkDataPacket(file_get_contents($filename));
					$pk->decode();

					NetworkChunkDeserializer::deserialize($world, $pk->chunkX, $pk->chunkZ, $pk->data, false);
					$this->getLogger()->info("[$worldName] [$pk->chunkX $pk->chunkZ] Chunk saved at " . ($pk->chunkX << 4) . " " . ($pk->chunkZ << 4));
				}

				$nbtSerializer = new NetworkNbtSerializer();

				foreach(glob("$directory/.*") as $filename){
					if(is_file($filename)){
						$pk = new BlockEntityDataPacket(file_get_contents($filename));
						$pk->decode();

						$tile = TileFactory::createFromData($world, $nbtSerializer->read($pk->namedtag)->getTag());
						$world->addTile($tile);

						$this->getLogger()->info("[$worldName] [$pk->x $pk->y $pk->z] Loaded additional tile data");
					}
				}

				$world->saveChunks();
			}
		}catch(Throwable $e){
			$this->getLogger()->logException($e);
		}
	}

	private function isDirectoryEmpty(string $path) : bool{
		foreach(new DirectoryIterator($path) as $file){
			if($file->isDot()){
				continue;
			}
			return false;
		}
		return true;
	}
}