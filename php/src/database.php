<?php

function query(string $query, $params = null) {
  static $connection = null;

  $params ??= [];

  if (!$connection) {
    $dbType   = $_ENV['DB_TYPE'] ?? 'mysql';  // mysql, pgsql, sqlite
    $host     = $_ENV['DB_HOST'];
    $user     = $_ENV['DB_USER'];
    $password = $_ENV['DB_PASSWORD'];
    $dbName   = $_ENV['DB_NAME'];
    $port     = $_ENV['DB_PORT'] ?? 3306;
    $ssl      = $_ENV['DB_SSL'] != null;

    if ($dbType === 'mysql' || $dbType === 'mariadb') {
      $dsn        = "mysql:host=$host;dbname=$dbName;port=$port;charset=utf8mb4" . ($ssl ? ';sslmode=prefer' : '');
      $options    = [ PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false, PDO::MYSQL_ATTR_SSL_CA => null ];
      $connection = new PDO($dsn, $user, $password, ($ssl ? $options : []));
    } elseif ($dbType === 'pgsql') {
      $dsn = "pgsql:host=$host;dbname=$dbName;port=$port";
      $connection = new PDO($dsn, $user, $password);
      $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } elseif ($dbType === 'sqlite') {
      $connection = new PDO("sqlite:$dbName");
      $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    $connection->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, false);
    $connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
  }

  if (preg_match_all('/:([a-zA-Z0-9_]+)/', $query, $matches)) {
    $stmt = $connection->prepare($query);
    foreach ($params as $name => $value) {
      $type = is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR);
      $stmt->bindValue(':' . $name, $value, $type);
    }
  } else {
    $stmt = $connection->prepare($query);
    $pos = 1;
    foreach ($params as $value) {
      $type = is_int($value) ? PDO::PARAM_INT : (is_bool($value) ? PDO::PARAM_BOOL : PDO::PARAM_STR);
      $stmt->bindValue($pos++, $value, $type);
    }
  }

  if (!$stmt->execute()) {
    return ['error' => $stmt->errorInfo()];
  }
  
  if (strpos(ltrim($query), 'SELECT') === 0 || strpos(ltrim($query), 'RETURNING') !== false) {
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($list as &$e) {

      foreach ($e as $k => $v) {

        if (is_string($v) && ((str_starts_with(ltrim($v), '{') && str_ends_with(rtrim($v), '}')) || str_starts_with(ltrim($v), '[') && str_ends_with(rtrim($v), ']'))) {
          $e[$k] = json_decode($v, true);
        } 
      }
    }

    return $list;
  } else {
    return (object) ['affected' => $stmt->rowCount(), 'id' => $connection->lastInsertId()];
  }
}