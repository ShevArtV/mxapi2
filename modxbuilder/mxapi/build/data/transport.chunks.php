<?php
/**
 * @var modxBuilder $this
 * @var string $categoryName
 * @var string $namespace
 */

$chunks = [];

/** @var modCategory $mainCategory */
$mainCategory = $this->modx->getObject('modCategory',[
    'category' => $categoryName
]);

if(!$mainCategory) return $chunks;

/** @var modChunk[] $realChunks */
$realChunks = $mainCategory->getMany('Chunks');

if(!$realChunks) return $chunks;

foreach($realChunks as $realChunk){
    /** @var modChunk $chunk */
    $chunk = $this->modx->newObject('modChunk');
    $chunkData = $realChunk->toArray();
    $chunkData['id'] = 0;
    //TODO remove comment if you want to make your chunks static
    $chunkData['static'] = 1;
    $chunk->fromArray($chunkData);
    $chunks[] = $chunk;
}

unset($realChunks,$chunkData);

return $chunks;
