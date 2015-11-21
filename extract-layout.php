<?php

if ($argc != 2)
{
    die("Wrong number of arguments. Usage: " . $argv[0] . " <rdb filename>\n");
}

$file = fopen($argv[1], "r");
if ($file === false)
{
    die("Could not open " . $argv[1] . "\n");
}

$extentMap = array();
$extentUtilization = array();

function readUInt64($file)
{
    // Before PHP 5.6.3, we must read 64 bit numbers as two 32 bits
    $v = unpack("L2", fread($file, 8));
    // TODO: Use more than 32 bits
    return $v[1];
}

function readUInt32($file)
{
    $v = unpack("L", fread($file, 4));
    return $v[1];
}

class StaticHeader
{
    public $softwareName;
    public $version;
};

function readStaticHeader($file)
{
    $res = new StaticHeader();
    $res->softwareName = rtrim(fread($file, 16));
    $res->version = rtrim(fread($file, 16));
    return $res;
}

fseek($file, 0);
$staticHeader = readStaticHeader($file);
echo "Header: " . $staticHeader->softwareName . ", serializer version " . $staticHeader->version . "\n";

class BlockId
{
    public $isAux;
    public $isNull;
    public $id;
};

function readBlockId($file)
{
    $res = new BlockId();
    // This is not quite correct. We want to read only the first bit, but `unpack` doesn't support that.
    // TODO: Use more than 32 bits
    $v = unpack("L", fread($file, 4));
    $res->id = $v[1];
    fread($file, 3);
    $v = unpack("C", fread($file, 1));
    $res->isAux = $v[1] == 128;
    $res->isNull = $v[1] > 128;
    return $res;
}

class FlaggedOffset
{
    public $isNull;
    public $offset;
};

function readFlaggedOffset($file)
{
    $res = new FlaggedOffset();
    $v = unpack("L", fread($file, 4));
    $res->offset = $v[1];
    $v = unpack("C3", fread($file, 3));
    $res->offset += (int)($v[1] << 32);
    $res->offset += (int)($v[2] << 40);
    $res->offset += (int)($v[3] << 48);
    // This is not quite correct. We want to read only the first bit, but `unpack` doesn't support that.
    $v = unpack("C", fread($file, 1));
    $res->isNull = $v[1] > 0;
    return $res;
}

class LbaShardMetablock
{
    public $lastLbaExtentOffset;
    public $lastLbaExtentEntriesCount;
    public $lbaSuperblockOffset;
    public $lbaSuperblockEntriesCount;
};

function readLbaShardMetablock($file)
{
    $res = new LbaShardMetablock();
    $res->lastLbaExtentOffset = readFlaggedOffset($file);
    $res->lastLbaExtentEntriesCount = readUInt32($file);
    fread($file, 4); // Padding
    $res->lbaSuperblockOffset = readFlaggedOffset($file);
    $res->lbaSuperblockEntriesCount = readUInt32($file);
    fread($file, 4); // Padding
    return $res;
}

class LbaEntry
{
    public $serBlockSize;
    public $blockId;
    public $recency;
    public $offset;
    
    public function isPadding()
    {
        return $this->offset->isNull && $this->blockId->isNull;
    }
};

function readLbaEntry($file)
{
    $res = new LbaEntry();
    fread($file, 4); // reserved
    $res->serBlockSize = readUInt32($file);
    $res->blockId = readBlockId($file);
    $res->recency = readUInt64($file);
    $res->offset = readFlaggedOffset($file);
    return $res;
}

class Metablock
{
    public $lbaShards;
    public $inlineLbaEntries;
    public $activeExtent;
};

function readMetablock($file)
{
    $LBA_SHARD_FACTOR = 4;
    $LBA_INLINE_SIZE = 4096 - 512;
    $LBA_NUM_INLINE_ENTRIES = $LBA_INLINE_SIZE / 32;

    $res = new Metablock();
    fread($file, 8); // Padding for extent_manager_part
    for ($i = 0; $i < $LBA_SHARD_FACTOR; ++$i)
    {
        $res->lbaShards[] = readLbaShardMetablock($file);
    }
    for ($i = 0; $i < $LBA_NUM_INLINE_ENTRIES; ++$i)
    {
        $res->inlineLbaEntries[] = readLbaEntry($file);
    }
    $numInlineEntries = readUInt32($file);
    $res->inlineLbaEntries = array_slice($res->inlineLbaEntries, 0, $numInlineEntries);
    foreach ($res->inlineLbaEntries as $k => $e)
    {
        if ($e->isPadding())
        {
            unset($res->inlineLbaEntries[$k]);
        }
    }
    fread($file, 4); // Padding
    $res->activeExtent = readUInt64($file);
    return $res;
}

