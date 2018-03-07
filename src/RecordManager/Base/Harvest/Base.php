<?php
/**
 * Harvesting Base Class
 *
 * PHP version 5
 *
 * Copyright (c) The National Library of Finland 2011-2017.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
namespace RecordManager\Base\Harvest;

use RecordManager\Base\Database\Database;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\XslTransformation;

/**
 * Harvesting Base Class
 *
 * This class provides a basic structure for harvesting classes.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/KDK-Alli/RecordManager
 */
class Base
{
    /**
     * Database
     *
     * @var Database
     */
    protected $db;

    /**
     * Logger
     *
     * @var Logger
     */
    protected $log;

    /**
     * Main configuration
     *
     * @var array
     */
    protected $config;

    /**
     * Base URL of repository
     *
     * @var string
     */
    protected $baseURL;

    /**
     * Source ID
     *
     * @var string
     */
    protected $source;

    /**
     * Harvest start date (null for all records)
     *
     * @var string
     */
    protected $startDate = null;

    /**
     * Harvest end date (null for all records)
     *
     * @var string
     */
    protected $endDate = null;

    /**
     * Whether to display debug output
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Changed record count
     *
     * @var int
     */
    protected $normalRecords = 0;

    /**
     * Deleted record count
     *
     * @var int
     */
    protected $deletedRecords = 0;

    /**
     * Unchanged record count
     *
     * @var int
     */
    protected $unchangedRecords = 0;

    /**
     * Transformation applied to the OAI-PMH responses before processing
     *
     * @var XslTransformation
     */
    protected $preXslt = null;

    /**
     * Record handling callback
     *
     * @var callable
     */
    protected $callback = null;

    /**
     * Most recent record date encountered during harvesting
     *
     * @var date
     */
    protected $trackedEndDate = '';

    /**
     * HTTP_Request2 configuration params
     *
     * @var array
     */
    protected $httpParams = [];

    /**
     * Number of times to attempt a request before bailing out
     *
     * @var int
     */
    protected $maxTries = 5;

    /**
     * Seconds to wait between request attempts
     *
     * @var int
     */
    protected $retryWait = 30;

    /**
     * Constructor.
     *
     * @param Database $db       Database
     * @param Logger   $logger   The Logger object used for logging messages
     * @param string   $source   The data source to be harvested
     * @param string   $basePath RecordManager main directory location
     * @param array    $config   Main configuration
     * @param array    $settings Settings from datasources.ini
     *
     * @throws Exception
     */
    public function __construct(Database $db, Logger $logger, $source, $basePath,
        $config, $settings
    ) {
        $this->db = $db;
        $this->log = $logger;
        $this->config = $config;

        // Don't time out during harvest
        set_time_limit(0);

        // Check if we have a start date
        $this->source = $source;
        $this->loadLastHarvestedDate();

        // Set up base URL:
        if (empty($settings['url'])) {
            throw new \Exception("Missing base URL for {$source}");
        }
        $this->baseURL = $settings['url'];
        if (isset($settings['verbose'])) {
            $this->verbose = $settings['verbose'];
        }

        if (!empty($settings['preTransformation'])) {
            $style = new \DOMDocument();
            $style->load(
                $basePath . '/transformations/' . $settings['preTransformation']
            );
            $this->preXslt = new \XSLTProcessor();
            $this->preXslt->importStylesheet($style);
            $this->preXslt->setParameter('', 'source_id', $this->source);
        }

        if (isset($config['Harvesting']['max_tries'])) {
            $this->maxTries = $config['Harvesting']['max_tries'];
        }
        if (isset($config['Harvesting']['retry_wait'])) {
            $this->retryWait = $config['Harvesting']['retry_wait'];
        }

        if (isset($config['HTTP'])) {
            $this->httpParams += $config['HTTP'];
        }
    }

    /**
     * Return the number of changed records
     *
     * @return number
     */
    public function getChangedRecordCount()
    {
        return $this->changedRecords;
    }

    /**
     * Return the number of deleted records
     *
     * @return number
     */
    public function getDeletedRecordCount()
    {
        return $this->deletedRecords;
    }

    /**
     * Return the number of unchanged records
     *
     * @return number
     */
    public function getUnchangedRecordCount()
    {
        return $this->unchangedRecords;
    }

    /**
     * Return total number of harvested records
     *
     * @return int
     */
    public function getHarvestedRecordCount()
    {
        return $this->changedRecords + $this->deletedRecords
            + $this->unchangedRecords;
    }

    /**
     * Set a start date for the harvest (only harvest records AFTER this date).
     *
     * @param string $date Start date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setStartDate($date)
    {
        $this->startDate = $date;
    }

    /**
     * Set an end date for the harvest (only harvest records BEFORE this date).
     *
     * @param string $date End date (YYYY-MM-DD format).
     *
     * @return void
     */
    public function setEndDate($date)
    {
        $this->endDate = $date;
    }

