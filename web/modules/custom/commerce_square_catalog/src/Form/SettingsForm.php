<?php

namespace Drupal\commerce_square_catalog\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\physical\LengthUnit;
use Square\Models\CatalogObjectType;

/**
 * Configure Commerce Square Catalog settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * A list of product variation bundles.
   *
   * @var array
   */
  protected $productBundles;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'commerce_square_catalog_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['commerce_square_catalog.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('commerce_square_catalog.settings');
    /**
     * @var \Drupal\Core\TempStore\SharedTempStore $tempStore
     */
    $tempStore = \Drupal::service('tempstore.shared')->get('commerce_square_catalog');
    $sandboxCursor = $tempStore->get('sandbox:items_import:cursor');
    $productionCursor = $tempStore->get('production:items_import:cursor');

    $this->productBundles = \Drupal::entityTypeManager()->getStorage('commerce_product_type')->loadMultiple();

    $bundle_options = [];
    foreach ($this->productBundles as $bundle_id => $bundle) {
      $bundle_options[$bundle_id] = $bundle->label();
    }
    $form['catalog_product_bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Product type to import Square Catalog Items as'),
      '#required' => TRUE,
      '#options' => $bundle_options,
      '#default_value' => $config->get('catalog_product_bundle'),
    ];

    $stores = \Drupal::entityTypeManager()->getStorage('commerce_store')->loadMultiple();
    $storeOptions = [];
    $currentStores = $config->get('catalog_stores');
    // dpm($currentStores, 'CURRENT STORES');

    foreach ($stores as $store_id => $store) {
      $storeOptions[$store_id] = $store->label();
    }

    $form['catalog_stores'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Stores for imported Square Catalog Items'),
      '#required' => TRUE,
      '#options' => $storeOptions,
      '#default_value' => empty($currentStores) ? [] : $currentStores,
    ];

    $form['sandbox'] = [
      '#type' => 'fieldset',
      '#collapsible' => FALSE,
      '#collapsed' => FALSE,
      '#title' => $this->t('Sandbox'),
    ];
    $form['sandbox']['instructions'] = [
      '#markup' => $this->t('Enter your application sandbox environment information.  This allows you to test functionality.  The Production section below uses commerce_square production params.'),
    ];
    $form['sandbox']['sandbox_app_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Application ID'),
      '#default_value' => $config->get('sandbox_app_id'),
      '#required' => TRUE,
      '#description' => $this->t('<p>The Application Secret identifies your application to Square for OAuth authentication.</p>'),
    ];
    $form['sandbox']['sandbox_access_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Sandbox Access Token'),
      '#default_value' => $config->get('sandbox_access_token'),
      '#description' => $this->t('<p>This is one of your sandbox test account authorizations.</p>'),
      '#required' => TRUE,
    ];

    if (!empty($config->get('sandbox_access_token'))) {
      $form['sandbox']['actions']['sync_sandbox_products'] = [
        '#type' => 'submit',
        '#value' => $this->t('Sync Sandbox Products'),
        '#submit' => ['::syncSandboxProducts']
      ];

      if (!empty($sandboxCursor)) {
        $form['sandbox']['actions']['sync_sandbox_products_cursor'] = [
          '#type' => 'submit',
          '#value' => $this->t('Sync Sandbox Products (Next 100 batch)'),
          '#submit' => ['::syncSandboxProductsCursor']
        ];
      }
    }

    $state = \Drupal::state();
    $productionToken = $state->get('commerce_square.production_access_token');
    if (!empty($productionToken)) {
      $form['production'] = [
        '#type' => 'fieldset',
        '#collapsible' => FALSE,
        '#collapsed' => FALSE,
        '#title' => $this->t('Production'),
      ];

      $form['production']['details'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => $this->t('The token from commerce_square module will be used.'),
      ];

      $form['production']['actions']['sync_products'] = [
        '#type' => 'submit',
        '#value' => $this->t('Sync Production Products'),
        '#submit' => ['::syncProducts']
      ];

      if (!empty($productionCursor)) {
        $form['production']['actions']['sync_products_cursor'] = [
          '#type' => 'submit',
          '#value' => $this->t('Sync Production Products (Next 100 batch)'),
          '#submit' => ['::syncProductsCursor']
        ];
      }
    }


    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /*if ($form_state->getValue('example') != 'example') {
      $form_state->setErrorByName('example', $this->t('The value is not correct.'));
    }*/
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('commerce_square_catalog.settings')
      ->set('app_name', $form_state->getValue('app_name'))
      ->set('sandbox_app_id', $form_state->getValue('sandbox_app_id'))
      ->set('sandbox_access_token', $form_state->getValue('sandbox_access_token'))
      ->set('catalog_product_bundle', $form_state->getValue('catalog_product_bundle'))
      ->set('catalog_stores', $form_state->getValue('catalog_stores'))
      ->save();

    // Create the product_bundle field.
    $this->addCatalogIdField($form_state->getValue('catalog_product_bundle'));

    // Try to make a pull if test submission.
    $squareSDK = \Drupal::service('commerce_square_catalog.sdk');
    /**
     * @var \Square\SquareClient $client
     */
    $client = $squareSDK->getClient(FALSE);

    // $locationsApi = $client->getLocationsApi();
    // $apiResponse = $locationsApi->listLocations();
    // dpm($apiResponse);
    // $catalog = $client->getCatalogApi()->listCatalog();

    parent::submitForm($form, $form_state);
  }

  public function syncSandboxProducts(array &$form, FormStateInterface $form_state) {
    return $this->syncProducts($form, $form_state, TRUE);
  }

  public function syncSandboxProductsCursor(array &$form, FormStateInterface $form_state) {
    return $this->syncProducts($form, $form_state, TRUE, TRUE);
  }

  public function syncProductsCursor(array &$form, FormStateInterface $form_state) {
    return $this->syncProducts($form, $form_state, FALSE, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function syncProducts(array &$form, FormStateInterface $form_state, $testMode = FALSE, $useCursor = FALSE) {
    dpm($testMode, "TEST MODE");
    $config = $this->config('commerce_square_catalog.settings');

    // Try to make a pull if test submission.
    $squareSDK = \Drupal::service('commerce_square_catalog.sdk');
    /**
     * @var \Square\SquareClient $client
     */
    $client = $squareSDK->getClient($testMode);

    // $locationsApi = $client->getLocationsApi();
    // $apiResponse = $locationsApi->listLocations();
    // dpm($apiResponse);

    if (!$useCursor) {
      $catalog = $client->getCatalogApi()
        ->listCatalog(NULL, CatalogObjectType::ITEM);
    }
    else {
      // Use the Cursor.
      $tempStore = \Drupal::service('tempstore.shared')->get('commerce_square_catalog');
      if ($testMode) {
        $cursor = $tempStore->get('sandbox:items_import:cursor');
      }
      else {
        $cursor = $tempStore->get('production:items_import:cursor');
      }
      // Safety check.
      if (empty($cursor)) {
        $cursor = null;
      }
      $catalog = $client->getCatalogApi()
        ->listCatalog($cursor, CatalogObjectType::ITEM);
    }

    if ($catalog->isSuccess()) {
      $result = $catalog->getResult();
      // dpm($result, 'result');
    } else {
      $errors = $catalog->getErrors();
      dpm($errors, 'ERRORS');
      return;
    }

    $cursorNext = $catalog->getCursor();
    dpm($cursorNext, 'CURSOR');

    // Might just set cursor anyway, so that once you've done them all, it resets.
    // if (!empty($cursorNext)) {
      // Prep tempStore to stash 'cursor' for pagination.
      $tempStore = \Drupal::service('tempstore.shared')
        ->get('commerce_square_catalog');
      if ($testMode) {
        dpm('SETTING SANDBOX cursor');
        $tempStore->set('sandbox:items_import:cursor', $cursorNext);
      }
      else {
        $tempStore->set('production:items_import:cursor', $cursorNext);
      }
    // }

    $stores = $config->get('catalog_stores');
    foreach ($stores as $store_id => $store) {
      if (!$store) {
        unset($stores[$store_id]);
      }
    }

    $productBundle = $config->get('catalog_product_bundle');
    $productStorage = \Drupal::entityTypeManager()->getStorage('commerce_product');
    $productVariationStorage = \Drupal::entityTypeManager()->getStorage('commerce_product_variation');
    $variation_bundle_id = \Drupal::entityTypeManager()->getStorage('commerce_product_type')->load($productBundle)->getVariationTypeId();
    /**
     * @var \Square\Models\CatalogObject $object
     */
    foreach ($result->getObjects() as $object) {
      if ($object->getType() != 'ITEM') {
        // Do something else, with attributes, etc.
        continue;
      }
      // dpm($object->getCustomAttributeValues(), 'ATTRIBUTES');

      // Check for existing products.
      $existingProductCheck = $productStorage->loadByProperties([
        'square_catalog_id' => $object->getId()
      ]);
      if (!empty($existingProductCheck)) {
        $existingProduct = reset($existingProductCheck);
        dpm('EXISTING PRODUCT FOUND');
        /*$itemData = $object->getItemData();
        dpm($itemData->getVariations());*/
        // Update values...
        continue;
      }

      // Otherwise, create new.
      $itemData = $object->getItemData();

      $product = $productStorage->create([
        'type' => $productBundle,
        'square_catalog_id' => $object->getId(),
        'title' => $itemData->getName(),
        'body' => $itemData->getDescription(),
        'stores' => $stores,
      ]);

      $product->save();

      $catalogVariations = $itemData->getVariations();
      if (empty($catalogVariations)) {
        \Drupal::messenger()->addWarning($itemData->getName() . ' HAD NO VARIATIONS!');
        continue;
      }

      // dpm($catalogVariations);
      $variations = [];
      foreach ($catalogVariations as $catalogVariation) {
        $variationItemData = $catalogVariation->getItemVariationData();
        if (empty($variationItemData)) {
          \Drupal::messenger()->addWarning($itemData->getName() . ' HAD NO VariationItemData!');
          continue;
        }

        // Set up the Variations.

        // Square could have NULL price items...
        if (empty($variationItemData->getPriceMoney())) {
          $price = new \Drupal\commerce_price\Price('0', 'USD');
        }
        else {
          $price = new \Drupal\commerce_price\Price(
            (string) $variationItemData->getPriceMoney()->getAmount() / 100,
            $variationItemData->getPriceMoney()->getCurrency()
          );
        }

        $variationDetails = [
          'type' => $variation_bundle_id,
          'square_catalog_id' => $catalogVariation->getId(),
          // 'uid' => $params['uid'],
          'sku' => $variationItemData->getSku(),
          'title' => $variationItemData->getName(),
          'status' => TRUE,
          //'field_product_image' => $params['fieldProductImage'],
          'price' => $price,
          'product_id' => $product->id(),
          'weight' => new \Drupal\physical\Weight(2, 'oz'),
          'dimensions' => [
            'length' => '2',
            'width' => '2',
            'height' => '2',
            'unit' => LengthUnit::INCH,
          ]
        ];
        // @todo Get actual physical dimension information, would need custom attributes
        // in Square.
        // $variationDetails['weight'] = new \Drupal\physical\Weight(2, 'oz');
        $variations[] = $productVariationStorage->create($variationDetails)->save();
      }
    }

  }

  private function addCatalogIdField(string $bundleId) {
    $product_field_storage = FieldStorageConfig::loadByName('commerce_product', 'square_catalog_id');
    $variation_field_storage = FieldStorageConfig::loadByName('commerce_product_variation', 'square_catalog_id');

    // Create Square Catalog ID reference fields for commerce_product and variation entities.
    $field = FieldConfig::loadByName('commerce_product', $bundleId, 'square_catalog_id');
    if (empty($field)) {
      $field = FieldConfig::create([
        'field_storage' => $product_field_storage,
        'bundle' => $bundleId,
        'label' => 'Square Catalog ID',
        'settings' => [],
      ]);
      $field->save();
    }

    $variation_bundle_id = \Drupal::entityTypeManager()->getStorage('commerce_product_type')->load($bundleId)->getVariationTypeId();
    $field = FieldConfig::loadByName('commerce_product_variation', $variation_bundle_id, 'square_catalog_id');
    if (empty($field)) {
      $field = FieldConfig::create([
        'field_storage' => $variation_field_storage,
        'bundle' => $variation_bundle_id,
        'label' => 'Square Catalog ID',
        'settings' => [],
      ]);
      $field->save();
    }

    // Delete previous setting.
    /*if (!empty($originalBundle)) {
      $field = FieldConfig::loadByName('commerce_product', $originalBundle, 'square_catalog_id');
      if (!empty($field)) {
        $field->delete();
      }

      $variation_bundle_id = \Drupal::entityTypeManager()->getStorage('commerce_product_type')->load($originalBundle)->getVariationTypeId();
      $field = FieldConfig::loadByName('commerce_product_variation', $variation_bundle_id, 'square_catalog_id');
      if (!empty($field)) {
        $field->delete();
      }
    }*/

  }

}
