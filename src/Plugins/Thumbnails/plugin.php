<?php

use Intervention\Image\ImageManager;

class elFinderPluginThumbnails {

    private $options = [];
    private $defaultOptions = [
        'enable' => true,
        'thumb_path' => '',
        'thumb' => ''
    ];

    public function __construct($options)
    {
        $this->options = array_merge( $this->defaultOptions, $options );
    }

    public function generateThumbs(&$path, &$name, $src, $elfinder, $volume)
    {

        $options = $this->pluginEnabled($volume);
        if($options == 'false')
        {
            return false;
        }

        $imgTypes = $this->mimeType($options, $src);
        if($imgTypes == 'false')
        {
            return false;
        }

        $thumbPath = $path.$options['thumb_path'];

        // create Dirs if not exist
        // todo: switch to function, create 5 folders, hide folders, check settings
        $this->createFolders($thumbPath);


        $manager = new ImageManager(array('driver' => 'gd'));
        $this->resize($src, $options, $manager, $thumbPath, $name);

    }

    protected function mimeType($opts, $src)
    {

        $srcImgInfo = @getimagesize( $src );
        if ( $srcImgInfo === false ) {
            return 'false';
        }

        switch ( $srcImgInfo[ 'mime' ] ) {
            case 'image/gif':
                break;
            case 'image/jpeg':
                break;
            case 'image/png':
                break;

            default:
                return 'false';
        }

    }
    private function pluginEnabled($volume)
    {
        $defaultOptions = $this->options;
        $configOptions = $volume->getOptionsPlugin('Thumbnails');

        if (is_array($configOptions)) {
            $options = array_merge($this->defaultOptions, $configOptions);
            $this->options = $options;
        }

        if (! $options['enable']) {
            return 'false';
        }

        return $options;
    }

    private function createFolders($thumbPath)
    {
        $thumbs = $this->options['thumb'];
        $thumbs = explode('|', $thumbs);

        foreach( $thumbs as $key => $value)
        {
            if($value != '')
            {
                if( ! is_dir( $thumbPath . '.thumb' . $key ))
                {
                    mkdir($thumbPath . '.thumb' . $key, 0777, true);
                }
            }
        }
    }

    private function resize($src, $options, $manager, $thumbPath, $name)
    {
        $thumbs = $this->options['thumb'];
        $thumbs = explode('|', $thumbs);

        foreach( $thumbs as $key => $value)
        {
            if($value != '')
            {
                // to finally create image instances
                $image = $manager->make( $src );
                // prevent possible upsizing
                $image->resize( $value , null, function( $constraint ) {
                    $constraint->aspectRatio();
                    $constraint->upsize();
                } );

                $image->save($thumbPath . '.thumb' . $key . '/' . $name);
            }
        }
    }
}
