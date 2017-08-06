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

use JYmusic\Downloader\HttpErrorResponseTrait;
use Skyzyx\Components\Mimetypes\Mimetypes;

/**
 * Class Downloader.
 */
class Downloader
{

    use HttpErrorResponseTrait;
    /**
     * Constants for Download Modes
     */
    const DOWNLOAD_FILE = 1;
    const DOWNLOADdata = 2;

    protected $data;
    protected $filename;
    protected $fileBasename;
    protected $fileExtension;

    protected $mime;
    protected $fullSize
    protected $lastModifiedTime;

    protected $fullSize
    protected $requiredDownloadSize;
    protected $downloaded = 0;

    protected $seekStart = 0;
    protected $seekEnd;

    protected $isPartial;
    protected $isResumable = true;

    protected $speedLimit;
    protected $bufferSize = 2048;
    protected $autoExit   = false;

    protected $downloadMode;

    protected $useAuthentiaction = false;
    protected $authUsername;
    protected $authPassword;
    protected $authCallback;

    protected $recordDownloaded;
    protected $recordDownloadedCallback;

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
            if (!is_file($toDownload)) {
                $this->httpError(404, 'File Not Found');
            } else if (
                !is_readable($toDownload) || !($this->data = fopen($toDownload, 'rb'))
            ) {
                // File is not readable, couldnot open
                $this->httpError(403, 'File Not Accissible');
            }
            $this->fullSize         = filesize($toDownload);
            $info                   = pathinfo($to_download);
            $this->filename         = $info['filename'];
            $this->fileBasename     = $info['basename'];
            $this->fileExtension    = $info['extension'];
            $this->mime             = $this->_getMimeOf($this->fileExtension);
            $this->lastModifiedTime = filemtime($toDownload);
        } else if ($download_mode == self::DOWNLOADdata) {
            $this->downloadMode = $downloadMode;
            if (is_file($toDownload)) {
                // the given is a file so we will get it as string data
                $this->data          = file_get_contents($toDownload);
                $info                = pathinfo($toDownload);
                $this->filename      = $info['filename'];
                $this->fileBasename  = $info['basename'];
                $this->fileExtension = $info['extension'];

            } else {
                // The give data may be binary data or basic string or whatever in string formate
                // so we will assume by default that the given string is basic txt file
                // you can change this behaviour via setDownloadName() method and pass to it file basename
                $this->data          = $to_download;
                $this->filename      = 'file';
                $this->fileExtension = 'txt';
                $this->fileBasename  = $this->filename . '.' . $this->fileExtension;
            }
            $this->fullSize         = strlen($this->data);
            $this->mime             = $this->getMimeOf($this->fileExtension);
            $this->lastModifiedTime = time();
        } else {
            $this->httpError(400, 'Bad Request, Undefined Download Mode');
        }

        // Range
        if (isset($_SERVER['HTTP_RANGE']) || isset($HTTP_SERVER_VARS['HTTP_RANGE'])) {
            // Partial Download Request, for Resumable
            $this->isPartial = true;
            $httpRange       = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : $HTTP_SERVER_VARS['HTTP_RANGE'];

            if (stripos('bytes') === false) {
                // Bad Request for range
                // $this->setHeader( 'HTTP/1.0 416 Requested Range Not Satisfiable' );
                // exit();
                $this->httpError(416);
            }
            $range = substr($httpRange, strlen('bytes='));
            $range = explode('-', $range, 3);
            // fullSize = 100byte
            // range = bytes=0-99
            // seekStart = 0, seekEnd = 99
            // Set Seek
            // Let Keep Default behaviour to be resumable, later immediately before downloading
            // we will check if resumability is turned off we will ovverride the comming three lines to be non resumable
            $this->seekStart            = ($range[0] > 0 && $range[0] < $this->fullSize - 1) ? $range[0] : 0;
            $this->seekEnd              = ($range[1] > 0 && $range[1] < $this->fullSize && $range[1] > $this->seekStart) ? $range[1] : $this->fullSize - 1;
            $this->requiredDownloadSize = $this->seekEnd - $this->seekStart + 1;
        } else {
            // Full File Download Request
            $this->isPartial            = false;
            $this->seekStart            = 0;
            $this->seekEnd              = $this->fullSize - 1;
            $this->requiredDownloadSize = $this->fullSize;
        }
    }

    /**
     * Start download process
     *
     * @return  null
     */
    public function download()
    {
        // Actual Download Steps
        // Check If Authentication Required
        if ($this->useAuthentiaction) {
            // Try To Use Basic WWW-Authenticate
            if (!$this->authenticate()) {
                // Authenticate Headers, this Will Popup authentication process then redirect back to the same request with provided username, password

                $this->setHeader('WWW-Authenticate', 'Basic realm="This Process Require authentication, please provide your Credentials."');
                $this->setHeader('HTTP/1.0 401 Unauthorized');
                $this->setHeader('Status', '401 Unauthorized');
                // Exit if auto exit is enabled
                if ($this->autoExit) {
                    exit();
                }
                return false; // Making sure That script stops here
            }
        }

        // check resumability, Headers Stage
        if ($this->isPartial) {
            // Resumable Request
            if ($this->isPesumable) {
                // Allow to resume
                // Resume Headers >>>
                $this->setHeader('HTTP/1.0 206 Partial Content');
                $this->setHeader('Status', '206 Partial Content');
                $this->setHeader('Accept-Ranges', 'bytes');
                $this->setHeader('Content-range', 'bytes ' . $this->seekStart . '-' . $this->seekEnd . '/' . $this->fullSize);
            } else {
                // Turn off resume capability
                $this->seekStart             = 0;
                $this->seekEnd               = $this->fullSize - 1;
                $this->requiredDownloadSize = $this->fullSize;
            }
        }

        // Commom Download Headers content type, content disposition, content length and Last Modified Goes Here >>>
        $this->setHeader('Content-Type', $this->mime);
        $this->setHeader('Content-Disposition', 'attachment; filename=' . $this->fileBasename);
        $this->setHeader('Content-Length', $this->requiredDownloadSize);
        $this->setHeader('Last-Modified', date('D, d M Y H:i:s \G\M\T', $this->_last_modified_time));
        // End Headers Stage

        // Work On Download Speed Limit
        if ($this->speedLimit) {
            // how many buffers ticks per second
            $bufPerSecond = 10; //10
            // how long one buffering tick takes by micro second
            $bufMicroTime = 150; // 100
            // Calculate sleep micro time after each tick
            $sleepMicroTime = round((1000000 - ($bufPerSecond * $bufMicroTime)) / $bufPerSecond);
            // Calculate required buffer per one tick, make sure it is integer so round the result
            $this->buffersize = round($this->speedLimit * 1024 / $bufPerSecond);
        }
        // Immediatly Before Downloading
        // clean any output buffer
        @ob_end_clean();

        // get oignore_user_abort value, then change it to yes
        $oldUserAbortSetting = ignore_user_abort();
        ignore_user_abort(true);
        // set script execution time to be unlimited
        @set_time_limit(0);

        // Download According Download Mode

        if ($this->downloadMode == self::DOWNLOAD_FILE) {
            // Download Data by fopen
            $bytesToDownload = $this->requiredDownloadSize;
            $downloaded        = 0;
            // goto the position of the first byte to download
            fseek($this->data, $this->seekStart);
            while ($bytesToDownload > 0 && !(connection_aborted() || connection_status() == 1)) {
                // still Downloading
                if ($bytesToDownload > $this->buffersize) {
                    // send buffer size
                    echo fread($this->data, $this->buffersize); // this also will seek to after last read byte
                    $downloaded += $this->buffersize; // updated downloaded
                    $bytesToDownload -= $this->buffersize; // update remaining bytes
                } else {
                    // send required size
                    // this will happens when we reaches the end of the file normally we wll download remaining bytes
                    echo fread($this->data, $bytesToDownload); // this also will seek to last reat

                    $downloaded += $bytesToDownload; // Add to downloaded

                    $bytesToDownload = 0; // Here last bytes have been written
                }
                // send to buffer
                flush();
                // Check For Download Limit
                if ($this->speedLimit) {
                    usleep($sleepMicroTime);
                }

            }
            // all bytes have been sent to user
            // Close File
            fclose($this->data);
        } else {
            // Download Data String
            $bytesToDownload = $this->requiredDownloadSize;

            $downloaded = 0;
            $offset     = $this->seekStart;
            while ($bytesToDownload > 0 && (!connection_aborted())) {
                if ($bytesToDownload > $this->buffersize) {
                    // Download by buffer
                    echo mb_strcut($this->data, $offset, $this->buffersize);
                    $bytesToDownload -= $this->buffersize;
                    $downloaded += $this->buffersize;
                    $offset += $this->buffersize;
                } else {
                    // download last bytes
                    echo mb_strcut($this->data, $offset, $bytesToDownload);
                    $downloaded += $bytesToDownload;
                    $offset += $bytesToDownload;
                    $bytesToDownload = 0;
                }
                // Send Data to Buffer
                flush();
                // Check Limit
                if ($this->speedLimit) {
                    usleep($sleepMicroTime);
                }

            }
        }
        // Set Downloaded Bytes
        $this->_downloaded = $downloaded;
        ignore_user_abort($oldUserAbortSetting); // Restore old user abort settings
        set_time_limit(ini_get('max_execution_time')); // Restore Default script max execution Time

        // Check if to record downloaded bytes
        if ($this->recordDownloaded) {
            $this->setRecordDownloaded();
        }

        if ($this->autoExit) {
            exit();
        }
    }

    /**
     * Force download process
     *
     * @return  null
     */
    public function forceDownload()
    {
        // Force mime
        $this->mime = 'Application/octet-stream';
        $this->download();
    }

    /**
     * Change file downloading name
     *
     * This method will download file with given name
     * if the given download name is a basename 'including extension'
     * then note that while download mode is file download,
     * file extension and also mime type will not be changed
     * and if downloade mode is data download,
     * file extension and also mime type will changed
     *
     * @param string $fileBasename name to be downloaded with
     */
    public function setDownloadName($fileBasename = null)
    {
        if ($fileBasename) {
            if (preg_match('/(?P<name>.+?)(\.(?P<ext>.+))?$/', $fileBasename, $matches)) {
                // Set filename and extension
                $this->filename = $matches['name'];

                $this->fileExtension = (@$matches['ext'] && $this->downloadMode == self::DOWNLOADdata) ? $matches['ext'] : $this->fileExtension ;

            }
            $this->fileBasename = $this->filename . '.' . $this->fileExtension ;
            $this->mime = $this->getMimeOf($this->fileExtension );
        }
        return $this;
    }

    /**
     * Set download resume capability
     *
     * @param  boolean $resumable resumable or not
     * @return class              current instance
     */
    public function setFesumable($resumable = true)
    {
        $this->isResumable = (bool)$resumable;
        return $this;
    }

    /**
     * Set download speed limit 'KBytes/sec'
     *
     * Using download speed limit may be affects on download process, using sleep alots
     * may make script to exit on some hosts
     * i tested this method on local hosr server and it works perfectly on any limit
     * and test on areal host but on speed limit of 100 kBps it works but not every time
     * and for more slower more failure
     * so becarefull while using
     *
     * @param  integer $limit speed in KBps
     * @return class          current instance
     */
    public function setSpeedLimit($limit)
    {
        $this->speedLimit = ntval($limit);
        return $this;
    }

    /**
     * Set script auto exit after download process completed
     *
     * @param  boolean $val auto exit or not
     * @return class        current instance
     */
    public function setAutoExit($val = true)
    {
        $this->autoExit = (bool)$val;
        return $this;
    }

    /**
     * Download with authenticating
     *
     * Set download with authentication process using a built in handler
     * or using given callback handler
     *
     * @param  mixid  $username_or_callback username or authentication callback handler
     * @param  string $password             password to authenticate againest in built in authenticatinon handler
     * @return class                        current instance
     */
    public function authenticate($usernameOrCallback, $password = null)
    {
        if (is_callable($usernameOrCallback)) {
            $this->authCallback = $usernameOrCallback;
        } elseif (strlen($username_or_callback) == 0 || strlen($password) == 0) {
            //  Error
            // throw new Exception( 'authenticate() requires one argument to be a callback function or two arguments to be username, password respectively.' );
            header_remove(); // remove pre sent headers
            $this->setHeader('HTTP/1.0 400 Bad Request Authentication Syntax Error');
            exit();
        } else {
            // Built in basic authentication
            $this->authUsername = $usernameOrCallback;
            $this->authPassword = $password;
        }
        $this->useAuthentiaction = true;
        return $this;
    }

    /**
     * Record download process
     *
     * Set if to record download process or not
     * or set callback handler that perform recording process
     *
     * @param  mixid $use_or_callback record or not or record with callback handler
     * @return class                  current instance
     */
    public function recordDownloaded($useOrCallback = true)
    {
        if (is_callable($useOrCallback)) {
            // Record Via Callback
            $this->recordDownloadedCallback = $useOrCallback;
            $this->recordDownloaded         = true;
        } else {
            $this->recordDownloaded = (bool) $useOrCallback;
        }

        return $this;
    }

    /**
     * Get file mime type
     *
     * This method return mime type of given extension
     *
     * @param  string $extension extension
     * @return string            mime type
     */
    protected function getMimeOf($extension)
    {
        $mimeTypeHelper = Mimetypes::getInstance();
        $mimeType       = $mimeTypeHelper->fromExtension($extension);
        return !is_null($mimeType) ? $mimeType : "application/octet-stream";
    }

    /**
     * Set header
     *
     * @access protected
     * @param  string $key   header key
     * @param  string $value header value
     * @return null
     */
    protected function setHeader($key, $value = null)
    {
        if (!$value) {
            header($key);
        } else {
            header($key . ': ' . $value);
        }
    }

    /**
     * Perform authentication process
     *
     * @access protected
     * @return boolean represent authentication success or failed
     */
    protected function authenticate()
    {
        // Perform Authentication
        $username = @$_SERVER['PHP_AUTH_USER'];
        $password = @$_SERVER['PHP_AUTH_PW'];

        if (!isset($username)) {
            return false;
        }

        if ($this->authCallback) {
            // authenticate via callback
            return call_user_func($this->authCallback, $username, $password);
        }

        // Built in Authentication
        return ($username === $this->authUsername && $password === $this->authPassword) ? true : false;
    }

    /**
     * Write downloaded bytes to bandwidth file
     *
     * Record download process to a file
     * by default it will update 'total_downloaded_bytes.txt' file by adding downloaded bytes
     * or if a callback handler was supplied it will use it instead for recording process
     *
     * @access private
     * @return null
     */
    protected function setRecordDownloaded()
    {
        if ($this->recordDownloaded_callback) {
            call_user_func($this->recordDownloadedCallback, $this->downloaded, $this->fileBasename);
        } else {
            // Default Recorder
            $file      = __DIR__ . DIRECTORY_SEPARATOR . 'total_downloaded_bytes.txt';
            $bandwidth = intval(@file_get_contents($file)) + $this->downloaded;
            file_put_contents($file, $bandwidth);
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
