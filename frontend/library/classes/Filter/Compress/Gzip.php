<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2018 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

namespace iMSCP\Filter\Compress;

/**
 * Class Gzip
 *
 * This class provides filter that allow to compress a string in GZIP file format.
 *
 * This filter can be used both for create a standard gz file, and as filter for the PHP ob_start() function.
 * This filter compresses the data by using the GZIP format specifications according the rfc 1952.
 * 
 * @package iMSCP\Filter\Compress
 */
class Gzip
{
    /**
     * Contains the filter method name
     *
     * @var string
     */
    const CALLBACK_NAME = 'filter';

    /**
     * Filter mode for the PHP ob_start function
     *
     * @var int
     */
    const FILTER_BUFFER = 0;

    /**
     * Filter mode for creation of standard gzip file
     *
     * @var int
     */
    const FILTER_FILE = 1;

    /**
     * Tells whether information about compression should be added as HTML comments
     *
     * It's not recommended to use it in production to avoid multiple compression work.
     *
     * <b>Note:</b>Not usable in {@link self::FILTER_FILE} mode
     *
     * @var boolean
     */
    public $compressionInformation = true;

    /**
     * Minimum compression level
     *
     * @var int
     */
    protected $minCompressionLevel = 0;

    /**
     * Maximum compression level
     *
     * @var int
     */
    protected $maxCompressionLevel = 9;

    /**
     * Compression level
     *
     * @var int
     */
    protected $compressionLevel = 1;

    /**
     * Accepted browser content-coding
     *
     * @var string
     */
    protected $browserAcceptedEncoding = '';

    /**
     * Data to be compressed
     *
     * @var string
     */
    protected $data = '';

    /**
     * Data size
     *
     * @var int
     */
    protected $dataSize = 0;

    /**
     * Gzip (encoded) Data size
     *
     * @var int
     */
    protected $gzipDataSize = 0;

    /**
     * Tells if the filter should act as callback function for the PHP ob_start
     * function or as simple filter for standard gz file creation
     *
     * @var int
     */
    protected $_mode;

    /**
     * Constructor
     *
     * @param int $mode Tells if the filter should act as callback function for the PHP ob_start function or as function for create a standard gz
     *                  file. The filter mode must be one of the Gzip::FILTER_* constants.
     * @param int $compressionLevel Compression level
     */
    public function __construct($mode = self::FILTER_FILE, $compressionLevel = 1)
    {
        if (extension_loaded('zlib')) {
            if ($mode === self::FILTER_BUFFER || $mode === self::FILTER_FILE) {
                $this->_mode = $mode;
            } else {
                throw new \InvalidArgumentException('Unknown filter mode!');
            }
        } else {
            throw new \RuntimeException('Zlib Compression library is not loaded.');
        }

        if (in_array(
            $compressionLevel,
            range($this->minCompressionLevel, $this->maxCompressionLevel))
        ) {
            $this->compressionLevel = $compressionLevel;
        } else {
            throw new \InvalidArgumentException('Wrong value for compression level.');
        }
    }

