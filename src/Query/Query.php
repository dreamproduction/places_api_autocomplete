<?php

namespace Drupal\places_api_autocomplete\Query;

/**
 * @file
 * Contains Drupal\places_api_autocomplete\Query\Query.
 */
use Drupal\places_api_autocomplete\Cache\CacheInterface;
use Drupal\places_api_autocomplete\Exception\RequestException;
use Fig\Cache\Memory\MemoryPool;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Queries the Google Places API for autocomplete suggestions.
 */
class Query implements QueryInterface {

  /**
   * The Google API key.
   *
   * @var string
   */
  protected $key;

  /**
   * The Google API endpoint.
   *
   * @var string
   */
  protected $endPoint = 'https://maps.googleapis.com/maps/api/place/autocomplete/json?';

  /**
   * The input from the user.
   *
   * @var string
   */
  protected $input;

  /**
   * The options (parameters) for the request to the Places API.
   *
   * @var array
   */
  protected $options = array();

  /**
   * Cache services.
   *
   * @var CacheItemPoolInterface[]
   */
  protected $caches;

  /**
   * The results of the query.
   *
   * @var array
   */
  protected $results;

  /**
   * Constructor for PlacesApiAutocomplete.
   *
   * @param string $key
   *   The Google API key.
   * @param \Psr\Cache\CacheItemPoolInterface $cache
   */
  public function __construct($key, CacheItemPoolInterface $cache = NULL) {
    $this->key = $key;

    // Use an in memory cache backend to store queries per request.
    $this->caches = array(
      new MemoryPool()
    );

    // If a permanent cache was provided, add it to the list of cache backends.
    if ($cache) {
      $this->caches []= $cache;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function query($input, $options = array()) {
    $this->input = $input;
    $this->options = $options;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function execute() {
    $this->executeSearch();
    return $this->results;
  }

  /**
   * Retrieves the results from cache.
   *
   * @return array
   *   The cached results, or NULL.
   */
  private function cacheGet() {
    foreach ($this->caches as $cache) {
      $item = $cache->getItem($this->getCid());
      $data = $item->get();
      // If we found a cache value, validate the input is the same (to prevent
      // an hypotetical situation where 2 input strings have the same hash).
      if ($item->isHit() && $data['input'] == $this->input ) {
        return $data['value'];
      }
    }
  }

  /**
   * Stores a value in the cache.
   *
   * @param mixed $value
   *   The value to be stored.
   */
  private function cacheSet($value) {
    $data = array(
      'input' => $this->input,
      'value' => $value
    );

    foreach ($this->caches as $cache) {
      $cache_item = $cache->getItem($this->getCid());
      $cache_item->set($data);
      $cache->save($cache_item);
    }
  }

  /**
   * Constructs a cache id based on the input.
   *
   * @return string
   *   The cid
   */
  private function getCid() {
    return 'hash.' . md5($this->input);
  }

  /**
   * Executes the actual request to the Places API end point.
   */
  private function executeSearch() {
    // First, attempt to get the data from cache. If this fails, we will query
    // the Places API.
    if (!$data = $this->cacheGet()) {
      // Get cURL resource.
      $curl = curl_init();

      // Set some options.
      curl_setopt_array($curl, array(
          CURLOPT_RETURNTRANSFER => 1,
          CURLOPT_URL => $this->prepareUrl(),
      ));

      // Send the request.
      $response = curl_exec($curl);

      // Close request to clear up some resources.
      curl_close($curl);

      // Decode the response json.
      $decoded_response = json_decode($response);
      // If not an object we hit some unknown error.
      if (!is_object($decoded_response)) {
        $error_msg = "Unknown error getting data from Google Places API.";
        throw new RequestException($error_msg, 1);
      }
      // If status code is not OK or ZERO_RESULTS, we hit a defined Places API error
      if (!in_array($decoded_response->status, array('OK', 'ZERO_RESULTS'))) {
        $error_msg = $decoded_response->status;
        if (isset($decoded_response->error_message)) {
          $error_msg .= ": {$decoded_response->error_message}";
        }
        throw new RequestException($error_msg, 1);
      }

      // Get just the predictions from the response.
      $data = $decoded_response->predictions;

      // Save these to cache for future requests.
      $this->cacheSet($data);
    }

    // Set the results.
    $this->results = $data;
  }

  /**
   * Constructs the url for the request.
   *
   * @return string
   *   The url.
   */
  private function prepareUrl() {
    // Get the options for the request.
    $options = $this->options;

    // Add the key and the input to them.
    $parameters = array(
      'key' => $this->key,
      'input' => $this->input,
    ) + $options;

    // Url encode the parameters.
    $processed_parameters = array();
    foreach ($parameters as $name => $value) {
      $processed_parameters[] = urlencode($name) . '=' . urlencode($value);
    }

    // Add the parameters to the endpoint and return them.
    return $this->endPoint . implode('&', $processed_parameters);
  }
  
}
