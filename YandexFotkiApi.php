<?php

/**
 *  @name Yandex Fotki Api
 *  @version 0.2
 *  @author Stan Drozdov, 2012-2013
 *
 *  @example
 *
 *      $yaFotki = new YandexFotkiApi();
 *      $yaFotki->init('username', 'tmp/', true);
 *
 *      $albums = $yaFotki->getAlbums(); //get all albums
 *
 *                  or to get albums sorted
 *
 *      $albums = $yaFotki->wrpGetAlbumsParentId(); //get all albums sorted ASCending
 *
 *
 *  - 0.2 (7.11.2012):
 *      + added smart cache to use files cache
 *
 *  Simple YaFotki API (fotki.yandex.ru) wrapper WITHOUT authorisation as a result
 *  without any authorisation required actions such as creation/modification of the Albums, photo uploads etc.
 *  By using this class you can easily integrate YaFotki galleries and photos onto your site. So you will be able to
 *  store all your photos on Yandex servers (and save your hosting qouta) and display them on your server as if they are on your own hosting.
 *
 *  The are some small and simple file caching system that works in two basic modes:
 *
 *  1) if @var $cacheIsPrimary has been set to TRUE - the module checks cache before doing any requests and in case
 *     no data in cache - makes any other calls to Yandex.
 *
 *  2) if @var $cacheIsPrimary == FALSE the module will always make calls to Yandex API servers and use cache only if
 *     the server will return some different http response code from 200
 *
 *  If you do not care about Yandex web servers ;) you can use the cache as described in second way, so your changes are always
 *  become available in real time, otherwise to make the loading speed much faster - use cache as described in first way.
 *  It is NOT recommended to use YaFotki API without cache at all due to very common fails of their API web servers.
 *
 */


namespace LIB;

class YandexFotkiApi {

    private $userName;
    private $apiRootUri;
    private $smartCacheDir;
    private $cacheIsPrimary;

    private $collectionUrls;


    private function _setDirFromFileName($rootDir, $fileName, $create = true, $level = 2) {
        $d = $rootDir;

        if(!file_exists($d) && $create)
            @mkdir($d, 0777);

        for($i = 0; $i < $level; $i++) {
            $d .= '/'. $fileName[$i];
            if(!file_exists($d) && $create)
                @mkdir($d, 0777);
        }

        if(file_exists($d))
            return $d;
        else
            return '';
    }

    private function _storeCache($url, $data) {
        $fileName = md5($url).'.sc';
        $fullPath = $this->_setDirFromFileName($this->smartCacheDir, $fileName) . '/'. $fileName;
        if(file_exists($fullPath))
            @unlink($fullPath);
        file_put_contents($fullPath, $data);
    }

    private function _getCache($url) {
        $fileName = md5($url).'.sc';
        $fullPath = $this->_setDirFromFileName($this->smartCacheDir, $fileName, false) . '/'. $fileName;
        if(!file_exists($fullPath))
            return false;

        return file_get_contents($fullPath);
    }

    /**
     *
     * @param string $userName - your YaFotki username
     * @param string $smartCacheDir - absolute path to dir where to store the cache
     * @param boolean $cacheIsPrimary - use cache firstly
     *
     * The initialisation call.
     *
     */

    public function init($userName, $smartCacheDir = false, $cacheIsPrimary = false) {
        $this->userName = $userName;
        $this->smartCacheDir = $smartCacheDir;
        $this->cacheIsPrimary = $cacheIsPrimary;
        $this->apiRootUri = 'http://api-fotki.yandex.ru/api/users/'. strtolower($userName).'/';
        $this->_setCollectionUrls();
    }

