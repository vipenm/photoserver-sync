<?php

namespace PhotoserverSync;

require_once 'vendor/autoload.php';

use PhotoserverSync\ImageManipulator;

$image = new ImageManipulator();

$time_start = microtime(true);

// First, move all files from UploadedToPi to ReadyForOptimisation
$initialList = $image->findAllImages('./images/UploadedToPi');
if ($initialList) {
  foreach ($initialList as $key => $value) {
    $image->moveFiles($value, './images/ReadyForOptimisation');
  }
}

// Resize images, upload to S3 and then move to Synced
$listOfImages = $image->findAllImages('./images/ReadyForOptimisation');
if ($listOfImages) {
  $initialCount = count($listOfImages);
  $list = $image->resizeAllImages($listOfImages);

  if ($list) {
    $finalCount = count($list);
    foreach ($list as $key => $value) {
      $image->moveFiles($value, './images/Synced');
    }
  }
}

echo ($finalCount . " images out of " . $initialCount . " successfully synced!");

$time_end = microtime(true);
$execution_time = ($time_end - $time_start)/60;
echo "<b>Total Execution Time:</b> ".$execution_time." Mins\n";
