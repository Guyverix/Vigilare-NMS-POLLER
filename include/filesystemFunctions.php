<?php
/*
  This is called by require or include.  General functions specifically related to working
  with the filesystem in some way.
*/


// Source https://stackoverflow.com/questions/24783862/list-all-the-files-and-folders-in-a-directory-with-php-recursive-function (Angel Politis?)
function getDirContents($dir, $filter = '', &$results = array()) {
  if (file_exists($dir)) {
    $files = scandir($dir);
  }
  if ( !empty($files)) {
    foreach($files as $key => $value){
      $path = realpath($dir.DIRECTORY_SEPARATOR.$value);
      if(!is_dir($path)) {
        if(empty($filter) || preg_match($filter, $path)) $results[] = $path;
      }
      elseif($value != "." && $value != "..") {
        getDirContents($path, $filter, $results);
      }
    }
  }
  else {
    $results=['']; // We must return array either way
  }
  return $results;
}
// Simple Call: List all files  tested 06-13-23
//var_dump(getDirContents('/opt/nmsApi/rrd'));
// Regex Call: List php files only
//var_dump(getDirContents('/opt/nmsApi/rrd', '/\.rrd$/'));



/* dir is full path to directory, age '-2 day' or strings like that or other acceptable values for strtotime can be used.
   Modified from https://stackoverflow.com/questions/8965778/the-correct-way-to-delete-all-files-older-than-2-days-in-php (user reformed)
   Filter based on MTIME, not CTIME
*/
function deleteOldFiles($dir, $age) {
  $fileSystemIterator = new FilesystemIterator($dir);
  $now = time();
  $threshold = strtotime($age);
  foreach ($fileSystemIterator as $file) {
    if ($threshold >= $file->getMTime()) {
        unlink($dir.'/'.$file->getFilename());
    }
  }
}
//var_dump(deleteOldFiles('/opt/nmsApi/logs', '-7 days'));  //tested 09-30-23

?>
