<?php

/*
 * This file is part of the JYmusic/filedownloader.
 *
 * (c) JYmusic<zhangcb1984@163.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace JYmusic\Downloader;

/**
 * Class SocialiteManager.
 */
class Downloader
{

    /**
     * Constants for Download Modes
     */
    const DOWNLOAD_FILE = 1;
    const DOWNLOAD_DATA = 2;

    protected $bufferSize = 2048;

    protected $autoExit = false;

    /**
     * Downloader constructor
     *
     * Constructor will prepare data file and calculate start byte and end byte
     *
     * @param string  $toDownload    file path or data string
     * @param integer $downloadMode  file mode or data mode
     */
    public function __construct($toDownload, $downloadMode = self::DOWNLOAD_FILE)
    {
        global $HTTP_SERVER_VARS;

        $this->_initialize();
        if ($download_mode == self::DOWNLOAD_FILE) {
            // Download by file path
            $this->downloadMode = $downloadMode;
            // Check if File exists and is file or not
            if (!is_file($to_download)) {

            }
        } else {

        }
    }

    /**
     * Initialization
     *
     * Set of code performed immediately before calling download method
     *
     * @access private
     * @return null
     */
    private function _initialize()
    {
        // Initializing code goes here
        // allow for sending partial contents to browser, so turn off compression on the server and php config

        // Disables apache compression mod_deflate || mod_gzip
        @apache_setenv('no-gzip', 1);
        // disable php cpmpression
        @ini_set('zlib.output_compression', 'Off');
    }
}
