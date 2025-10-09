<?php

function localFetch(Context $context, $webDir = null) {
  $path = isset($webDir) ? $webDir : (isset($_ENV['WEB_DIR']) ? $_ENV['WEB_DIR'] : './web');
  $file = $path . $context->path;

  if (str_contains($context->path, '/.') || str_contains($context->path, '/_')) {
    http_response_code(403); exit;
  }

  if (!str_contains($file, '.php') && is_file($file)) {
    $ps  = explode('.', $file);
    $ext = strtolower($ps[count($ps) - 1]);
  
    $mimeTypes = [
      // Images
      'jpg' => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'png' => 'image/png',
      'gif' => 'image/gif',
      'bmp' => 'image/bmp',
      'webp' => 'image/webp',
      'avif' => 'image/avif',
      'svg' => 'image/svg+xml',
      'ico' => 'image/x-icon',
  
      // Fonts
      'ttf' => 'font/ttf',
      'otf' => 'font/otf',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
      'eot' => 'application/vnd.ms-fontobject',
  
      // Documents
      'pdf' => 'application/pdf',
      'doc' => 'application/msword',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'xls' => 'application/vnd.ms-excel',
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'ppt' => 'application/vnd.ms-powerpoint',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'txt' => 'text/plain',
      'rtf' => 'application/rtf',
  
      // Audio
      'mp3' => 'audio/mpeg',
      'wav' => 'audio/wav',
      'ogg' => 'audio/ogg',
      'm4a' => 'audio/mp4',
  
      // Video
      'mp4' => 'video/mp4',
      'avi' => 'video/x-msvideo',
      'mov' => 'video/quicktime',
      'wmv' => 'video/x-ms-wmv',
      'flv' => 'video/x-flv',
      'webm' => 'video/webm',
  
      // Archives
      'zip' => 'application/zip',
      'rar' => 'application/vnd.rar',
      'tar' => 'application/x-tar',
      'gz' => 'application/gzip',
      '7z' => 'application/x-7z-compressed',
  
      // Web files
      'html' => 'text/html',
      'htm' => 'text/html',
      'css' => 'text/css',
      'js' => 'application/javascript',
      'json' => 'application/json',
      'xml' => 'application/xml',
  
      // Fonts
      'ttf' => 'font/ttf',
      'otf' => 'font/otf',
      'woff' => 'font/woff',
      'woff2' => 'font/woff2',
  
      // Miscellaneous
      'csv' => 'text/csv',
      'ics' => 'text/calendar',
      'eot' => 'application/vnd.ms-fontobject'
    ];
    
    error_log('GET ' . $context->path);
    
    $mimeType      = ($mimeTypes[$ext] ?? 'application/octet-stream');
    $cacheControl  = $_ENV['WEB_CACHE'] ?? 'public, max-age=3600';
    $lastModified  = filemtime($file);
    $formattedTime = gmdate('D, d M Y H:i:s \G\M\T', $lastModified);
    $hash          = md5($formattedTime);
    $size          = filesize($file);
    $headers       = [];

    $headers['content-type']  = $mimeType;
    $headers['Cache-Control'] = $cacheControl;
    $headers['ETag']          = 'W/"' . $hash . '"';
    $headers['Last-Modified'] = $formattedTime;
    $headers['Accept-Ranges'] = 'bytes';
    $headers['Date'] = gmdate('D, d M Y H:i:s') . ' GMT';

    $writeHeaders = function() use (&$headers) {
      foreach ($headers as $k => $v) {
        header("$k: $v");
      }
    };

    $hasRange      = isset($_SERVER['HTTP_RANGE']);
    $ifRange       = $_SERVER['HTTP_IF_RANGE'] ?? null;
    $ifNone        = $_SERVER['HTTP_IF_NONE_MATCH'] ?? null;
    $ifSince       = $_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? null;
    $isConditional = $ifNone || $ifSince;
    $isModified    = !(($ifNone && $ifNone === 'W/"' . $hash . '"') || ($ifSince && $ifSince === $formattedTime));

    if (!$isModified) {
      header('HTTP/1.1 304 Not Modified');
      $writeHeaders();
      exit;
    }

    if ($hasRange && $ifRange && $ifRange !== 'W/"' . $hash . '"' && $ifRange !== $formattedTime) {
      unset($_SERVER['HTTP_RANGE']);
    }
    
    if (str_starts_with($mimeType, 'image/') && ($context->query('type') != null || $context->query('width') != null || $context->query('height') != null)) {
      $image  = imagecreatefromstring(file_get_contents($file));
      $type   = $context->query('type') ?: $ext;
      $width  = $context->query('width')  != null ? (int) $context->query('width')  : imagesx($image);
      $height = $context->query('height') != null ? (int) $context->query('height') : imagesy($image);
      $cache  = sys_get_temp_dir() . '/' . $hash . "." . $width . "x" . $height . '.' . $type;
      $age    = is_file($cache) ? time() - filemtime($cache) : 0;

      $headers['content-type'] = 'image/' . $type;
      
      if (is_file($cache) && !$isModified) {
        $headers['Cache-Status'] = 'INTERN; HIT; age="' . $age . '"';
      } else {
        $headers['Cache-Status'] = 'INTERN; ' . ($isConditional ? 'REVALIDATED' : 'MISS') . '; age="' . $age . '"';
        
        imagepalettetotruecolor($image);
  
        if (imagesx($image) > $width) {
          $height = imagesy($image) * $width / imagesx($image);
          $out    = imagecreatetruecolor($width, $height);
  
          imagealphablending($out, false);
          imagesavealpha($out, true);
  
          imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
          imagedestroy($image);
  
          $image = &$out;      
        } else if (imagesy($image) > $height) {
          $width = imagesx($image) * $height / imagesy($image);
          $out   = imagecreatetruecolor($width, $height);
  
          imagealphablending($out, false);
          imagesavealpha($out, true);
  
          imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));
          imagedestroy($image);
  
          $image = &$out;
        }

        ('image' . $type)($image, $cache, 70);
  
        imagedestroy($image);
      }

      $file = $cache;
      $size = filesize($file);
    } 

    if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
      $start  = intval($matches[1]);
      $end    = $matches[2] !== '' ? intval($matches[2]) : $size - 1;
      $length = $end - $start + 1;

      error_log('  -> Range: ' . $_SERVER['HTTP_RANGE']);

      header('HTTP/1.1 206 Partial Content');

      $headers['Content-Range']  = "bytes $start-$end/$size";
      $headers['Content-Length'] = $length;

      error_log('  -> 206');

      $writeHeaders();

      $fh = fopen($file, 'rb');
      fseek($fh, $start);

      $bytesLeft = $length;
      $chunkSize = 4 * 1024 * 1024;

      while ($bytesLeft > 0 && !feof($fh)) {
        $readSize = min($chunkSize, $bytesLeft);
        $bytes      = fread($fh, $readSize);

        echo $bytes;
        
        $bytesLeft -= $readSize;

        error_log('  >> ' . $readSize . ' bytes');

        ob_flush();
        flush();
      }

      fclose($fh);

    } else {
      header('HTTP/1.1 200 OK');

      error_log('  -> 200');

      $headers['Content-Length'] = $size;

      $writeHeaders();

      readfile($file);
    }

    exit;
  }

  return false;
}
