<?php

function addEnvFile($envFile) {

  if (is_file($envFile)) {
    $handle = fopen($envFile, 'r') or die('Cannot open: ' + $envFile);

    while(!feof($handle)) {
      $line = trim(explode('#', fgets($handle))[0]);

      if (!empty($line)) {
        $i = strpos($line, '=');
        $k = trim(substr($line, 0, $i));
        $v = trim(substr($line, $i + 1));

        $_ENV[$k] = $v;

        putenv("$k=$v");
      }
    }
    
    fclose($handle);
  }
}

addEnvFile('.env');
addEnvFile('.env.local');

if (!empty($_ENV['ENVIRONMENT'])) {
  $env = strtolower($_ENV['ENVIRONMENT']);

  addEnvFile(".env.$env");
  addEnvFile(".env.$env.local");
}