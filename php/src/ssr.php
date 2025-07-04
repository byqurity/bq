<?php


function value(&$context, $value) {
  $value = trim($value);

  if ($value == '') {
    return null;
  }

  if ($value[0] == '!') {
    return (bool) !value($context, ltrim($value, '!'));
  } else if (str_contains($value, '?')) {
    $p1 = explode('?', $value);
    $p2 = explode(':', $p1[1]);
    
    return value($context, $p1[0]) ? value($context, $p2[0]) : value($context, $p2[1]);
  } else if (preg_match('/(?<left>.+?)(?<operator>[><=!]+)(?<right>.+)/', $value, $matches)) {
    $a = value($context, $matches['left']);
    $b = value($context, $matches['right']);

    switch ($matches['operator']) {
      case '>':
        return $a > $b;
      case '<':
        return $a < $b;
      case '>=':
        return $a >= $b;
      case '<=':
        return $a <= $b;
      case '==':
        return $a == $b;
      case '!=':
        return $a != $b;
    }

  } else if (str_contains($value, '|')) {
    $pipe = explode('|', $value);
    $v    = value($context, $pipe[0]);

    for ($i = 1; $i < count($pipe); $i++) {
      $o = explode(':', $pipe[$i]);
      $p = count($o) == 2 ? value($context, $o[1]) : null;
      $v = $context->use(trim($o[0]), $v, $p);
    }

    return $v;
    
  } else if ($value[0] == '\'' || $value[0] == '"') {
    return trim($value, '\'"');
  } else if (is_numeric($value)) {
    return (float) $value;
  } else if (str_contains($value, '.')) {
    $parts = explode('.', $value);
    $value = $context->use(trim($parts[0]));
  
    for ($i = 1; $i < count($parts); $i++) {
      $key = $parts[$i];
      
      if (is_array($value)) {
        $value = $value[$key] ?? null;
      } elseif (is_object($value)) {
        $value = $value->$key ?? null;
      }
    }

    return $value;
  } else {
    return $context->use($value);
  }

}

function checkBinding($str, &$context) {
  return preg_replace_callback('/{{\s*([^}]+)\s*}}/', function ($m) use (&$context) {
    $v = value($context, $m[1]);

    return is_bool($v) ? ($v ? ' ' : null) : $v;
  }, $str);
}

function renderElement($e, &$context, callable $children = null) {
  static $selfClosedTags = ['show', 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];

  if ($e instanceof DOMElement) {

    if ($e->nodeName == 'show' && value($context, checkBinding($e->getAttribute('when'), $context)) == false) {
      return;
    } else if ($e->nodeName == 'children' && $children != null) {
      echo $children();
    } else if ($e->nodeName == 'scope') {

      foreach ($e->attributes as $key => $attr) {
        $v = checkBinding($attr->value, $context) ?? $attr->value;

        if ($v === $attr->value) {
          $v = value($context, $attr->value);
        }

        $context->bind($key, fn() => $v);
      }

      foreach ($e->childNodes as $child) {
        renderElement($child, $context, $children);
      }

      foreach ($e->attributes as $key => $attr) {
        $context->unbind($key);
      }

    } else if ($e->nodeName == 'fragment') {
      $fragment = checkBinding($e->getAttribute(':name'), $context);

      foreach ($e->attributes as $key => $attr) {

        if ($key == $attr->value || $key == ':name') {
          continue;
        }

        $v = checkBinding($attr->value, $context) ?? $attr->value;

        if ($v === $attr->value) {
          $v = value($context, $attr->value) ?? $attr->value;
        }

        $context->bind($key, fn() => $v);
      }

      render($fragment, $context, function () use (&$e, &$context, &$children) {
        foreach ($e->childNodes as $child) {
          renderElement($child, $context, $children);
        }
      });

      foreach ($e->attributes as $key => $attr) {

        if ($key == $attr->value || $key == ':name') {
          continue;
        }
        
        $context->unbind($key);
      }

    } else if ($e->nodeName == 'for') {
      $each      = value($context, checkBinding($e->getAttribute('each'), $context));
      $nameIndex = $e->hasAttribute('index') ? $e->getAttribute('index') : '@i';
      $nameValue = $e->hasAttribute('as') ? $e->getAttribute('as') : '@v';
      $keys      = array_keys($each);
      $count     = count($keys);

      foreach ($keys as $index => $key) {
        $value = $each[$key];

        $context->bind($nameIndex, fn() => $key);
        $context->bind($nameValue, fn() => $value);

        $context->bind('@count',         fn() => $count);
        $context->bind('@next',          fn() => ($index + 1 < $count) ? [ 'index' => $keys[$index + 1], 'value' => $each[$keys[$index + 1]] ] : null);
        $context->bind('@previous',      fn() => ($index - 1 >= 0)     ? [ 'index' => $keys[$index - 1], 'value' => $each[$keys[$index - 1]] ] : null);

        foreach ($e->childNodes as $child) {
          renderElement($child, $context, $children);
        }

        $context->unbind($nameIndex);
        $context->unbind($nameValue);
        $context->unbind('@count');
        $context->unbind('@next');
        $context->unbind('@previous');
      }

    } else {

      if ($e->nodeName != 'show') {
        echo '<' . $e->nodeName;
  
        foreach ($e->attributes as $attrName => $attrValue) {

          if ($attrValue->value !== '') {
            $attr = checkBinding($attrValue->value, $context);

            if ($attr == '') {
              continue;
            }

            $attr = trim($attr);
          } else {
            $attr = $attrValue->value;
          }

          $quote = str_contains($attr, '"') ? '\'' : '"';

          echo ' ' . $attrName . ($attr != '' ? ('=' . $quote . $attr . $quote) : '');
        }
  
        echo '>';
      }

      foreach ($e->childNodes as $child) {
        renderElement($child, $context, $children);
      }

      if (!in_array($e->nodeName, $selfClosedTags)) {
        echo '</' . $e->nodeName . '>';
      }
    }
  } if ($e instanceof DOMText) {
    echo checkBinding($e->data, $context);
  }
}

function render($path, &$context, callable $children = null) {
  static $documentCache = [];

  $path = str_contains($path, '.') ? $path : ('web/fragments/' . $path . '.fragment.html');

  if (!isset($documentCache[$path])) {
    libxml_use_internal_errors(true);
    
    $html = '<?xml version="1.0" encoding="utf-8"?>' . file_get_contents($path);

    $documentCache[$path] = new DOMDocument('1.0', 'UTF-8');
    
    $documentCache[$path]->encoding = 'UTF-8';
    $documentCache[$path]->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_COMPACT | LIBXML_HTML_NODEFDTD);

    if ($documentCache[$path]->doctype) {
      echo '<!DOCTYPE ' . $documentCache[$path]->doctype->name . '>' . PHP_EOL;
    }
    
    libxml_clear_errors();
  }

  foreach ($documentCache[$path]->childNodes as $child) {
    renderElement($child, $context, $children);
  }

}

function sync($key, $text = null, $attributes = []) {
  print  PHP_EOL . '<data data-sync="' . $key . '"';

  foreach ($attributes as $k => $v) {
    print ' data-sync.' . $k . '="' . $v . '"';
  }
  
  print '>' . $text . '</data>';
}