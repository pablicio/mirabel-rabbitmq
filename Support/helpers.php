<?php

if (!function_exists('mb_config_path')) {
  function mb_config_path($path)
  {
    $path_segments = explode('.', $path);
    $file_name = $path_segments[0];
    unset($path_segments[0]);

    $array = include "config\\$file_name.php";

    foreach ($path_segments as $value) {
      $array = $array[$value];
    }
   
    return $array;
  }
}