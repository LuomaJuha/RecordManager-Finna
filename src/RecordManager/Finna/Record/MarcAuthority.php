<?php

/**
 * Marc authority Record Class
 *
 * PHP version 5
 *
 * Copyright (C) The National Library of Finland 2021-2023.
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
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */

namespace RecordManager\Finna\Record;

use RecordManager\Base\Database\DatabaseInterface as Database;

use function in_array;

/**
 * Marc authority record class
 *
 * This is a class for processing MARC authority records.
 *
 * @category DataManagement
 * @package  RecordManager
 * @author   Samuli Sillanpää <samuli.sillanpaa@helsinki.fi>
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/NatLibFi/RecordManager
 */
class MarcAuthority extends \RecordManager\Base\Record\MarcAuthority
{
    /**
     * Delimiter for separating name related subfields.
     *
     * @var string
     */
    protected $nameDelimiter = ', ';

    /**
     * Return fields to be indexed in Solr
     *
     * @param ?Database $db Database connection. Omit to avoid database lookups for related records.
     *
     * @return array<string, mixed>
     *
     * @psalm-suppress DuplicateArrayKey
     * @psalm-suppress InvalidOperand
     */
    public function toSolrArray(?Database $db = null)
    {
        $data = parent::toSolrArray($db);

        $data['identifier_str_mv'] = $this->getIdentifiers();

        $data['allfields'][] = $this->getHeading();
        $data['allfields'] = [
            ...$data['allfields'],
            ...$this->getAlternativeNames(['500', '510']),
        ];
        return $data;
    }

    /**
     * Get alternative names.
     *
     * @param array<int, string> $additional List of additional fields to return
     *
     * @return array<int, string>
     */
    public function getAlternativeNames($additional = [])
    {
        $result = [];
        $defaultFields = ['111', '400', '410', '411'];
        foreach ([...$defaultFields, ...$additional] as $code) {
            $subfields = in_array($code, ['400', '500'])
                ? ['a', 'b', 'c']
                : ['a', 'b'];

            foreach ($this->record->getFields($code) as $field) {
                $result[] = implode(
                    $this->nameDelimiter,
                    $this->trimFields(
                        $this->getSubfieldsArray($field, $subfields)
                    )
                );
            }
        }
        return array_unique($this->trimFields($result));
    }

    /**
     * Get heading
     *
     * @return string
     */
    protected function getHeading()
    {
        if ($name = $this->getFieldSubField('100', 'a', true)) {
            $name = $this->metadataUtils->stripTrailingPunctuation($name, '.');
            foreach (['b', 'c'] as $subfield) {
                if ($sub = $this->getFieldSubField('100', $subfield, true)) {
                    $name .= $this->nameDelimiter . $sub;
                }
            }
            return $name;
        }
        return parent::getHeading();
    }

    /**
     * Get identifiers
     *
     * @return array<int, string>
     */
    protected function getIdentifiers(): array
    {
        $result = [$this->getID()];
        foreach ($this->record->getFields('024') as $field) {
            if (
                ($id = $this->record->getSubfield($field, 'a'))
                && ($source = $this->record->getSubfield($field, '2'))
            ) {
                if (preg_match('/^https?:/', $id)) {
                    // Never prefix http(s) url's
                    $result[] = $id;
                } else {
                    $result[] = "($source)$id";
                }
            }
        }
        return $result;
    }
}
