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
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function strlen;

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
     * Type fields which should be excluded when defining format.
     *
     * @var array
     */
    protected $excludedFormatTypes = ['version'];

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     * @param HttpService   $httpService      HTTP service
     * @param ?Database     $db               Database
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils,
        HttpService $httpService,
        ?Database $db = null
    ) {
        parent::__construct(
            $config,
            $dataSourceConfig,
            $logger,
            $metadataUtils,
            $httpService,
            $db
        );
        $this->initMediaTypeTrait($config);
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

    /**
     * Get languages
     *
     * @return array
     */
    protected function getLanguages()
    {
        $languages = [];
        foreach ($this->doc->language as $language) {
            foreach (explode(' ', trim((string)$language)) as $part) {
                //Remove extra characters from start and end of a language
                $check = trim($part, ', ');
                $check = preg_replace(
                    '/^http:\/\/lexvo\.org\/id\/iso639-.\/(.*)/',
                    '$1',
                    $check
                );
                // en_US
                if (str_contains($check, '_')) {
                    $check = explode('_', $check)[0];
                }
                // Check that the language given is in proper form
                if (!$check || strlen($check) > 9 || !ctype_lower($check)) {
                    $toLog = $part ?: 'EMPTY_VALUE';
                    $this->storeWarning("unhandled language $toLog");
                    continue;
                }
                foreach (str_split($check, 3) as $code) {
                    $languages[] = $code;
                }
            }
        }
        return $this->metadataUtils->normalizeLanguageStrings($languages);
    }

    /**
     * Get online URLs
     *
     * @return array
     */
    protected function getOnlineUrls(): array
    {
        $results = [];
        foreach ($this->doc->relation as $relation) {
            $url = trim((string)$relation);
            // Ignore too long fields. Require at least one dot surrounded by valid
            // characters or a familiar scheme
            if (
                strlen($url) > 4096
                || (!preg_match('/^[A-Za-z0-9]\.[A-Za-z0-9]$/', $url)
                && !preg_match('/^https?:\/\//', $url))
            ) {
                continue;
            }
            $results[] = [
                'url' => $url,
                'text' => '',
                'source' => $this->source,
            ];
        }
        foreach ($this->doc->file as $file) {
            if (trim((string)($file->attributes()->bundle ?? '')) === 'TEXT') {
                continue;
            }
            $url = (string)$file->attributes()->href
                ? trim((string)$file->attributes()->href)
                : trim((string)$file);
            if (!$url) {
                continue;
            }
            $result = [
                'url' => $url,
                'text' => trim((string)$file->attributes()->name),
                'source' => $this->source,
            ];
            $mediaType = $this->getLinkMediaType(
                $url,
                trim($file->attributes()->type ?? '')
            );
            if ($mediaType) {
                $result['mediaType'] = $mediaType;
            }
            $results[] = $result;
        }
        return $results;
    }
}
