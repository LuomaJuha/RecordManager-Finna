<?php

/**
 * Ead record class
 *
 * PHP version 7
 *
 * Copyright (C) The National Library of Finland 2012-2022.
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
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;
use RecordManager\Base\Utils\Logger;
use RecordManager\Base\Utils\MetadataUtils;

/**
 * Ead record class
 *
 * This is a class for processing EAD records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class Ead extends \RecordManager\Base\Record\Ead
{
    use AuthoritySupportTrait;
    use DateSupportTrait;
    use MediaTypeTrait;

    /**
     * Field for geographic data
     *
     * @var string
     */
    protected $geoField = 'location_geo';

    /**
     * Field for geographic center coordinates
     *
     * @var string
     */
    protected $geoCenterField = 'center_coords';

    /**
     * Constructor
     *
     * @param array         $config           Main configuration
     * @param array         $dataSourceConfig Data source settings
     * @param Logger        $logger           Logger
     * @param MetadataUtils $metadataUtils    Metadata utilities
     */
    public function __construct(
        array $config,
        array $dataSourceConfig,
        Logger $logger,
        MetadataUtils $metadataUtils
    ) {
        parent::__construct(
            $config,
            $dataSourceConfig,
            $logger,
            $metadataUtils
        );
        $this->initMediaTypeTrait($config);
    }

    /**
     * Return fields to be indexed in Solr
     *
     * @param Database $db Database connection. Omit to avoid database lookups for
     *                     related records.
     *
     * @return array<string, mixed>
     */
    public function toSolrArray(Database $db = null)
    {
        $data = parent::toSolrArray($db);
        $doc = $this->doc;

        $unitDateRange = $this->parseDateRange((string)$doc->did->unitdate);
        $data['search_daterange_mv'] = $data['unit_daterange']
            = $this->dateRangeToStr($unitDateRange);
        if ($unitDateRange) {
            $data['main_date_str'] = $this->metadataUtils
                ->extractYear($unitDateRange[0]);
            $data['main_date'] = $this->validateDate($unitDateRange[0]);
            // Append year range to title (only years, not the full dates)
            $startYear = $this->metadataUtils->extractYear($unitDateRange[0]);
            $endYear = $this->metadataUtils->extractYear($unitDateRange[1]);
            $yearRange = '';
            if ($startYear != '-9999') {
                $yearRange = $startYear;
            }
            if ($endYear != $startYear) {
                $yearRange .= '-';
                if ($endYear != '9999') {
                    $yearRange .= $endYear;
                }
            }
            if ($yearRange) {
                $len = strlen($yearRange);
                foreach (['title_full', 'title_sort', 'title', 'title_short'] as $field) {
                    if (
                        substr($data[$field], -$len) != $yearRange
                        && substr($data[$field], -$len - 2) != "($yearRange)"
                    ) {
                        $data[$field] .= " ($yearRange)";
                    }
                }
            }
        }

        // Single-valued sequence for sorting
        if (isset($data['hierarchy_sequence'])) {
            $data['hierarchy_sequence_str'] = $data['hierarchy_sequence'];
        }

        $data['source_str_mv'] = ($data['institution'] ?? '') ?: $this->source;
        $data['datasource_str_mv'] = $this->source;

        // Digitized?
        if ($doc->did->daogrp) {
            if (in_array($data['format'], ['collection', 'series', 'fonds', 'item'])) {
                $data['format'] = 'digitized_' . $data['format'];
            }
        }

        if ($this->isOnline()) {
            $data['online_boolean'] = '1';
            // This is sort of special. Make sure to use source instead
            // of datasource.
            $data['online_str_mv'] = $data['source_str_mv'];

            if ($this->isFreeOnline()) {
                $data['free_online_boolean'] = '1';
                // This is sort of special. Make sure to use source instead
                // of datasource.
                $data['free_online_str_mv'] = $data['source_str_mv'];
            }
        }

        if (isset($doc->did->unitid)) {
            $data['identifier'] = (string)$doc->did->unitid;
        }
        if (isset($doc->did->dimensions)) {
            // display measurements
            $data['measurements'] = (string)$doc->did->dimensions;
        }

        if (isset($doc->did->physdesc)) {
            $data['material'] = (string)$doc->did->physdesc;
        }

        if (isset($doc->did->userestrict->p)) {
            $data['rights'] = (string)$doc->did->userestrict->p;
        } elseif (isset($doc->did->accessrestrict->p)) {
            $data['rights'] = (string)$doc->did->accessrestrict->p;
        }

        // Usage rights
        if ($rights = $this->getUsageRights()) {
            $data['usage_rights_str_mv'] = $rights;
            $data['usage_rights_ext_str_mv'] = $rights;
        }

        // phpcs:ignore
        /** @psalm-var list<string> */
        $a = (array)($data['author'] ?? []);
        // phpcs:ignore
        /** @psalm-var list<string> */
        $a2 = (array)($data['author2'] ?? []);
        // phpcs:ignore
        /** @psalm-var list<string> */
        $ac = (array)($data['author_corporate'] ?? []);
        $data['author_facet'] = [...$a, ...$a2, ...$ac];

        $data['format_ext_str_mv'] = (array)$data['format'];
        if ($this->hasImages()) {
            $data['format_ext_str_mv'][] = 'Image';
        }
        $onlineUrls = $this->getOnlineURLs();
        $data['media_type_str_mv'] = $data['media_type_facet_str_mv'] = array_values(
            array_unique(
                array_column($onlineUrls, 'mediaType')
            )
        );
        return $data;
    }

    /**
     * Return usage rights if any
     *
     * @return array ['restricted'] or a more specific id if restricted,
     * empty array otherwise
     */
    protected function getUsageRights()
    {
        $getRestriction = function ($restrict) {
            $restrict = (string)$restrict;
            if (strstr($restrict, 'No known copyright restrictions')) {
                return ['No known copyright restrictions'];
            }
            if (
                strncasecmp($restrict, 'CC', 2) === 0
                || strncasecmp($restrict, 'Public', 6) === 0
                || strncasecmp($restrict, 'Julkinen', 8) === 0
            ) {
                return [$restrict];
            }
            return null;
        };

        // Handle each element separately. Any merging as an array is bound to cause
        // problems with element attributes.
        foreach ($this->doc->userestrict->p ?? [] as $restrict) {
            if ($result = $getRestriction($restrict)) {
                return $result;
            }
        }
        foreach ($this->doc->accessrestrict->p ?? [] as $restrict) {
            if ($result = $getRestriction($restrict)) {
                return $result;
            }
        }

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
        foreach ($this->doc->did->daogrp->daoloc ?? [] as $daoloc) {
            if ($daoloc->attributes()->{'href'}) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if the record is freely available online
     *
     * @return bool
     */
    protected function isFreeOnline(): bool
    {
        if (null !== ($free = $this->getDriverParam('freeOnline', null))) {
            return boolval($free);
        }
        foreach ($this->doc->accessrestrict->p ?? [] as $restrict) {
            if (trim((string)$restrict) !== '') {
                return false;
            }
        }
        return $this->getDriverParam('freeOnlineDefault', true);
    }

    /**
     * Parse date range string
     *
     * @param string $input Date range
     *
     * @return NULL|array
     */
    protected function parseDateRange($input)
    {
        if (!$input || $input == '-') {
            return null;
        }

        $dateRangeRe = '/(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d\d\d)/';

        $found = preg_match(
            '/(\d\d?).(\d\d?).(\d\d\d\d) ?- ?(\d\d?).(\d\d?).(\d\d\d\d)/',
            $input,
            $matches
        );
        if ($found > 0) {
            $startYear = $matches[3];
            $startMonth = sprintf('%02d', $matches[2]);
            $startDay = sprintf('%02d', $matches[1]);
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[6];
            $endMonth = sprintf('%02d', $matches[5]);
            $endDay = sprintf('%02d', $matches[4]);
            $endDate = $endYear . '-' . $endMonth . '-' . $endDay . 'T23:59:59Z';
        } elseif (preg_match($dateRangeRe, $input, $matches) > 0) {
            $startYear = $matches[2];
            $startMonth = sprintf('%02d', $matches[1]);
            $startDay = '01';
            $startDate = $startYear . '-' . $startMonth . '-' . $startDay
                . 'T00:00:00Z';
            $endYear = $matches[4];
            $endMonth = sprintf('%02d', $matches[3]);
            $endDate = $endYear . '-' . $endMonth . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Ead',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d\d\d) ?- ?(\d\d\d\d)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)-(\d\d?)-(\d\d?)/', $input, $matches) > 0) {
            $year = $matches[1];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[3]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?).(\d\d?).(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[3];
            $month = sprintf('%02d', $matches[2]);
            $day = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-' . $day . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-' . $day . 'T23:59:59Z';
        } elseif (preg_match('/(\d\d?)\.(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[2];
            $month = sprintf('%02d', $matches[1]);
            $startDate = $year . '-' . $month . '-01' . 'T00:00:00Z';
            $endDate = $year . '-' . $month . '-01';
            try {
                $d = new \DateTime($endDate);
            } catch (\Exception $e) {
                $this->logger->logDebug(
                    'Ead',
                    "Failed to parse date $endDate, record {$this->source}."
                        . $this->getID()
                );
                $this->storeWarning('invalid end date');
                return null;
            }
            $endDate = $d->format('Y-m-t') . 'T23:59:59Z';
        } elseif (preg_match('/(\d+) ?- ?(\d+)/', $input, $matches) > 0) {
            $startDate = $matches[1] . '-01-01T00:00:00Z';
            $endDate = $matches[2] . '-12-31T00:00:00Z';
        } elseif (preg_match('/(\d\d\d\d)/', $input, $matches) > 0) {
            $year = $matches[1];
            $startDate = $year . '-01-01T00:00:00Z';
            $endDate = $year . '-12-31T23:59:59Z';
        } else {
            return null;
        }

        if (strtotime($startDate) > strtotime($endDate)) {
            $this->logger->logDebug(
                'Ead',
                "Invalid date range {$startDate} - {$endDate}, record "
                    . "{$this->source}." . $this->getID()
            );
            $this->storeWarning('invalid date range');
            $endDate = substr($startDate, 0, 4) . '-12-31T23:59:59Z';
        }

        return [$startDate, $endDate];
    }

    /**
     * Get online URLs
     *
     * @return array
     */
    protected function getOnlineURLs(): array
    {
        $results = [];
        foreach ($this->doc->did->daogrp ?? [] as $daogrp) {
            foreach ($daogrp->daoloc as $daoloc) {
                $url = trim($daoloc->attributes()->href);
                if (empty($url)) {
                    continue;
                }
                $result = [
                    'url' => $url,
                    'desc' => '',
                    'source' => $this->source,
                ];
                $mediaType = $this->getLinkMediaType($url);
                if ($mediaType) {
                    $result['mediaType'] = $mediaType;
                }
                $results[] = $result;
            }
        }
        return $results;
    }

    /**
     * Check if the record has image links (full images)
     *
     * @return bool
     */
    protected function hasImages()
    {
        foreach ($this->doc->did->daogrp ?? [] as $daogrp) {
            if (!isset($daogrp->daoloc)) {
                continue;
            }
            foreach ($daogrp->daoloc as $daoloc) {
                $role = $daoloc->attributes()->{'role'};
                if (in_array($role, ['image_full', 'image_reference'])) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Get topic identifiers.
     *
     * @return array
     */
    protected function getTopicIDs(): array
    {
        $result = parent::getTopicIDs();
        return $this->addNamespaceToAuthorityIds($result, 'topic');
    }
}
