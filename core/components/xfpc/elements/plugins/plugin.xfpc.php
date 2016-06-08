<?php
/**
 * xFPC
 *
 * Copyright 2012-13 by SCHERP Ontwikkeling <info@scherpontwikkeling.nl>
 *
 * This file is part of xFPC.
 *
 * xFPC is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * xFPC is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * xFPC; if not, write to the Free Software Foundation, Inc., 59 Temple Place,
 * Suite 330, Boston, MA 02111-1307 USA
 *
 * @package xFPC
 * @author Patrick Nijkamp <patrick@scherpontwikkeling.nl>
 */

$xFPCEnabled = (int) $modx->getOption('xfpc.enabled');

if ($xFPCEnabled == 0) {
  return;
}

$eventName = $modx->event->name;

switch($eventName) {
  case 'OnDocFormSave':
    $setLifeTime = true;
  case 'OnSiteRefresh':
    $count = 0;
    $dir = $modx->getOption('core_path').'cache/xfpc/';
    $assetsDir = $modx->getOption('assets_path').'components/xfpc/cache/';
    if (is_dir($dir)) {
      // Remove all cache files
      $handle = opendir($dir);
      while (false !== ($entry = readdir($handle))) {
        if (substr($entry, 0, 1) != '.' && strpos($entry, 'config') === false) {
          unlink($dir.$entry);
          $count++;
        }
      }

      // Remove all JS and CSS cache files
      $handle = opendir($assetsDir);
      while (false !== ($entry = readdir($handle))) {
        if (substr($entry, 0, 1) != '.') {
          unlink($assetsDir.$entry);
          $count++;
        }
      }
    }

    if (isset($setLifeTime)) {
      // Get the TV option
      $lifeTimeTv = (int) $modx->getOption('xfpc.lifetimetv');

      if ((int) $lifeTimeTv != 0) {
        if (isset($_POST['tv'.$lifeTimeTv])) {
          $lifeTime = (int) $_POST['tv'.$lifeTimeTv];
          if ($lifeTime != '' && $lifeTime != 0) {
            // Generate the resource url
            $ressourceId = (int) $resource->get('id');
            $siteStart   = (int) $modx->getOption('site_start');
            $url = ($ressourceId == $siteStart) ? $modx->getOption('base_url') : $modx->makeUrl($ressourceId, '', '', 'abs');

            // Modx returns a full url when friendly urls are enabled and the resource alias is empty or can't be found.
            // Strip 'http://' and 'domain.tld'
            if (substr($url, 0, 7) == 'http://') {
              $url = str_replace('http://', '', $url);
              $url = explode('/', $url);
              array_shift($url);
              $url = '/'.implode('/', $url);
            }

            // Set cache lifetime
            // Get cache hash
            $hash = sha1($_SERVER['HTTP_HOST'].$url);

            // Generate lifetime file
            file_put_contents($modx->getOption('core_path').'cache/xfpc/'.$hash.'.config', json_encode(array(
              'lifeTime' => $lifeTime
            )));
          }
        }
      }
    }

    return;
    break;
}

// Don't execute in manager past this point
if ($modx->context->get('key') == 'mgr') {
  return;
}

header ('X-XFPC-Cache-Active: Yes');

$cache = true;
$refreshCache = false;

// If posting, don't cache
if (strtolower($_SERVER['REQUEST_METHOD']) == 'post') {
  // Don't cache and remove cached file
  $cache = false;
  $refreshCache = true;
}

// If user is logged in, don't cache
if ($modx->user->isAuthenticated() || $modx->user->isAuthenticated('mgr')) {
  header ('X-XFPC-Cache: Miss');
  return;
}

// Check for excludes
$excludes = $modx->getOption('xfpc.exclude');
if (!empty($excludes)) {
  $excludes = explode("\n", $excludes);
  foreach($excludes as $exclude) {
    if (trim($exclude) == '') {
      continue;
    }

    if (strpos($_SERVER['REQUEST_URI'], $exclude) !== false) {
      header ('X-XFPC-Cache: Excluded');
      return;
    }
  }
}

