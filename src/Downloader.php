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

class Downloader
{

    use HttpErrorResponseTrait;

    /**
     * Constants for Download Modes
     */
    const DOWNLOAD_FILE = 1;
    const DOWNLOADdata  = 2;

    protected $data;

    protected $filename;
    protected $fileBasename;
    protected $fileExtension;

    protected $mime;
    protected $extensionsmimeArr;

    protected $lastModifiedTime;

    protected $fullSize;
    protected $requiredDownloadSize;
    protected $downloaded = 0;

    protected $seekStart = 0;
    protected $seekEnd;

    protected $isPartial;
    protected $isResumable = true;

    protected $speedLimit;

    protected $bufferSize = 2048;

    protected $autoExit = false;

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
     * @param string  $to_download    file path or data string
     * @param integer $download_mode  file mode or data mode
     */
    public function __construct($to_download, $download_mode = self::DOWNLOAD_FILE)
    {
        global $HTTP_SERVER_VARS;
        $this->_initialize();

        if ($download_mode == self::DOWNLOAD_FILE) {
            // Download by file path
            $this->_download_mode = $download_mode;
            // Check if File exists and is file or not
            if (!is_file($to_download)) {
                // Not Found
                // $this->_setHeader( 'HTTP/1.0 404 File Not Found' );
                // exit();
                $this->httpError(404, 'File Not Found');
            } else if (!is_readable($to_download) || !($this->data = fopen($to_download, 'rb'))) {
                // File is not readable, couldnot open
                // $this->_setHeader( 'HTTP/1.0 403 Forbidden File Not Accissible.' );
                // exit();
                $this->httpError(403, 'File Not Accissible');
            }

            $this->fullSize = filesize($to_download);
            $info = pathinfo($to_download);
            $this->filename      = $info['filename'];
            $this->fileBasename  = $info['basename'];
            $this->fileExtension = $info['extension'];
            $this->mime = $this->_getMimeOf($this->fileExtension);
            $this->lastModifiedTime = filemtime($to_download);

        } else if ($download_mode == self::DOWNLOADdata) {
            // Download By Data String
            $this->_download_mode = $download_mode;
            if (is_file($to_download)) {
                // the given is a file so we will get it as string data
                $this->data = file_get_contents($to_download);
                // $this->data = implode( '', file( $to_download ) );
                $info = pathinfo($to_download);
                $this->filename = $info['filename'];
                $this->_basename = $info['basename'];
                $this->fileExtension = $info['extension'];

            } else {
                // The give data may be binary data or basic string or whatever in string formate
                // so we will assume by default that the given string is basic txt file
                // you can change this behaviour via setDownloadName() method and pass to it file basename
                $this->data = $to_download;
                $this->filename = 'file';
                $this->fileExtension = 'txt';
                $this->_basename = $this->filename . '.' . $this->fileExtension;
            }
            $this->fullSize = strlen($this->data);
            $this->mime = $this->_getMimeOf($this->fileExtension);
            $this->lastModifiedTime = time();
        } else {
            // Bad Request
            // $this->_setHeader( 'HTTP/1.0 400 Bad Request Download Mode Error' );
            // exit();
            $this->httpError(400, 'Bad Request, Undefined Download Mode');
        }

        // Range
        if (isset($_SERVER['HTTP_RANGE']) || isset($HTTP_SERVER_VARS['HTTP_RANGE'])) {

            // Partial Download Request, for Resumable
            $this->isPartial = true;
            $http_range = isset($_SERVER['HTTP_RANGE']) ? $_SERVER['HTTP_RANGE'] : $HTTP_SERVER_VARS['HTTP_RANGE'];

            if (stripos('bytes') === false) {
                // Bad Request for range
                // $this->_setHeader( 'HTTP/1.0 416 Requested Range Not Satisfiable' );
                // exit();
                $this->httpError(416);
            }

            $range = substr($http_range, strlen('bytes='));
            // $range = str_replace( 'bytes=', '', $http_range );
            $range = explode('-', $range, 3);

            // full_size = 100byte
            // range = bytes=0-99
            // seek_start = 0, seek_end = 99

            // Set Seek
            // Let Keep Default behaviour to be resumable, later immediately before downloading
            // we will check if resumability is turned off we will ovverride the comming three lines to be non resumable
            $this->seekStart = ($range[0] > 0 && $range[0] < $this->fullSize - 1) ? $range[0] : 0;
            $this->seekEnd = ($range[1] > 0 && $range[1] < $this->fullSize && $range[1] > $this->seekStart) ? $range[1] : $this->fullSize - 1;
            $this->requiredDownloadSize = $this->seekEnd - $this->seekStart + 1;
        } else {
            // Full File Download Request
            $this->isPartial = false;
            $this->seekStart = 0;
            $this->seekEnd = $this->fullSize - 1;
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
            if (!$this->_authenticate()) {
                // Authenticate Headers, this Will Popup authentication process then redirect back to the same request with provided username, password
                $this->_setHeader('WWW-Authenticate', 'Basic realm="This Process Require authentication, please provide your Credentials."');
                $this->_setHeader('HTTP/1.0 401 Unauthorized');
                $this->_setHeader('Status', '401 Unauthorized');
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
            if ($this->isResumable) {
                // Allow to resume
                // Resume Headers >>>
                $this->_setHeader('HTTP/1.0 206 Partial Content');
                $this->_setHeader('Status', '206 Partial Content');
                $this->_setHeader('Accept-Ranges', 'bytes');
                $this->_setHeader('Content-range', 'bytes ' . $this->seekStart . '-' . $this->seekEnd . '/' . $this->fullSize);
            } else {
                // Turn off resume capability
                $this->seekStart = 0;
                $this->seekEnd = $this->fullSize - 1;
                $this->requiredDownloadSize = $this->fullSize;
            }
        }

        // Commom Download Headers content type, content disposition, content length and Last Modified Goes Here >>>
        $this->_setHeader('Content-Type', $this->mime);
        $this->_setHeader('Content-Disposition', 'attachment; filename=' . $this->fileBasename);
        $this->_setHeader('Content-Length', $this->requiredDownloadSize);
        $this->_setHeader('Last-Modified', date('D, d M Y H:i:s \G\M\T', $this->lastModifiedTime));
        
        // Work On Download Speed Limit
        if ($this->speedLimit) {
            // how many buffers ticks per second
            $buf_per_second = 10; //10
            // how long one buffering tick takes by micro second
            $buf_micro_time = 150; // 100
            // Calculate sleep micro time after each tick
            $sleep_micro_time = round((1000000 - ($buf_per_second * $buf_micro_time)) / $buf_per_second);
            // Calculate required buffer per one tick, make sure it is integer so round the result
            $this->bufferSize = round($this->speedLimit * 1024 / $buf_per_second);
        }

        // Immediatly Before Downloading
        // clean any output buffer
        @ob_end_clean();
        // get oignore_user_abort value, then change it to yes
        $old_user_abort_setting = ignore_user_abort();
        ignore_user_abort(true);
        // set script execution time to be unlimited
        @set_time_limit(0);

        // Download According Download Mode
        if ($this->_download_mode == self::DOWNLOAD_FILE) {
            // Download Data by fopen
            $bytes_to_download = $this->requiredDownloadSize;
            $downloaded = 0;
            // goto the position of the first byte to download
            fseek($this->data, $this->seekStart);

            while ($bytes_to_download > 0 && !(connection_aborted() || connection_status() == 1)) {
                // still Downloading
                if ($bytes_to_download > $this->bufferSize) {
                    // send buffer size
                    echo fread($this->data, $this->bufferSize); // this also will seek to after last read byte
                    $downloaded += $this->bufferSize; // updated downloaded
                    $bytes_to_download -= $this->bufferSize; // update remaining bytes
                } else {
                    // send required size
                    // this will happens when we reaches the end of the file normally we wll download remaining bytes
                    echo fread($this->data, $bytes_to_download); // this also will seek to last reat
                    $downloaded += $bytes_to_download; // Add to downloaded
                    $bytes_to_download = 0; // Here last bytes have been written
                }
                // send to buffer
                flush();
                // Check For Download Limit
                if ($this->speedLimit) {
                    usleep($sleep_micro_time);
                }
            }
            // all bytes have been sent to user
            // Close File
            fclose($this->data);
        } else {
            // Download Data String
            $bytes_to_download = $this->requiredDownloadSize;
            $downloaded = 0;
            $offset = $this->seekStart;

            while ($bytes_to_download > 0 && (!connection_aborted())) {
                if ($bytes_to_download > $this->bufferSize) {
                    // Download by buffer
                    echo mb_strcut($this->data, $offset, $this->bufferSize);
                    $bytes_to_download -= $this->bufferSize;
                    $downloaded += $this->bufferSize;
                    $offset += $this->bufferSize;
                } else {
                    // download last bytes
                    echo mb_strcut($this->data, $offset, $bytes_to_download);
                    $downloaded += $bytes_to_download;
                    $offset += $bytes_to_download;
                    $bytes_to_download = 0;
                }
                // Send Data to Buffer
                flush();
                // Check Limit
                if ($this->speedLimit) {
                    usleep($sleep_micro_time);
                }
            }
        }

        // Set Downloaded Bytes
        $this->downloaded = $downloaded;
        ignore_user_abort($old_user_abort_setting); // Restore old user abort settings
        set_time_limit(ini_get('max_execution_time')); // Restore Default script max execution Time

        // Check if to record downloaded bytes
        if ($this->_recorddownloaded) {
            $this->_recordDownloaded();
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
     * @param string $file_basename name to be downloaded with
     */
    public function setDownloadName($file_basename = null)
    {
        if ($file_basename) {
            if (preg_match('/(?P<name>.+?)(\.(?P<ext>.+))?$/', $file_basename, $matches)) {
                // Set filename and extension
                $this->filename = $matches['name'];
                $this->fileExtension = (@$matches['ext'] && $this->_download_mode == self::DOWNLOADdata) ? $matches['ext'] : $this->fileExtension;
            }
            $this->fileBasename = $this->filename . '.' . $this->fileExtension;
            $this->mime = $this->_getMimeOf($this->fileExtension);
        }
        return $this;
    }

    /**
     * Set download resume capability
     *
     * @param  boolean $resumable resumable or not
     * @return class              current instance
     */
    public function resumable($resumable = true)
    {
        $this->isResumable = (bool) $resumable;
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
    public function speedLimit($limit)
    {
        $limit = intval($limit);
        $this->speedLimit = $limit;
        return $this;
    }

    /**
     * Set script auto exit after download process completed
     *
     * @param  boolean $val auto exit or not
     * @return class        current instance
     */
    public function autoExit($val = true)
    {
        $this->autoExit = (bool) $val;
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
    public function authenticate($username_or_callback, $password = null)
    {
        // Via Callback
        if (is_callable($username_or_callback)) {
            $this->authCallback = $username_or_callback;
        } else if (strlen($username_or_callback) == 0 || strlen($password) == 0) {
            //  Error
            // throw new Exception( 'authenticate() requires one argument to be a callback function or two arguments to be username, password respectively.' );
            header_remove(); // remove pre sent headers
            $this->_setHeader('HTTP/1.0 400 Bad Request Authentication Syntax Error');
            exit();
        } else {
            // Built in basic authentication
            $this->authUsername = $username_or_callback;
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
    public function recordDownloaded($use_or_callback = true)
    {
        if (is_callable($use_or_callback)) {
            // Record Via Callback
            $this->recordDownloadedCallback = $use_or_callback;
            $this->_recorddownloaded = true;
        } else {
            $this->_recorddownloaded = (bool) $use_or_callback;
        }
        return $this;
    }
    
    /**
     * Initialization
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

    /**
     * Get file mime type
     *
     * This method return mime type of given extension
     *
     * @param  string $extension extension
     * @return string            mime type
     */
    private function _getMimeOf($extension)
    {
        $mimeTypeHelper = Mimetypes::getInstance();
        $mimeType       = $mimeTypeHelper->fromExtension($extension);
        return !is_null($mimeType) ? $mimeType : "application/octet-stream";
    }

    /**
     * Set header
     *
     * @access private
     * @param  string $key   header key
     * @param  string $value header value
     * @return null
     */
    private function _setHeader($key, $value = null)
    {
        // one value header
        if (!$value) {
            header($key);
        } else {
            header($key . ': ' . $value);
        }
    }

    /**
     * Perform authentication process
     *
     * @access private
     * @return boolean represent authentication success or failed
     */
    private function _authenticate()
    {
        // Perform Authentication
        $username = @$_SERVER['PHP_AUTH_USER'];
        $password = @$_SERVER['PHP_AUTH_PW'];

        if (!isset($username)) {
            return false;
        } else if ($this->authCallback) {
            return call_user_func($this->authCallback, $username, $password);
        } else {
            // Built in Authentication
            return ($username === $this->authUsername && $password === $this->authPassword) ? true : false;
        }
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
    private function _recordDownloaded()
    {
        // Via Callback
        if ($this->recordDownloadedCallback) {
            call_user_func($this->recordDownloadedCallback, $this->_downloaded, $this->fileBasename);
        } else {
            // Default Recorder
            $file = __DIR__ . DIRECTORY_SEPARATOR . 'total_downloaded_bytes.txt';
            $bandwidth = intval(@file_get_contents($file)) + $this->_downloaded;
            file_put_contents($file, $bandwidth);
        }
    }
}
