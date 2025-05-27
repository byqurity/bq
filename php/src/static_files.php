<?php

function localFetch(Context $context, $webDir = null) {
  $path = isset($webDir) ? $webDir : (isset($_ENV['WEB_DIR']) ? $_ENV['WEB_DIR'] : './web');
  $file = $path . $context->path;

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
    
    $mimeType      = ($mimeTypes[$ext] ?? 'application/octet-stream');
    $cacheControl  = $_ENV['WEB_CACHE'] ?? 'public, max-age=3600';
    $lastModified  = filemtime($file);
    $hash          = md5_file($file);
    $formattedTime = gmdate('D, d M Y H:i:s \G\M\T', $lastModified);
    $size          = filesize($file);
    
    if (str_starts_with($mimeType, 'image/') && ($context->query('type') != null || $context->query('width') != null || $context->query('height') != null)) {
      $image  = imagecreatefromstring(file_get_contents($file));
      $type   = $context->query('type') ?: $ext;
      $width  = $context->query('width')  != null ? (int) $context->query('width')  : imagesx($image);
      $height = $context->query('height') != null ? (int) $context->query('height') : imagesy($image);
      $cache  = sys_get_temp_dir() . '/' . $hash . "." . $width . "x" . $height . '.' . $type;
      $age    = is_file($cache) ? time() - filemtime($cache) : null;

      header('content-type: image/' . $type);

      if (is_file($cache)) {
        header('cache-status: HIT');
      } else {
        header('cache-status: ' . 'MISS');
        header('age: ' . $age ?: 0);
        
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
    } else {
      header('content-type: ' . $mimeType);
    }

    header('Cache-Control: ' . $cacheControl);
    header('ETag: W/"'       . $hash . '"');
    header('Last-Modified: ' . $formattedTime);

    if ((isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $_SERVER['HTTP_IF_MODIFIED_SINCE'] === $formattedTime) || 
        (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] === 'W/"' . $hash . '"')) {
      header('HTTP/1.1 304 Not Modified');
    } else if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches)) {
      $start  = intval($matches[1]);
      $end    = $matches[2] !== '' ? intval($matches[2]) : $size - 1;
      $length = $end - $start + 1;

      header('HTTP/1.1 206 Partial Content');
      header("Content-Range: bytes $start-$end/$size");
      header('Content-Length: ' . $length);

      $fh = fopen($file, 'rb');
      fseek($fh, $start);
      echo fread($fh, $length);
      fclose($fh);
    } else {
      header('Content-Length: ' . $size);
      readfile($file);
    }

    exit;
  }

  return false;
}