    /**
     * Initialize settings for harvesting.
     *
     * @param callable $callback Function to be called to store a harvested record
     *
     * @return void
     */
    public function initHarvest($callback)
    {
        $this->callback = $callback;
        $this->changedRecords = 0;
        $this->unchangedRecords = 0;
        $this->deletedRecords = 0;
    }

    /**
     * Retrieve the date from the database and use it as our start
     * date if it is available.
     *
     * @return void
     */
    protected function loadLastHarvestedDate()
    {
        $state = $this->db->getState("Last Harvest Date {$this->source}");
        if (null !== $state) {
            $this->setStartDate($state['value']);
        }
    }

    /**
     * Save the tracked date as the last harvested date.
     *
     * @param string $date Date to save.
     *
     * @return void
     */
    protected function saveLastHarvestedDate($date)
    {
        $state = ['_id' => "Last Harvest Date {$this->source}", 'value' => $date];
        $this->db->saveState($state);
    }

    /**
     * Check if the record is deleted.
     * This implementation works for MARC records.
     *
     * @param SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isDeleted($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status == 'd';
    }

    /**
     * Check if the record is modified.
     * This implementation works for MARC records.
     *
     * @param SimpleXMLElement $record Record
     *
     * @return bool
     */
    protected function isModified($record)
    {
        $status = substr($record->leader, 5, 1);
        return $status != 'd';
    }

    /**
     * Extract record ID.
     * This implementation works for MARC records.
     *
     * @param SimpleXMLElement $record Record
     *
     * @return string|bool ID if found, false if record is missing ID
     * @throws Exception
     */
    protected function extractID($record)
    {
        foreach ($record->controlfield as $field) {
            if ($field->attributes()->tag == '001') {
                return (string)$field;
            }
        }
        return false;
    }

    /**
     * Create an OAI style ID
     *
     * @param string $sourceId Source ID
     * @param string $id       Record ID
     *
     * @return string OAI ID
     */
    protected function createOaiId($sourceId, $id)
    {
        return get_class($this) . ":$sourceId:$id";
    }

    /**
     * Do pre-transformation
     *
     * @param string $xml XML to transform
     *
     * @return string Transformed XML
     * @throws Exception
     */
    protected function preTransform($xml)
    {
        $doc = new \DOMDocument();
        $saveUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $result = $doc->loadXML($xml, LIBXML_PARSEHUGE);
        if ($result === false || libxml_get_last_error() !== false) {
            $this->message(
                'Invalid XML received, trying encoding fix...',
                false,
                Logger::WARNING
            );
            $xml = iconv('UTF-8', 'UTF-8//IGNORE', $xml);
            libxml_clear_errors();
            $result = $doc->loadXML($xml, LIBXML_PARSEHUGE);
        }
        if ($result === false || libxml_get_last_error() !== false) {
            libxml_use_internal_errors($saveUseErrors);
            $errors = '';
            foreach (libxml_get_errors() as $error) {
                if ($errors) {
                    $errors .= '; ';
                }
                $errors .= 'Error ' . $error->code . ' at ' . $error->line . ':'
                    . $error->column . ': ' . $error->message;
            }
            $this->message("Could not parse XML: $errors\n", false, Logger::FATAL);
            throw new \Exception("Failed to parse XML");
        }
        libxml_use_internal_errors($saveUseErrors);

        return $this->preXslt->transformToXml($doc);
    }

    /**
     * Report the results of harvesting
     *
     * @return void
     */
    protected function reportResults()
    {
        $this->message(
            'Harvested ' . $this->changedRecords . ' updated, '
            . $this->unchangedRecords . ' unchanged and '
            . $this->deletedRecords . ' deleted records'
        );
    }

    /**
     * Log a message and display on console in verbose mode.
     *
     * @param string $msg     Message
     * @param bool   $verbose Flag telling whether this is considered verbose output
     * @param int    $level   Logging level
     *
     * @return void
     */
    protected function message($msg, $verbose = false, $level = Logger::INFO)
    {
        $msg = "[{$this->source}] $msg";
        if ($this->verbose) {
            echo "$msg\n";
        }
        $classParts = explode('\\', get_class($this));
        $class = end($classParts);
        $this->log->log($class, $msg, $level);
    }

    /**
     * Get file name for a temporary file
     *
     * @param string $prefix File name prefix
     * @param string $suffix File name suffix
     *
     * @return void
     */
    protected function getTempFileName($prefix, $suffix)
    {
        $tmpDir = !empty($this->config['Site']['temp_dir'])
            ? $this->config['Site']['temp_dir'] : sys_get_temp_dir();

        $attempt = 1;
        do {
            $tmpName = $tmpDir . DIRECTORY_SEPARATOR . $prefix . getmypid()
                . mt_rand() . $suffix;
            $fp = @fopen($tmpName, 'x');
        } while (!$fp && ++$attempt < 100);
        if (!$fp) {
            throw new \Exception("Could not create temp file $tmpName");
        }
        fclose($fp);
        return $tmpName;
    }
}
