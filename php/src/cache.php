<?php

function cache(string $key, callable $fn, ?int $ttl = null, ?callable $status = null) {
  $encoding  = $_ENV['CACHE_TYPE'] ?? 'serialized';
  $file      = $_ENV['CACHE_DIR'] . '/' . sha1($key . '.' . $encoding);
  $ttl     ??= (int) $_ENV['CACHE_TTL'] ?? 0;
  $data      = null;
  $status  ??= fn($status) => error_log('Cache-Status: ' . $status . ' [' . $key . ']');

  if ($ttl === 0 || !is_file($file) || (time() - filemtime($file)) < $ttl) {
    $data = $fn();

    $status(!is_file($file) ? 'MISS' : 'REVALIDATED');

    if ($ttl > 0) {
      file_put_contents($file, $encoding == 'json' ? json_encode($data) : serialize($data));
    } else {
      $status('BYPASS');
    }

  } else {
    $status('HIT');

    $raw  = file_get_contents($file);
    $data = $encoding == 'json' ? json_decode($raw) : unserialize($raw);
  }

  return $data;
}

$_ENV['CACHE_DIR'] ??= sys_get_temp_dir();