    /**
     * Gzip Filter
     *
     * This method can be used both for create standard gz files, and as filter for the ob_start() function to help facilitate sending gzip encoded
     * data to the clients browsers that support the gzip content-coding.
     *
     * According the PHP documentation, when used as filter for the ob_start() function, and if any error occurs, FALSE is returned and then, content
     * is sent to the client browser without compression. Note that FALSE is also returned when the data are already encoded.
     *
     * If used in {@link self::FILTER_FILE} mode and if the $filePath is not specified, the encoded string is returned instead of be written in a file.
     *
     * @param string $data Data to be compressed
     * @param string $filePath File path to be used for gz file creation]
     * @return string|bool Encoded string in gzip file format, FALSE on failure
     */
    public function filter($data, $filePath = '')
    {
        $this->data = $data;

        // Act as filter for the PHP ob_start function
        if ($this->_mode === self::FILTER_BUFFER) {
            if (ini_get('output_handler') != 'ob_gzhandler' && !ini_get('zlib.output_compression') && !headers_sent()
                && connection_status() == CONNECTION_NORMAL && $this->_getEncoding()
                && strcmp(substr($data, 0, 2), "\x1f\x8b")
            ) {

                if ($this->compressionInformation && !isXhr()) {
                    $statTime = microtime(true);
                    $gzipData = $this->_getEncodedData();
                    $time = round((microtime(true) - $statTime) * 1000, 2);
                    $this->gzipDataSize = strlen($gzipData);
                    $gzipData = $this->_addCompressionInformation($time);
                } else {
                    $gzipData = $this->_getEncodedData();
                    $this->gzipDataSize = strlen($gzipData);
                }

                // Send required headers
                $this->_sendHeaders();
            } else {
                return false;
            }

            // Create standard gz file
        } else {

            $gzipData = $this->_getEncodedData();

            if ($filePath != '' && $gzipData !== false) {
                $this->_writeFile($gzipData, $filePath);
            }
        }

        return $gzipData;
    }

    /**
     * Check and sets the acceptable content-coding for compression
     *
     * @return boolean TRUE if the client browser accept gzip content-coding as response, FALSE otherwise
     */
    protected function _getEncoding()
    {
        if (isset($_SERVER['HTTP_ACCEPT_ENCODING'])) {
            if (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'x-gzip') !== false) {
                $this->browserAcceptedEncoding = 'x-gzip';
            } elseif (strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false) {
                $this->browserAcceptedEncoding = 'gzip';
            } else {
                return false;
            }
        } else {
            return false;
        }

        return true;
    }

    /**
     * Encode data in Gzip file format
     *
     * @return string|bool Encoded string in gzip file format, FALSE on failure
     */
    protected function _getEncodedData()
    {

        return gzencode($this->data, $this->compressionLevel);
    }

    /**
     * Adds compression information as HTML comment
     *
     * Note: Only called when the filter is used as callback function of the PHP ob_start function.
     *
     * @param string $time Time for data compression
     * @return string|bool Encoded data in gzip file format, FALSE on failure
     */
    protected function _addCompressionInformation($time)
    {
        $dataSize = round(strlen($this->data) / 1024, 2);
        $gzipDataSize = round($this->gzipDataSize / 1024, 2);
        $savingkb = $dataSize - $gzipDataSize;
        $saving = ($dataSize > 0) ? round($savingkb / $dataSize * 100, 0) : 0;

        // Prepare compression Information
        $compressionInformation =
            "\n<!--\n" .
            "\tCompression level: {$this->compressionLevel}\n" .
            "\tOriginal size: $dataSize kb\n" .
            "\tNew size: $gzipDataSize kb\n" .
            "\tSaving: $savingkb kb ($saving %)\n" .
            "\tTime: $time ms\n" .
            "-->\n";

        $this->data .= $compressionInformation;
        $gzipData = $this->_getEncodedData();
        $this->gzipDataSize = strlen($gzipData);

        return $gzipData;
    }

    /**
     * Send headers
     *
     * Note: Only called when the filter is used as callback function of the PHP ob_start function.
     *
     * @return void
     */
    protected function _sendHeaders()
    {
        header("Content-Encoding: {$this->browserAcceptedEncoding}");
        header("Content-Length: {$this->gzipDataSize}");
    }

    /**
     * Write gzip files
     *
     * @param string $gzipData Data in GZIP file format
     * @param string $filePath File path for Gzip file
     * @return void
     */
    protected function _writeFile($gzipData, $filePath)
    {
        $directory = dirname($filePath);

        if (is_dir($directory) && is_writable($directory) && $gzipData !== false) {
            $fileHandle = fopen($filePath, 'w');
            fwrite($fileHandle, $gzipData);
            fclose($fileHandle);
        } else {
            throw new \InvalidArgumentException("`$filePath` is not a valid directory or is not writable.");
        }
    }
}
