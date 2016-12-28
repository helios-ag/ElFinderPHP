<?php

namespace FM\ElFinderPHP\Driver;

use Aws\S3\Enum\CannedAcl;
use Aws\S3\Exception\NoSuchKeyException;
use Aws\S3\S3Client;
use FM\ElFinderPHP\ElFinder;

/**
 * @file
 *
 * elFinder driver for Amazon S3 (SOAP) filesystem.
 *
 * @author Dmitry (dio) Levashov,
 * @author Alexey Sukhotin
 * */
class ElFinderVolumeS3 extends ElFinderVolumeDriver {

    protected $driverId = 's3s';

    /**
     * @var S3Client
     */
    protected $s3;

    public function __construct() {
        $opts = array(
            'accesskey' => '',
            'secretkey' => '',
            'bucket'    => '',
        );
        $this->options = array_merge($this->options, $opts);
        $this->options['mimeDetect'] = 'internal';

    }

    protected function init() {
        if (!$this->options['accesskey']
            || !$this->options['secretkey']
            || !$this->options['signature']
            || !$this->options['region']
            ||  !$this->options['bucket']) {
            return $this->setError('Required options undefined.');
        }

        $this->s3 = S3Client::factory([
            'key' => $this->options['accesskey'],
            'secret' => $this->options['secretkey'],
            'signature' => $this->options['signature'],
            'region' => $this->options['region']
        ]);
        $this->s3->registerStreamWrapper();

        $this->root = $this->options['path'];

        $this->rootName = 's3';

        return true;
    }

    protected function configure() {
        parent::configure();
        $this->mimeDetect = 'internal';
    }

    /**
     * Return parent directory path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dirname($path) {

        $newpath =  preg_replace("/\/$/", "", $path);
        $dn = substr($path, 0, strrpos($newpath, '/')) ;

        if (substr($dn, 0, 1) != '/') {
            $dn = "/$dn";
        }

        return $dn;
    }

    /**
     * Return file name
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _basename($path) {
        return basename($path);
    }



    /**
     * Join dir name and file name and return full path.
     * Some drivers (db) use int as path - so we give to concat path to driver itself
     *
     * @param  string  $dir   dir path
     * @param  string  $name  file name
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _joinPath($dir, $name) {
        return $dir.DIRECTORY_SEPARATOR.$name;
    }

    /**
     * Return normalized path, this works the same as os.path.normpath() in Python
     *
     * @param  string  $path  path
     * @return string
     * @author Troex Nevelin
     **/
    protected function _normpath($path) {
        $tmp =  preg_replace("/^\//", "", $path);
        $tmp =  preg_replace("/\/\//", "/", $tmp);
        $tmp =  preg_replace("/\/$/", "", $tmp);
        return $tmp;
    }

    /**
     * Return file path related to root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _relpath($path) {


        $newpath = $path;


        if (substr($path, 0, 1) != '/') {
            $newpath = "/$newpath";
        }

        $newpath =  preg_replace("/\/$/", "", $newpath);

        $ret = ($newpath == $this->root) ? '' : substr($newpath, strlen($this->root)+1);

        return $ret;
    }

    /**
     * Convert path related to root dir into real path
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _abspath($path) {
        if ($path == $this->separator) {
            return $this->root;
        } else {
            $path = $this->root.$this->separator.$path;
            // Weird.. fixes "///" in paths.
            while (preg_match("/\/\//", $path)) {
                $path = preg_replace("/\/\//", "/", $path);
            }
            return $path;
        }
    }

    /**
     * Return fake path started from root dir
     *
     * @param  string  $path  file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _path($path) {
        return $this->rootName.($path == $this->root ? '' : $this->separator.$this->_relpath($path));
    }

    /**
     * Return true if $path is children of $parent
     *
     * @param  string  $path    path to check
     * @param  string  $parent  parent path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _inpath($path, $parent) {
        return $path == $parent || strpos($path, $parent.'/') === 0;
    }


    /**
     * Converting array of objects with name and value properties to
     * array[key] = value
     * @param  array  $metadata  source array
     * @return array
     * @author Alexey Sukhotin
     **/
    protected function metaobj2array($metadata) {
        $arr = array();

        if (is_array($metadata)) {
            foreach ($metadata as $meta) {
                $arr[$meta->Name] = $meta->Value;
            }
        } else {
            $arr[$metadata->Name] = $metadata->Value;
        }
        return $arr;
    }