class CrcMetablock
{
    public $magic;
    public $formatVersion;
    public $crc;
    public $version;
    public $metablock;
    // TODO: Support computing the actual CRC
};

function readCrcMetablock($file)
{
    $res = new CrcMetablock();
    $res->magic = fread($file, 8);
    $res->formatVersion = readUInt32($file);
    $res->crc = readUInt32($file);
    $res->version = readUInt64($file);
    $res->metablock = readMetablock($file);
    return $res;
}

function findLatestMetablock($file)
{
    $EXTENT_SIZE = 2 * 1024 * 1024;
    $METABLOCK_SIZE = 4096;

    $best = null;
    // Skip the first offset because that's where the static header is
    for ($i = 1; $i < $EXTENT_SIZE / $METABLOCK_SIZE; ++$i)
    {
        fseek($file, $i * $METABLOCK_SIZE);
        $metablock = readCrcMetablock($file);
        if ($metablock->magic != "metablck")
        {
            continue;
        }
        if ($best == null || $metablock->version > $best->version)
        {
            $best = $metablock;
        }
    }
    return $best;
}

class LbaSuperblockEntry
{
    public $offset;
    public $entriesCount;
};

function readLbaSuperblockEntry($file)
{
    $res = new LbaSuperblockEntry();
    $res->offset = readFlaggedOffset($file);
    $res->entriesCount = readUInt64($file);
    return $res;
}

class LbaSuperblock
{
    public $magic;
    public $entries;
}

function readLbaSuperblock($file, $entryCount)
{
    $res = new LbaSuperblock();
    $res->magic = fread($file, 8);
    fread($file, 8); // Padding
    $res->entries = array();
    for ($i = 0; $i < $entryCount; ++$i)
    {
        $res->entries[] = readLbaSuperblockEntry($file);
    }
    return $res;
}

class LbaExtent
{
    public $magic;
    public $entries;
}

function readLbaExtent($file, $entryCount)
{
    $res = new LbaExtent();
    $res->magic = fread($file, 8);
    fread($file, 24); // Padding
    $res->entries = array();
    for ($i = 0; $i < $entryCount; ++$i)
    {
        $entry = readLbaEntry($file);
        if (!$entry->isPadding())
        {
            $res->entries[] = $entry;
        }
    }
    return $res;
}

function offsetToExtent($offset)
{
    $EXTENT_SIZE = 2 * 1024 * 1024;
    return ($offset - ($offset % $EXTENT_SIZE)) / $EXTENT_SIZE;
}

// This is a limited subset of the information in the LBA for now (offsets only).
class LbaList
{
    public $normalEntries;
    public $auxEntries;
};

function applyLbaEntry($lbaEntry, &$lbaList)
{
    if ($lbaEntry->blockId->isAux)
    {
        if ($lbaEntry->offset->isNull)
        {
            // Deletion
            unset($lbaList->auxEntries[$lbaEntry->blockId->id]);
        }
        else
        {
            $lbaList->auxEntries[$lbaEntry->blockId->id] = $lbaEntry;
        }
    }
    else
    {
        if ($lbaEntry->offset->isNull)
        {
            // Deletion
            unset($lbaList->normalEntries[$lbaEntry->blockId->id]);
        }
        else
        {
            $lbaList->normalEntries[$lbaEntry->blockId->id] = $lbaEntry;
        }
    }
}

