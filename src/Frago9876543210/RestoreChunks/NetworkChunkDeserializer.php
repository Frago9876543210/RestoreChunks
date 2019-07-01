<?php

/** @noinspection PhpInternalEntityUsedInspection */
/** @noinspection PhpUnhandledExceptionInspection */

declare(strict_types=1);

namespace Frago9876543210\RestoreChunks;

use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\nbt\NbtDataException;
use pocketmine\network\mcpe\protocol\types\RuntimeBlockMapping;
use pocketmine\network\mcpe\serializer\{NetworkBinaryStream, NetworkNbtSerializer};
use pocketmine\world\format\{Chunk, ChunkException, io\SubChunkConverter, PalettedBlockArray, SubChunk};
use pocketmine\world\World;
use ReflectionClass;
use function file_get_contents;
use function json_decode;
use const pocketmine\RESOURCE_PATH;

final class NetworkChunkDeserializer{

	private function __construct(){
		//NOOP
	}

	public static function loadRuntimeIdTable(array $bedrockKnownStates) : void{
		RuntimeBlockMapping::init(); //preload hack

		$class = new ReflectionClass(RuntimeBlockMapping::class);

		$property = $class->getProperty("bedrockKnownStates");
		$property->setAccessible(true);
		$property->setValue(null, $bedrockKnownStates);

		$method = $class->getMethod("registerMapping");
		$method->setAccessible(true);

		$legacyIdMap = json_decode(file_get_contents(RESOURCE_PATH . "vanilla/block_id_map.json"), true);

		foreach($bedrockKnownStates as $k => $obj){
			if(!isset($legacyIdMap[$obj["name"]])){
				continue;
			}
			$method->invoke(null, $k, $legacyIdMap[$obj["name"]], $obj["data"]);
		}
	}

	public static function deserialize(World $world, int $chunkX, int $chunkZ, string $data, bool $outdated = false) : void{
		$stream = new NetworkBinaryStream($data);

		$subChunkCount = $stream->getByte();
		/** @var SubChunk[] $subChunks */
		$subChunks = [];

		for($y = 0; $y < $subChunkCount; ++$y){
			$subChunkVersion = $stream->getByte();

			switch($subChunkVersion){
				case 0:
					$idArray = $stream->get(4096);
					$metaArray = $stream->get(2048);

					$subChunks[] = new SubChunk([SubChunkConverter::convertSubChunkXZY($idArray, $metaArray)]);

					if($outdated){
						$stream->offset += 4096; //2048 bytes of sky light, 2048 bytes of block light
					}
					break;

				case 1:
				case 8:
					/** @var PalettedBlockArray[] $layers */
					$layers = [];

					$layersCount = $subChunkVersion === 8 ? $stream->getByte() : 1;
					for($i = 0; $i < $layersCount; ++$i){
						$bitsPerBlock = ($stream->getByte() ^ 1) >> 1;
						$blocksPerWord = (int) floor(32 / $bitsPerBlock);
						$wordCount = (int) ceil(4096 / $blocksPerWord) << 2;
						$wordArray = $stream->get($wordCount);

						$palette = [];
						$paletteCount = $stream->getVarInt();

						for($i = 0; $i < $paletteCount; ++$i){
							list($id, $meta) = RuntimeBlockMapping::fromStaticRuntimeId($stream->getVarInt());
							$palette[] = $id << 4 | $meta & 0xf;
						}

						$layers[] = PalettedBlockArray::fromData($bitsPerBlock, $wordArray, $palette);
					}

					$subChunks[] = new SubChunk($layers);
					break;

				default:
					throw new ChunkException("SubChunk version $subChunkVersion not supported yet");
			}
		}

		$heightMap = unpack("v*", $stream->get(512));
		$biomeIds = $stream->get(256);

		$borderBlockCount = $stream->getByte();
		if($borderBlockCount !== 0){
			throw new ChunkException("Border block count must be 0");
		}

		if($outdated){
			$extraDataCount = $stream->getVarInt();
			if($extraDataCount !== 0){
				throw new ChunkException("Extra data count not implemented"); //TODO: implementation
			}
		}

		$tags = [];

		$nbtSerializer = new NetworkNbtSerializer();
		while(!$stream->feof()){
			$remaining = $stream->getRemaining();
			try{
				$offset = 0;
				$tags[] = $nbtSerializer->read($remaining, $offset, 512)->getTag();
			}catch(NbtDataException $e){
				break;
			}
			$stream->offset += $offset;
		}

		$chunk = new Chunk($chunkX, $chunkZ, $subChunks, [], $tags, $biomeIds, $heightMap);
		$chunk->setGenerated(true);

		foreach($tags as $tag){
			$tile = TileFactory::createFromData($world, $tag);
			if($tile instanceof Tile){
				$chunk->addTile($tile);
			}
		}

		$world->setChunk($chunkX, $chunkZ, $chunk, false);
	}
}