// Create a unique cache hash
$hash = sha1($_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
$dir = $modx->getOption('core_path').'cache/xfpc/';

// If cache enabled, check the age of the cache files
if ($cache && is_file($dir.$hash)) {
  $cacheLifeTime = $modx->getOption('xfpc.cachelife');
  if ($cacheLifeTime != '' && (int) $cacheLifeTime != 0) {
    $cacheLifeTime = (int) $cacheLifeTime;
    $fileModified = filemtime($dir.$hash);

    $difference = time() - $fileModified;

    if ($difference > $cacheLifeTime) {
      $refreshCache = true;
      header('X-XPFC-Cache: Lifetime exceeded');
    }
  }
}

switch($eventName) {
  case 'OnInitCulture':
    if ($refreshCache) {
      // URL is banned, refresh
      $cache = false;
      if (is_file($dir.$hash)) {
        unlink($dir.$hash);
      }
    }

    if ($cache) {
      if (is_file($dir.$hash)) {
        // Check the lifetime
        if (is_file($dir.$hash.'.config')) {
          $config = json_decode(file_get_contents($dir.$hash.'.config'), true);
          if (isset($config['lifeTime'])) {
            $lifeTime = (int) $config['lifeTime'];
            if ($lifeTime != 0) {
              if (time() - filemtime($dir.$hash) > $lifeTime) {
                $cache = false;
                unlink($dir.$hash);
              }
            }
          }
        }

        if ($cache) {
          header ('X-XFPC-Cache: Hit');
          $cacheContent = file_get_contents($dir.$hash);
          echo $cacheContent;
          exit();
        } else {
          header('X-XPFC-Cache: Lifetime exceeded');
        }
      }
    }
    break;
  case 'OnWebPageComplete':
    
    // disable caching on 404 error page
    $currentResource = $modx->resource;
    $errorPageId = $modx->getOption('error_page');
	    
    if ($currentResource && $errorPageId) {
        if ($currentResource->get('id') == $errorPageId) {
            $modx->log(modX::LOG_LEVEL_DEBUG, '[xFPC] disallow caching on 404 error page');
            $cache = false;
        }
    }
    
    if ($cache) {
      if (!is_dir($dir)) {
        mkdir($dir);
      }

      if (!is_writable($dir)) {
        $modx->log(modX::LOG_LEVEL_DEBUG, '[xFPC] Directory '.$dir.' not writable!');
      } else {
        $assetsUrl = 'http://'.str_replace('//', '/', substr($modx->getOption('site_url').$modx->getOption('xfpc.assets_path',null,$modx->getOption('assets_url').'components/xfpc/'), 7));
        $pageContent = $modx->resource->_output;

        $assetsCache = $modx->getOption('assets_path').'components/xfpc/cache/';

        if (!is_dir($assetsCache)) {
          mkdir($assetsCache);
        }

        if (!is_writable($assetsCache)) {
          $modx->log(modX::LOG_LEVEL_DEBUG, '[xFPC] Directory '.$assetsCache.' not writable!');
        } else {
          // Fetch the head
          preg_match('%\<head\>(.*)\<\/head\>%mis', $pageContent, $head);
          $head = $head[0];

          $jsIsCombined = false;
          $combineJsAndCss = $modx->getOption('xfpc.combinejsandcss');

          if ((int) $combineJsAndCss == 1) {
            $combineJsAndCss = true;
          } else {
            $combineJsAndCss = false;
          }

          if (!empty($head)) {
            if ((int) $modx->getOption('xfpc.combinecss') == 1) {
              // Fetch all external CSS
              preg_match_all('%\<link.*href\=["|\'](.+?)["|\'].*\>%i', $head, $styleSheets);

              // Create JS hash
              if (sizeof($styleSheets) > 0) {
                $cacheHash = sha1(json_encode($styleSheets));
                $cacheFile = $assetsCache.$cacheHash.'.css';

                // CSS Excludes
                $cssExclude = explode(',', $modx->getOption('xfpc.excludecss'));

                if (!is_file($cacheFile)) {

                  // Now we have an array (0 = replaceables and 1 = src for scripts)
                  $cssOutput = '';

                  foreach($styleSheets[1] as $key => $css) {
                    if (in_array(basename($css), $cssExclude)) {
                      continue;
                    }

                    $css = explode('?', $css);
                    $css = $css[0];

                    if (is_file($modx->getOption('base_path').$css)) {
                      $fp = fopen(str_replace('//', '/', $modx->getOption('base_path').$css), 'rb');
                      while ($cline = fgets($fp)) {
                        $cssOutput .= $cline;
                      }
                      fclose($fp);
                    }
                  }
                  file_put_contents($cacheFile, $cssOutput);

                  // Check if we need to minify
                  if ((int) $modx->getOption('xfpc.minifycss') == 1) {
                    // Get minified contents
                    $url = $assetsUrl.'min/?f='.$modx->getOption('assets_url').'components/xfpc/cache/'.$cacheHash.'.css';
                    $result = @file_get_contents($url);
                    if ($result) {
                      file_put_contents($cacheFile, $result);
                    }
                  }
                }

                // Remove CSS files from source HTML file
                foreach($styleSheets[0] as $key => $linkTag) {
                     if (in_array(basename($styleSheets[1][$key]), $cssExclude)) {
                    continue;
                     }
                     $pageContent = str_replace($linkTag, '', $pageContent);
                }

                $cssCombinedFile = '<link rel="stylesheet" type="text/css" href="'.$modx->getOption('assets_url').'components/xfpc/cache/'.$cacheHash.'.css" />';
              }
            }

            if ((int) $modx->getOption('xfpc.combinejs') == 1) {
              // Fetch all external JS
              preg_match_all('%\<script.*src\=["|\'](.+?)".*\>\<\/script\>%i', $head, $javaScript);

              // Create JS hash
              if (sizeof($javaScript) > 0) {
                $cacheHash = sha1(json_encode($javaScript));
                $cacheFile = $assetsCache.$cacheHash.'.js';

                // Exclude JS
                $jsExclude = explode(',', $modx->getOption('xfpc.excludejs'));

                if (!is_file($cacheFile)) {
                  // Now we have an array (0 = replaceables and 1 = src for scripts)
                  $scriptOutput = '';

                  foreach($javaScript[1] as $key => $script) {
                    if (in_array(basename($script), $jsExclude)) {
                      continue;
                    }
                    $script = explode('?', $script);
                    $script = $script[0];

                    if (is_file($modx->getOption('base_path').$script)) {
                      $fp = fopen(str_replace('//', '/', $modx->getOption('base_path').$script), 'rb');
                      while ($cline = fgets($fp)) {
                        $scriptOutput .= $cline;
                      }
                      fclose($fp);
                    }
                  }

                  // Combine JS and css
                  $scriptAdd = '/* No CSS found */';
                  if ($combineJsAndCss && isset($cssOutput) && $cssOutput != '' && (int) $modx->getOption('xfpc.combinecss') == 1) {
                    file_put_contents($assetsCache.$cacheHash.'.combine', '');
                    $jsIsCombined = true;
                    $cssOutput = rawurlencode($cssOutput);
                    $scriptAdd = 'var xFPCStyle = document.createElement(\'style\');
                    xFPCStyle.innerHTML = unescape(\''.$cssOutput.'\');
                    document.getElementsByTagName(\'head\')[0].appendChild(xFPCStyle);';
                  }

                  file_put_contents($cacheFile, $scriptAdd.$scriptOutput);

                  // Check if we need to minify
                  if ((int) $modx->getOption('xfpc.minifyjs') == 1) {
                    // Get minified contents
                    $url = $assetsUrl.'min/?f='.$modx->getOption('assets_url').'components/xfpc/cache/'.$cacheHash.'.js';
                    $result = @file_get_contents($url);
                    if ($result) {
                      file_put_contents($cacheFile, $result);
                    }
                  }
                }

                // Remove script files from source HTML file
                foreach($javaScript[0] as $key => $scriptTag) {
                     if (in_array(basename($javaScript[1][$key]), $jsExclude)) {
                    continue;
                     }
                     $pageContent = str_replace($scriptTag, '', $pageContent);
                }

                $pageContent = str_replace('</head>', '<script type="text/javascript" src="'.$modx->getOption('assets_url').'components/xfpc/cache/'.$cacheHash.'.js"></script></head>', $pageContent);
              }
            }
          }
        }

        if ($jsIsCombined === false && !is_file($assetsCache.$cacheHash.'.combine')) {
          $pageContent = str_replace('</head>', $cssCombinedFile.'</head>', $pageContent);
        }

        // Write the document content
        file_put_contents($dir.$hash, $pageContent);
      }
    }
  break;
}