    /**
     * Return stat for given path.
     * Stat contains following fields:
     * - (int)    size    file size in b. required
     * - (int)    ts      file modification time in unix time. required
     * - (string) mime    mimetype. required for folders, others - optionally
     * - (bool)   read    read permissions. required
     * - (bool)   write   write permissions. required
     * - (bool)   locked  is object locked. optionally
     * - (bool)   hidden  is object hidden. optionally
     * - (string) alias   for symlinks - link target path relative to root path. optionally
     * - (string) target  for symlinks - link target path. optionally
     *
     * If file does not exists - returns empty array or false.
     *
     * @param  string  $path    file path
     * @return array|false
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _stat($path) {

        $stat = array(
            'size' => 0,
            'ts' => time(),
            'read' => true,
            'write' => true,
            'locked' => false,
            'hidden' => false,
            'mime' => 'directory',
        );

        // S3 apparently doesn't understand paths Key with a "/" at the end
        if (substr($path, -1) == "/") {
            $path = substr($path, 0, strlen($path) - 1);
        }

        if ($this->root == $path) {
            return $stat;
        }


        $np = $this->_normpath($path);
        /* @var $obj \Guzzle\Service\Resource\Model */
        try {
            $obj = $this->s3->headObject(['Bucket' => $this->options['bucket'], 'Key' => $np]);
        } catch (NoSuchKeyException $e) {
        }

        if (!isset($obj)) {
            $np .= '/';
            try {
                $obj = $this->s3->headObject(['Bucket' => $this->options['bucket'], 'Key' => $np]);
            } catch (NoSuchKeyException $e) {
            }
        }

        // No obj means it's a folder, or it really doesn't exist
        if (!isset($obj)) {
            if (!$this->_scandir($path)) {
                return false;
            } else {
                return $stat;
            }
        }

        $mime = '';

        if ($obj->hasKey('Last-Modified')) {
            $stat['ts'] = strtotime($obj->get('Last-Modified'));
        }

        try {
            $files = $this->s3->listObjects(['Bucket' => $this->options['bucket'], 'Prefix' => $np, 'Delimiter' => '/'])->get('Contents');
        } catch (Exception $e) {

        }

        $mime = $obj->get('ContentType');
        $stat['mime'] = substr($np, -1) == '/' ? 'directory' : (!$mime ? 'text/plain' : $mime);
        foreach ($files as $file) {
            if ($file['Key'] == $np) {
                $stat['size'] = $file['Size'];
            }
        }