function loadLbaList($file, $metablock)
{
    global $extentMap;
    global $extentUtilization;

    $res = new LbaList();
    $res->normalOffsets = array();
    $res->auxOffsets = array();

    // We read lba entries in the following order:
    // 1. From LBA extents listed in the lba superblock
    // 2. From the last LBA extent
    // 3. From the inline LBA entries

    $lbaExtents = array();
    foreach ($metablock->lbaShards as $lbaShard)
    {
        if (!$lbaShard->lbaSuperblockOffset->isNull)
        {
            $superblockOffset = $lbaShard->lbaSuperblockOffset->offset;
            $extentMap[offsetToExtent($superblockOffset)] = "LBA SB";
            $extentUtilization[offsetToExtent($superblockOffset)] = 1;

            fseek($file, $superblockOffset);
            $lbaSuperblock = readLbaSuperblock($file, $lbaShard->lbaSuperblockEntriesCount);

            foreach ($lbaSuperblock->entries as $lbaExtent)
            {
                $extentMap[offsetToExtent($lbaExtent->offset->offset)] = "LBA";
                // TODO: Compute proper utilization for LBA extents
                $extentUtilization[offsetToExtent($lbaExtent->offset->offset)] = 1;
                fseek($file, $lbaExtent->offset->offset);
                $lbaExtents[] = readLbaExtent($file, $lbaExtent->entriesCount);
            }
        }
        
        if (!$lbaShard->lastLbaExtentOffset->isNull)
        {
            $extentMap[offsetToExtent($lbaShard->lastLbaExtentOffset->offset)] = "LBA";
            // TODO: Compute proper utilization for LBA extents
            $extentUtilization[offsetToExtent($lbaShard->lastLbaExtentOffset->offset)] = 1;
            fseek($file, $lbaShard->lastLbaExtentOffset->offset);
            $lbaExtents[] = readLbaExtent($file, $lbaShard->lastLbaExtentEntriesCount);
        }
    }

    foreach ($lbaExtents as $lbaExtent)
    {
        foreach ($lbaExtent->entries as $lbaEntry)
        {
            applyLbaEntry($lbaEntry, $res);
        }
    }
    
    foreach ($metablock->inlineLbaEntries as $lbaEntry)
    {
        applyLbaEntry($lbaEntry, $res);
    }

    return $res;
}

echo "Reading metablock... ";
$extentMap[0] = "metablock";
$extentUtilization[0] = 1;
$metablock = findLatestMetablock($file);
echo "done\n";
//var_dump($metablock);

echo "Loading LBA... ";
$lbaList = loadLbaList($file, $metablock->metablock);
echo "done\n";
echo "Found " . count($lbaList->normalEntries) . " normal and " . count ($lbaList->auxEntries) . " aux blocks\n";

// TODO: Distinguish between aux and normal blocks in utilization?
$EXTENT_SIZE = 2 * 1024 * 1024;
foreach ($lbaList->normalEntries as $entry)
{
    $ext = offsetToExtent($entry->offset->offset);
    $extentMap[$ext] = "data";
    if (!isset($extentUtilization[$ext]))
    {
        $extentUtilization[$ext] = 0;
    }
    $extentUtilization[$ext] += $entry->serBlockSize / $EXTENT_SIZE;
}
foreach ($lbaList->auxEntries as $entry)
{
    $ext = offsetToExtent($entry->offset->offset);
    $extentMap[$ext] = "data";
    if (!isset($extentUtilization[$ext]))
    {
        $extentUtilization[$ext] = 0;
    }
    $extentUtilization[$ext] += $entry->serBlockSize / $EXTENT_SIZE;
}

function visualizeExtentMap($map, $utilization, $filename)
{
    $maxId = 0;
    foreach ($map as $id => $e)
    {
        if ($id > $maxId)
        {
            $maxId = $id;
        }
    }
 
    $height = 100;   
    $scale = max(1, 1000 / ($maxId + 1));

    $im = imagecreatetruecolor(($maxId + 1) * $scale, $height);
    $bgColor = imagecolorallocate($im, 255, 255, 255);
    $dataColor = imagecolorallocate($im, 128, 255, 128);
    $dataColor = imagecolorallocate($im, 128, 255, 128);
    $lbaColor = imagecolorallocate($im, 128, 128, 255);
    $lbaSbColor = imagecolorallocate($im, 0, 0, 128);
    $metablockColor = imagecolorallocate($im, 128, 128, 128);
    $unknownColor = imagecolorallocate($im, 255, 0, 0);
    $untilizedOverlayColor = imagecolorallocatealpha($im, 255, 255, 255, 48);

    imagefill($im, 0, 0, $bgColor);
    
    foreach ($map as $id => $e)
    {
        if ($e == "data")
        {
            $c = $dataColor;
        }
        elseif ($e == "LBA")
        {
            $c = $lbaColor;
        }
        elseif ($e == "LBA SB")
        {
            $c = $lbaSbColor;
        }
        elseif ($e == "metablock")
        {
            $c = $metablockColor;
        }
        else
        {
            $c = $unknownColor;
        }

        imagefilledrectangle($im, $id * $scale, 0, ($id + 1) * $scale, $height - 1, $c);
        imagefilledrectangle($im, $id * $scale, 0, ($id + 1) * $scale, ($height - 1) * (1 - $utilization[$id]), $untilizedOverlayColor);
    }

    imagepng($im, $filename);
    imagedestroy($im);
}

$out = basename($argv[1]) . ".png";
echo "Writing map to $out... ";
visualizeExtentMap($extentMap, $extentUtilization, $out);
echo "done\n";

fclose($file);
