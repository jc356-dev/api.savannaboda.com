<?php

namespace Drupal\commerce_square_catalog;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Square\SquareClient;
use Square\Apis\LocationsApi;
use Square\Exceptions\ApiException;
use Square\Http\ApiResponse;
use Square\Models\ListLocationsResponse;
use Square\Environment;

/**
 * CommerceSquareCatalogService service.
 */
class CommerceSquareCatalogService {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The application settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $settings;

  /**
   * The application settings.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $testSettings;

  /**
   * Constructs a CommerceSquareCatalogService object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    $this->settings = $config_factory->get('commerce_square.settings');
    $this->testSettings = $config_factory->get('commerce_square_catalog.settings');
    $this->httpClient = $http_client;
  }

  /**
   * Method description.
   */
  public function getClient(bool $testMode = FALSE): SquareClient {
    if ($testMode) {
      // $accessToken = $this->testSettings->get('sandbox_access_token');
      $client = new SquareClient([
        'accessToken' => $this->testSettings->get('sandbox_access_token'),
        'environment' => Environment::SANDBOX,
      ]);
      dpm($this->testSettings->get('sandbox_access_token'), 'Sandbox TOKEN');
    }
    else {
      // Have to get this from the state stuff.
      // $accessToken = $this->settings->get('production_access_token');
      $state = \Drupal::state();
      $productionToken = $state->get('commerce_square.production_access_token');
      $client = new SquareClient([
        'accessToken' => $productionToken,
        'environment' => Environment::PRODUCTION,
      ]);

      dpm($productionToken, 'LIVE TOKEN');
    }

    return $client;
  }

  public function hasEnoughInventory($catalogID, $qty, $testMode = FALSE): bool {


    return TRUE;
  }

  /**
   * @param $catalogIDS
   * @param $testMode
   *
   * @return array|mixed
   * @throws \Square\Exceptions\ApiException
   */
  public function getAllCatalogInventories($catalogIDS = [], $testMode = FALSE) {
    $client = $this->getClient($testMode);
    $inventoryApi = $client->getInventoryApi();
    $body = new \Square\Models\BatchRetrieveInventoryCountsRequest;

    // If Catalog Item IDs are provided, check only those.
    if (!empty($catalogIDS)) {
      $body->setCatalogObjectIds($catalogIDS);
    }

    // If setLocationIds is empty, searches all locations.
    // $body->setLocationIds(['XXXLALALA222']);

    // Generate a RFC 3339 timestamp.
    $body->setUpdatedAfter('2021-11-16T00:00:00.000Z');
    // $body->setCursor('cursor0');
    // $body->setStates([\Square\Models\InventoryState::SUPPORTED_BY_NEWER_VERSION]);
    $body->setStates([\Square\Models\InventoryState::IN_STOCK]);

    $apiResponse = $inventoryApi->batchRetrieveInventoryCounts($body);

    if ($apiResponse->isSuccess()) {
      $batchRetrieveInventoryCountsResponse = $apiResponse->getResult();
      dpm($batchRetrieveInventoryCountsResponse, 'INVENTORY');

      return $batchRetrieveInventoryCountsResponse;
    } else {
      $errors = $apiResponse->getErrors();
      dpm($errors, 'Inventory ERROR');
    }

    return [];
  }

  public function getSingleCatalogInventory($catalogID) {
    /*$apiResponse = $inventoryApi->retrieveInventoryCount($catalogObjectId, $locationIds, $cursor);

    if ($apiResponse->isSuccess()) {
      $retrieveInventoryCountResponse = $apiResponse->getResult();
    } else {
      $errors = $apiResponse->getErrors();
    }*/
  }


}
