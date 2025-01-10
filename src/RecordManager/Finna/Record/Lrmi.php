<?php

/**
 * Lrmi record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2011-2023.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Http\HttpService;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

use function boolval;

/**
 * Lrmi record class
 *
 * This is a class for processing Lrmi records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Juha Luoma <juha.luoma@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Lrmi extends \RecordManager\Base\Record\Lrmi
{
    use AuthoritySupportTrait;
    use QdcRecordTrait {
        toSolrArray as _toSolrArray;
    }

    /**
     * Fields that are not included in allfield.
     *
     * @var array
     */
    protected $ignoredAllfields = [
        'format', 'id', 'identifier', 'date', 'dateCreated', 'dateModified',
        'filesize', 'inLanguage', 'position', 'recordID', 'rights', 'targetUrl',
        'url',
    ];

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
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = $this->_toSolrArray();

        $doc = $this->doc;
        // Facets
        foreach ($doc->educationalAudience as $audience) {
            $data['educational_audience_str_mv'][]
                = (string)$audience->educationalRole;
        }
        foreach ($doc->learningResource->educationalLevel ?? [] as $educationalLevel) {
            $educationalLevel = $educationalLevel->name ?? $educationalLevel;
            $data['educational_level_str_mv'][] = (string)$educationalLevel;
        }
        foreach ($doc->learningResource->teaches ?? [] as $teaches) {
            $teaches = $teaches->name ?? $teaches;
            $data['educational_aim_str_mv'][] = (string)$teaches;
        }
        foreach ($doc->learningResource->educationalAlignment ?? [] as $alignment) {
            if ($subject = $alignment->educationalSubject ?? null) {
                $subject = $subject->targetName ?? $subject;
                $data['educational_subject_str_mv'][] = (string)$subject;
            }
        }

        foreach ($doc->type as $type) {
            $data['educational_material_type_str_mv'][] = (string)$type;
        }

        // Topic ids
        $data['topic_id_str_mv'] = $this->getTopicIds();

        return $data;
    }

    /**
     * Get online URLs
     *
     * @return array
     */
    public function getOnlineUrls(): array
    {
        $results = [];
        // Materials
        foreach ($this->doc->material ?? [] as $material) {
            if ($url = (string)($material->url ?? '')) {
                $result = [
                    'url' => $url,
                    'text' => trim((string)($material->name ?? $url)),
                    'source' => $this->source,
                ];
                $mediaType = $this->getLinkMediaType(
                    $url,
                    trim($material->format ?? '')
                );
                if ($mediaType) {
                    $result['mediaType'] = $mediaType;
                }
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Return title
     *
     * @param bool $forFiling Whether the title is to be used in filing
     *                        (e.g. sorting, non-filing characters should be removed)
     *
     * @return string
     */
    public function getTitle($forFiling = false)
    {
        $doc = $this->doc;
        $title = (string)$doc->title;
        foreach ($doc->title as $t) {
            if ((string)$t->attributes()->lang === 'fi') {
                $title = (string)$t;
                break;
            }
        }
        if ($forFiling) {
            $title = $this->metadataUtils->createSortTitle($title);
        }
        return $title;
    }

    /**
     * Return URLs associated with object
     *
     * @return array
     */
    protected function getUrls()
    {
        return [];
    }

    /**
     * Check if the record is available online
     *
     * @return bool
     */
    protected function isOnline(): bool
    {
        if (null !== ($online = $this->getDriverParam('online', null))) {
            return boolval($online);
        }

        return !empty($this->doc->material);
    }

    /**
     * Return subject identifiers associated with object.
     *
     * @return array
     */
    protected function getTopicIDs(): array
    {
        $result = $this->getTopicData(true);
        return $this->addNamespaceToAuthorityIds($result, 'topic');
    }
}
