<?php
/**
 * Qdc record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2023.
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
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Http\ClientManager;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Qdc record class
 *
 * This is a class for processing Qualified Dublin Core records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Qdc extends \RecordManager\Base\Record\Qdc
{
    use QdcRecordTrait;

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     * @param ClientManager $httpManager      HTTP client manager
     * @param ?Database     $db               Database
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        ClientManager $httpManager,
        Database $db = null
    ) {
        parent::__construct(
            $config,
            $dataSourceConfig,
            $logger,
            $metadataUtils,
            $httpManager,
            $db
        );
        $this->extensionDetector
            = new \League\MimeTypeDetection\ExtensionMimeTypeDetector();
        $this->extensionMapper
            = new \League\MimeTypeDetection\GeneratedExtensionToMimeTypeMap();
    }

    /**
     * Get primary authors
     *
     * @return array
     */
    public function getPrimaryAuthors()
    {
        $authors = $this->getValues('author');
        if ($authors) {
            return (array)array_shift($authors);
        }
        return parent::getPrimaryAuthors();
    }

    /**
     * Dedup: Return series numbering
     *
     * @return string
     */
    public function getSeriesNumbering()
    {
        foreach ($this->doc->relation as $rel) {
            if ((string)$rel->attributes()->{'type'} === 'numberinseries') {
                return trim((string)$rel);
            }
        }
        return '';
    }

    /**
     * Get series information
     *
     * @return array
     */
    public function getSeries()
    {
        $result = [];
        foreach ($this->doc->relation as $rel) {
            if ((string)$rel->attributes()->{'type'} === 'ispartofseries') {
                $result[] = trim((string)$rel);
            }
        }
        return $result;
    }

    /**
     * Get hierarchy fields. Must be called after title is present in the array.
     *
     * @param array $data Reference to the target array
     *
     * @return void
     */
    protected function getHierarchyFields(array &$data): void
    {
        $data['hierarchy_parent_title'] = $this->getValues('isPartOf');
        foreach ($this->doc->relation as $rel) {
            if ((string)$rel->attributes()->type === 'ispartof') {
                $data['hierarchy_parent_title'][] = trim((string)$rel);
            }
        }
    }
}