    private function _getUrlResponce($url) {

        if($this->cacheIsPrimary) {
            $cache = $this->_getCache($url);
            if($cache)
                return $cache;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if($httpCode == '200') {
            if($this->smartCacheDir) { // write cache
                $this->_storeCache($url, $response);
            }
            return $response;
        } else {
            if($this->smartCacheDir) {
                return $this->_getCache($url); // trying to get results from cache
            } else {
                print('<b>'.$url.'</b> - 502');
                return false; // totally failed and we could not do anything!
            }
        }
    }

    private function _getXmlObjectFromUrl($url) {

        $document = $this->_getUrlResponce($url);
        if(!$document || strlen($document) <= 0)
            return false;

        $document = $this->_cleanUpXml($document);
        $sXML = simplexml_load_string($document);
        if(!$sXML instanceof \SimpleXMLElement)
            return false;

        return $sXML;
    }

    private function _setCollectionUrls() {

        $workspaceTag  = 'workspace';
        $collectionTag = 'collection';
        $document = $this->_getXmlObjectFromUrl($this->apiRootUri);
        if(isset($document->app_workspace)) {
            $workspaceTag  = 'app_'.$workspaceTag;
            $collectionTag = 'app_'.$collectionTag;
        }

        if(!isset($document->$workspaceTag->$collectionTag))
            return false;

        foreach($document->$workspaceTag->$collectionTag as $collection) {
                $href = (string)$collection->attributes()->href[0];
                $id   = (string)$collection->attributes()->id[0];
                $this->collectionUrls[$id] = $href;
        }
    }

    private function _getIdFromString($start, $idStr) {
        preg_match('~.*'. $start. ':([0-9]+)~ism', (string)$idStr, $matches);
        if(isset($matches[1]) && (int)$matches[1] > 0)
            return $matches[1];
        else
            return false;
    }

    private function _cleanUpXml($xml) {
        if(strlen((string)$xml) > 0)
            return preg_replace(array('~(<f:)~is', '~(</f:)~is', '~(<atom:)~is', '~(</atom:)~is', '~(<app:)~is', '~(</app:)~is'),
                                array('<f_', '</f_', '<atom_', '</atom_', '<app_', '</app_'),
                                $xml);
        else
            return false;
    }

    private function _extractPictureFromXMLNode($xmlNode) {
        $data = array();

        $data['id'] = $this->_getIdFromString('photo', (string)$xmlNode->id);
        $data['title'] = (string)$xmlNode->title[0];
        $data['published'] = strtotime($xmlNode->published);
        $data['updated'] = strtotime($xmlNode->updated);
        if(isset($xmlNode->f_img)) {
            $data['images'] = array();
            foreach($xmlNode->f_img as $idx => $img) {
                $attr = $img->attributes();
                $tmp = array();
                $tmp['href']   = (string)$attr->href[0];
                $tmp['width']  = (string)$attr->width[0];
                $tmp['height'] = (string)$attr->height[0];

                $size = (string)$attr->size[0];
                $data['images'][$size] = $tmp;
            }
        }
        return $data;
    }


    private function _getNextPrevElementByKey($array, $key, $direction = 'next') {
        foreach($array as $k => $v) {
            next($array);
            if($k == $key) {
                if($direction == 'next')
                    return current($array);
                else {

                    if(!current($array))
                        end($array);
                    else
                        prev($array);

                    return prev($array);
                }
            }
        }
        return null;
    }


    public function getAlbums() {
        if(!isset($this->collectionUrls['album-list']))
            return false;

        $url = $this->collectionUrls['album-list'];

        $data = array();
        $sXML = $this->_getXmlObjectFromUrl($url);

        if(!isset($sXML->entry))
            return false;

        foreach($sXML->entry as $idx => $album) {
            $tmp = array();
            $id = $this->_getIdFromString('album', $album->id);
            if(!$id)
                continue;

            $tmp['id'] = $id;

            if(isset($album->title))
                $tmp['title'] = (string)$album->title;

            if(isset($album->summary))
                $tmp['description'] = (string)$album->summary;


            $tmp['links'] = array();
            foreach($album->link as $idx2 => $link) {
                $key = (string)$link->attributes()->rel[0];
                $val = (string)$link->attributes()->href[0];
                $tmp['links'][$key] = $val;

                if($key == 'album') {
                    preg_match('~.*users\/.*\/album\/([0-9]+)\/~isU', $val, $matches);
                    if($matches && isset($matches[1])) {
                        $tmp['parent'] = array(
                            'link' => $val,
                            'id' => $matches[1]
                        );
                    }
                }
            }
            $tmp['updated']   = strtotime($album->updated);
            $tmp['published'] = strtotime($album->published);

            if(isset($tmp['links']['cover'])) {
                preg_match('~.*users\/(.*)\/photo~isU', $tmp['links']['cover'], $matches);
                if(isset($matches[1]) && strtolower($matches[1]) == 'none') {

                    $tmp['links']['cover'] = preg_replace('~users\/(.*)\/photo~isU',
                                                          'users/'. strtolower($this->userName).'/photo',
                                                          $tmp['links']['cover']);
                }

                $cover = $this->getPictureByUrl($tmp['links']['cover']);
                if(!empty($cover))
                    $tmp['cover'] = $cover;
            }

            $data[$id] = $tmp;
        }
        return $data;
    }


    public function getPictureByUrl($url) {
        $data = array();
        $sXML = $this->_getXmlObjectFromUrl($url);
        $data = $this->_extractPictureFromXMLNode($sXML);
        return $data;
    }

    public function getPicturesByUrl($url, $limit = false) {
        $data = array();
        if($limit)
            $url .= '?limit='. $limit;

        $sXML = $this->_getXmlObjectFromUrl($url);
        foreach($sXML->entry as $idx => $entry) {
            $tmp = $this->_extractPictureFromXMLNode($entry);

            if(isset($tmp['id']))
                $data[$tmp['id']] = $tmp;
        }
        return $data;
    }


    public function getTags() {
        if(!isset($this->collectionUrls['tag-list']))
            return false;

        $data = array();
        $url = $this->collectionUrls['tag-list'];
        $sXML = $this->_getXmlObjectFromUrl($url);

        foreach($sXML->entry as $idx => $entry) {
            $tmp = array();
            $tmp['title'] = (string)$entry->title;
            if(isset($entry->link)) {
                $tmp['links'] = array();
                foreach($entry->link as $idx2 => $link) {
                    $attrs = $link->attributes();
                    $rel        = (string)$attrs->rel[0];
                    $tmp['links'][$rel] = (string)$attrs->href[0];
                }
            }

            $tagName = 'f_image-count';
            if(isset($entry->$tagName)) {
                $count = $entry->$tagName->attributes();
                $tmp['count'] = (string)$count->value[0];
            }
            $tmp['updated'] = strtotime($entry->updated);

            $data[$tmp['title']] = $tmp;
        }

        return $data;
    }


    // some wrappers to call from page rendering controllers

    /**
     *
     * @param string $id
     * @return array | boolean
     *
     * Get album by it's id. Because there is no API query to get certain album info from YaFotki
     * we have to get the information about all albums and then return an element of the array.
     *
     */

    public function wrpGetAlbumById($id) {
        $albums = $this->getAlbums();
        if($albums && isset($albums[$id]))
            return $albums[$id];
        else
            return false;
    }


    /**
     *
     * @param string $id
     * @param string $sortDirection
     * @return array | boolean
     *
     * Get all subalbums by their parent album's id.
     *
     */

    public function wrpGetAlbumsParentId($id = null, $sortDirection = 'ASC') {
        $allAlbums = $this->getAlbums();
        if(!$id)
            return $allAlbums;

        $albums = array();
        foreach($allAlbums as $albumId => $album) {
            if(isset($album['parent']) && isset($album['parent']['id']) && $album['parent']['id'] == $id) {
                $albums[$albumId] = $album;
            }
        }

        if(!empty($albums)) {
            uasort($albums, function($a, $b) {
                return $a['published'] > $b['published'] ? 1 : 0;
            });
            if($sortDirection == 'DESC')
                $albums = array_reverse($albums);
        }

        return empty($albums) ? false : $albums;
    }

    /**
     *
     * @param string $id
     * @return array | boolean
     *
     * Get photo by it's id (if we don't know the album's id).
     *
     */

    public function wrpGetPhotoByIdFromAll($id) {
        if(!isset($this->collectionUrls['photo-list']))
            return false;


        $photos = $this->getPicturesByUrl($this->collectionUrls['photo-list']);
        if($photos && !empty($photos) && isset($photos[$id]))
            return $photos[$id];
        else
            return false;
    }

    /**
     *
     * @param string $albumId
     * @param string $id
     * @param boolean $includeAlbum
     * @return array | boolean
     *
     * Get photo by id if we know album's id and include/not include the album information.
     *
     */

    public function wrpGetPhotoByIdFromAlbum($albumId, $id, $includeAlbum = true) {
        $pictures = $this->wrpGetPhotosByAlbumId($albumId, $includeAlbum);
        if(!$pictures)
            return false;

        if($pictures['pictures'] && isset($pictures['pictures'][$id])) {
            return array('album' => $pictures['album'],
                         'picture' => $pictures['pictures'][$id],
                         'next' => $this->_getNextPrevElementByKey($pictures['pictures'], $id, 'next'),
                         'prev' => $this->_getNextPrevElementByKey($pictures['pictures'], $id, 'prev')
                        );
        } elseif($pictures[$id])
            return $pictures[$id];
        else
            return false;
    }

    /**
     *
     * @param string $name
     * @return array | boolean
     *
     * Get certain tag's info
     *
     */

    public function wrpGetTagByName($name) {
        $tags = $this->getTags();
        if($tags && isset($tags[$name]))
            return $tags[$name];
        else
            return false;
    }


    /**
     *
     * @param string $id
     * @param boolean $includeAlbum
     * @param int $limit
     * @return array | boolean
     *
     * Get all photos in certain album and include/not include album's info
     *
     */

    public function wrpGetPhotosByAlbumId($id, $includeAlbum = true, $limit = false) {
        $album = $this->wrpGetAlbumById($id);
        if(!$album)
            return false;

        $url = $album['links']['photos'];

        $photos = $this->getPicturesByUrl($url, $limit);
        if($photos && !empty($photos))
            return $includeAlbum ? array('album' => $album, 'pictures' => $photos) : $photos;
        else
            return false;
    }

    /**
     *
     * @param string $url
     * @param int $limit
     * @return array | boolean
     *
     * Get photo's by direct API url call
     *
     */

    public function wrpGetPhotosByUrl($url, $limit = false) {
        $photos = $this->getPicturesByUrl($url, $limit);
        if($photos && !empty($photos))
            return $photos;
        else
            return false;
    }

    /**
     *
     * @param string $tagName
     * @return array | boolean
     *
     * Get photos by tag name
     *
     */

    public function wrpGetPhotosByTagName($tagName) {
        $tag = $this->wrpGetTagByName($tagName);
        if(!$tag)
            return false;

        $url = $tag['links']['photos'];

        $photos = $this->getPicturesByUrl($url);
        if($photos && !empty($photos))
            return $photos;
        else
            return false;

    }
}