        return $stat;
    }



    /***************** file stat ********************/


    /**
     * Return true if path is dir and has at least one childs directory
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Alexey Sukhotin
     **/
    protected function _subdirs($path) {
        $stat = $this->_stat($path);

        if ($stat['mime'] == 'directory') {
            $files = $this->_scandir($path);
            foreach ($files as $file) {
                $fstat = $this->_stat($file);
                if ($fstat['mime'] == 'directory') {
                    return true;
                }
            }

        }

        return false;
    }

    /**
     * Return object width and height
     * Ususaly used for images, but can be realize for video etc...
     *
     * @param  string  $path  file path
     * @param  string  $mime  file mime type
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function _dimensions($path, $mime) {
        return false;
    }

    /******************** file/dir content *********************/

    /**
     * Return files list in directory
     *
     * @param  string  $path  dir path
     * @return array
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _scandir($path) {

        $s3path = preg_replace("/\/$/", "", $path);
        $s3path = preg_replace("/^\//", "", $s3path);

        $files = (array)$this->s3->listObjects(array('Bucket' => $this->options['bucket'], 'delimiter' => '/', 'Prefix' => $s3path))->get('Contents');

        $finalfiles = array();
        $folders = array();
        foreach ($files as $file) {
            if (preg_match("|^" . preg_replace("/^\//", "", $s3path) . '/' . "[^/]*/?$|", $file['Key'])) {
                $fname = $file['Key'];
                if (!$fname || $fname == preg_replace("/\/$/", "", $s3path) || $fname == preg_replace("/$/", "/", $s3path)) {
                    continue;
                }
                $finalfiles[] = preg_replace("/\/$/", "", $fname);
            } else {
                $matches = array();
                if ($res = preg_match("|^" . preg_replace("/^\//", "", $s3path) . '/' . "(.*?)\/|", $file['Key'], $matches)) {
                    $folders[$matches[1]] = true;
                }
            }
        }

        // Folders retrieved differently, as it's not a real object on S3
        foreach ($folders as $forlderName => $tmp) {
            if (!in_array(preg_replace("/^\//", "", $s3path)."/".$forlderName, $finalfiles)) {
                $finalfiles[] = preg_replace("/^\//", "", $s3path)."/".$forlderName;
            }
        }

        sort($finalfiles);
        return $finalfiles;
    }

    /**
     * Return temporary file path for required file
     *
     * @param  string  $path   file path
     * @return string
     * @author Dmitry (dio) Levashov
     **/
    protected function tmpname($path) {
        return $this->tmpPath.DIRECTORY_SEPARATOR.md5($path);
    }

    /**
     * Open file and return file pointer
     *
     * @param  string  $path  file path
     * @param  bool    $write open file for writing
     * @return resource|false
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _fopen($path, $mode="rb") {
        return fopen('s3://'.$this->options['bucket'].'/'.$this->_normpath($path), $mode);
    }

    /**
     * Close opened file
     *
     * @param  resource  $fp    file pointer
     * @param  string    $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _fclose($fp, $path='') {
        @fclose($fp);
        if ($path) {
            @unlink($this->tmpname($path));
        }
    }

    /********************  file/dir manipulations *************************/

    /**
     * Create dir and return created dir path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new directory name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _mkdir($path, $name) {

        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name/";

        try {
            mkdir('s3://'.$this->options['bucket'].'/'.$newkey);
            return "$path/$name";
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Create file and return it's path or false on failed
     *
     * @param  string  $path  parent dir path
     * @param string  $name  new file name
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _mkfile($path, $name) {
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        $newkey = "$newkey/$name";

        try {
            touch('s3://'.$this->options['bucket'].'/'.$newkey, null, null, stream_context_create([
                's3' => array('ACL' => CannedAcl::PUBLIC_READ)
            ]));
        } catch (Exception $e) {

        }

        if (isset($obj)) {
            return "$path/$name";
        }

        return false;

    }

    /**
     * Create symlink
     *
     * @param  string  $source     file to link to
     * @param  string  $targetDir  folder to create link in
     * @param  string  $name       symlink name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _symlink($source, $targetDir, $name) {
        return false;
    }

    /**
     * Copy file into another file (only inside one volume)
     *
     * @param  string  $source  source file path
     * @param  string  $target  target dir path
     * @param  string  $name    file name
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _copy($source, $targetDir, $name) {
        $sourcekey = $this->_normpath($source);
        $sourcekey = preg_replace("/\/$/", "", $sourcekey);
        $newkey = $this->_normpath($targetDir.'/'.$name);
        $newkey = preg_replace("/\/$/", "", $newkey);

        copy('s3://'.$this->options['bucket'].'/'.$sourcekey, 's3://'.$this->options['bucket'].'/'.$newkey, stream_context_create([
            's3' => ['ACL' => CannedAcl::PUBLIC_READ]
        ]));
        return true;
    }

    /**
     * Move file into another parent dir.
     * Return new file path or false.
     *
     * @param  string  $source  source file path
     * @param  string  $target  target dir path
     * @param  string  $name    file name
     * @return string|bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _move($source, $targetDir, $name) {
        $this->_copy($source, $targetDir, $name);
        $newkey = $this->_normpath($source);
        $newkey = preg_replace("/\/$/", "", $newkey);
        unlink('s3://'.$this->options['bucket'].'/'.$newkey);
    }

    /**
     * Remove file
     *
     * @param  string  $path  file path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _unlink($path) {

        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);

        try {
            $obj = $this->s3->deleteObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey));
            return true;
        } catch (Exception $e) {

        }
        return false;
    }

    /**
     * Remove dir
     *
     * @param  string  $path  dir path
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _rmdir($path) {
        $newkey = $this->_normpath($path).'/';

        try {
            $obj = $this->s3->deleteObject(array('Bucket' => $this->options['bucket'], 'Key' => $newkey));
            return true;
        } catch (Exception $e) {

        }
        return false;
    }

    /**
     * Create new file and write into it from file pointer.
     * Return new file path or false on error.
     *
     * @param  resource  $fp   file pointer
     * @param  string    $dir  target dir path
     * @param  string    $name file name
     * @return bool|string
     * @author Dmitry (dio) Levashov
     **/
    protected function _save($fp, $dir, $name, $stat) {
        $contents = stream_get_contents($fp);
        fclose($fp);
        $this->_filePutContents($dir.'/'.$name, $contents);
        return $dir.'/'.$name;
    }

    /**
     * Get file contents
     *
     * @param  string  $path  file path
     * @return string|false
     * @author Dmitry (dio) Levashov
     **/
    protected function _getContents($path) {
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);
        return file_get_contents('s3://'.$this->options['bucket'].'/'.$newkey);
    }

    /**
     * Write a string to a file
     *
     * @param  string  $path     file path
     * @param  string  $content  new file content
     * @return bool
     * @author Dmitry (dio) Levashov
     **/
    protected function _filePutContents($path, $content) {
        $newkey = $this->_normpath($path);
        $newkey = preg_replace("/\/$/", "", $newkey);

        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        $this->s3->putObject([
            'Bucket' => $this->options['bucket'],
            'Key' => $newkey,
            'Body' => $content,
            'ACL' => CannedAcl::PUBLIC_READ,
            'ContentType' => self::$mimetypes[$ext]
        ]);
        return true;
    }

    /**
     * Extract files from archive
     *
     * @param  string  $path file path
     * @param  array   $arc  archiver options
     * @return bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _extract($path, $arc) {
        return false;
    }

    /**
     * Create archive and return its path
     *
     * @param  string  $dir    target dir
     * @param  array   $files  files names list
     * @param  string  $name   archive name
     * @param  array   $arc    archiver options
     * @return string|bool
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _archive($dir, $files, $name, $arc) {
        return false;
    }

    /**
     * Detect available archivers
     *
     * @return void
     * @author Dmitry (dio) Levashov,
     * @author Alexey Sukhotin
     **/
    protected function _checkArchivers() {

    }

   /**
    * chmod implementation
    *
    * @return bool
    **/
    protected function _chmod($path, $mode) {
        return false;
    }
}

