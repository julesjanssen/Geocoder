<?php

/**
 * This file is part of the Geocoder package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license    MIT License
 */

namespace Geocoder\Provider;

use Exception;
use Geocoder\Exception\InvalidCredentials;
use Geocoder\Exception\NoResult;
use Geocoder\Exception\QuotaExceeded;
use Geocoder\Exception\UnsupportedOperation;
use Ivory\HttpAdapter\HttpAdapterInterface;

/**
 * @author William Durand <william.durand1@gmail.com>
 */
class Mapbox extends AbstractHttpProvider
{
    /**
     * @var string
     */
    const ENDPOINT_URL = 'http://api.mapbox.com/geocoding/v5/mapbox.places/%s.json';

    /**
     * @var string
     */
    const ENDPOINT_URL_SSL = 'https://api.mapbox.com/geocoding/v5/mapbox.places/%s.json';

    /**
     * @var string
     */
    private $country;

    /**
     * @var bool
     */
    private $useSsl;

    /**
     * @var string
     */
    private $apiKey;

    /**
     * @param HttpAdapterInterface $adapter An HTTP adapter
     * @param string               $country  A locale (optional)
     * @param string               $region  Region biasing (optional)
     * @param bool                 $useSsl  Whether to use an SSL connection (optional)
     * @param string               $apiKey  Google Geocoding API key (optional)
     */
    public function __construct(HttpAdapterInterface $adapter, $country = null, $proximity = null, $useSsl = false, $apiKey = null)
    {
        parent::__construct($adapter);

        $this->country = $country;
        $this->proximity = $proximity;
        $this->useSsl = $useSsl;
        $this->apiKey = $apiKey;
    }

    /**
     * {@inheritDoc}
     */
    public function geocode($address)
    {
        // Google API returns invalid data if IP address given
        // This API doesn't handle IPs
        if (filter_var($address, FILTER_VALIDATE_IP)) {
            throw new UnsupportedOperation('The Mapbox provider does not support IP addresses, only street addresses.');
        }

        $query = sprintf(
            $this->useSsl ? self::ENDPOINT_URL_SSL : self::ENDPOINT_URL,
            urlencode($address)
        );

        return $this->executeQuery($query);
    }

    /**
     * {@inheritDoc}
     */
    public function reverse($latitude, $longitude)
    {
        return $this->geocode(sprintf('%F,%F', $latitude, $longitude));
    }

    /**
     * {@inheritDoc}
     */
    public function getName()
    {
        return 'mapbox';
    }

    public function setCountry($country)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * @param string $query
     *
     * @return string query with extra params
     */
    protected function buildQuery($query)
    {
        $params = [];
        if (null !== $this->country) {
            $params['country'] = $this->country;
        }

        if (null !== $this->apiKey) {
            $params['access_token'] = $this->apiKey;
        }

        return $query . (count($params) ? '?'. http_build_query($params) : '');
    }

    /**
     * @param string $query
     */
    private function executeQuery($query)
    {
        $query   = $this->buildQuery($query);
        echo $query .'<br/>';
        // exit;
        $response = $this->getAdapter()->get($query);

        if ($response->getStatusCode() == 401) {
            throw new InvalidCredentials(sprintf('Access token is invalid %s', $query));
        }

        if ($response->getStatusCode() == 429) {
            throw new QuotaExceeded(sprintf('Rate limits exceeded %s', $query));
        }

        $content = $response->getBody();

        if (empty($content)) {
            throw new NoResult(sprintf('Could not execute query "%s".', $query));
        }

        $json = json_decode($content);

        // API error
        if (!isset($json)) {
            throw new NoResult(sprintf('Could not execute query "%s".', $query));
        }

        // no result
        if (!isset($json->features) || !count($json->features) || $response->getStatusCode() != 200) {
            throw new NoResult(sprintf('Could not execute query "%s".', $query));
        }

        $results = [];
        foreach ($json->features as $feature) {
            $resultSet = $this->getDefaults();

            // update address components
            $this->updateAddressComponent($resultSet, $feature);

            // update coordinates
            $coordinates = $feature->geometry->coordinates;
            $resultSet['latitude']  = $coordinates[0];
            $resultSet['longitude'] = $coordinates[1];

            $resultSet['bounds'] = null;
            if (isset($feature->bbox)) {
                $resultSet['bounds'] = array(
                    'south' => $feature->bbox[0],
                    'west'  => $feature->bbox[1],
                    'north' => $feature->bbox[2],
                    'east'  => $feature->bbox[3]
                );
            }

            $results[] = array_merge($this->getDefaults(), $resultSet);
        }

        return $this->returnResults($results);
    }

    /**
     * Update current resultSet with given key/value.
     *
     * @param array  $resultSet resultSet to update
     * @param object $values    The component values
     *
     * @return array
     */
    private function updateAddressComponent(&$resultSet, $feature)
    {
        $values = [];
        $values[] = $this->normalizeContext($feature);
        if (isset($feature->context)) {
            foreach ($feature->context as $value) {
                $values[] = $this->normalizeContext($value);
            }
        }

        foreach ($values as $value) {
            $type = strtok($value->id, '.');

            switch ($type) {
                case 'country':
                    $resultSet['country']       = $value->text;
                    $resultSet['countryCode']   = $value->short_code;
                    break;

                case 'region':
                    $resultSet['adminLevels'][] = [
                        'name'      => $value->text,
                        'code'      => '',
                        'level'     => 1,
                    ];
                    break;

                case 'postcode':
                    $resultSet['postalCode']    = $value->text;
                    break;

                case 'place':
                    $resultSet['locality']      = $value->text;
                    break;

                case 'locality':
                    $resultSet['locality']      = $value->text;
                    break;

                case 'neighborhood':
                    $resultSet['subLocality']   = $value->text;
                    break;

                case 'address':
                    $resultSet['streetName']    = $value->text;
                    break;

                case 'poi':
                default:
                    break;

            }

        }

        return $resultSet;
    }

    private function normalizeContext($value)
    {
        $result = [
            'id'        => $value->id,
            'text'      => $value->text,
        ];

        if (isset($value->short_code)) {
            $result['short_code'] = $value->short_code;
        }

        if (isset($value->properties)) {
            foreach ($value->properties as $key => $property) {
                $result[$key] = $property;
            }
        }

        return (object) $result;
    }
}
