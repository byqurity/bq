<?php

function cache(string $key, callable $fn, ?int $ttl = null) {
  $encoding  = $_ENV['CACHE_TYPE'] ?? 'serialized';
  $directory = sys_get_temp_dir(). '/cache';
  $file      = $directory . '/' . $key . '.' . $encoding;
  $ttl     ??= (int) $_ENV['CACHE_TTL'] ?? 0;
  $data      = null;

  if (!is_dir($directory)) {
    mkdir($directory);
  }

  if ($ttl == 0 || !is_file($file) || (time() - filemtime($file)) < $ttl) {
    $data = $fn();

    if ($ttl > 0) {
      file_put_contents($file, $encoding == 'json' ? json_encode($data) : serialize($data));
    }

  } else {
    $raw  = file_get_contents($file);
    $data = $encoding == 'json' ? json_decode($raw) : unserialize($raw);
  }

  return $data;
}