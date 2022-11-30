<?php

namespace Drupal\rest_toolkit_commerce\Packer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\physical\Calculator;
use Drupal\physical\Weight;
use Drupal\physical\WeightUnit;
use Drupal\profile\Entity\ProfileInterface;
use Drupal\commerce_shipping\Packer\PackerInterface;
use Drupal\commerce_shipping\ShipmentItem;
use Drupal\commerce_shipping\ProposedShipment;

/**
 * Creates multiple shipment based on product types.
 */
class RTCommercePacker implements PackerInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new DefaultPacker object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function applies(OrderInterface $order, ProfileInterface $shipping_profile): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function pack(OrderInterface $order, ProfileInterface $shipping_profile): array {
    $proposed_shipments = [];
    $packages = [];

    foreach ($order->getItems() as $order_item) {
      $purchased_entity = $order_item->getPurchasedEntity();

      if (!$purchased_entity || !$purchased_entity->hasField('weight')) {
        continue;
      }

      // The weight will be empty if the shippable trait was added but the
      // existing entities were not updated.
      if ($purchased_entity->get('weight')->isEmpty()) {
        $purchased_entity->set('weight', new Weight(0, WeightUnit::GRAM));
      }

      $quantity = $order_item->getQuantity();
      if (Calculator::compare($order_item->getQuantity(), '0') == 0) {
        continue;
      }
      /** @var \Drupal\physical\Weight $weight */
      $weight = $purchased_entity->get('weight')->first()->toMeasurement();

      $packages[$purchased_entity->bundle()][] = new ShipmentItem([
        'order_item_id' => $order_item->id(),
        'title' => $order_item->getTitle(),
        'quantity' => $quantity,
        'weight' => $weight->multiply($quantity),
        'declared_value' => $order_item->getUnitPrice()->multiply($quantity),
      ]);

    }

    $shipment_index = 1;
    foreach ($packages as $type => $pack) {
      // $this->debug($this->getShipmentType($order));
      if (!empty($pack)) {
        // $proposed_shipments[]
        $proposed_shipments[] = new ProposedShipment([
          'type' => $this->getShipmentType($order),
          'order_id' => $order->id(),
          'title' => "Shipment #{$shipment_index}",
          'items' => $pack,
          'shipping_profile' => $shipping_profile,
        ]);
        $shipment_index++;
      }
    }

    return $proposed_shipments;
  }

  /*public function debug($var = '') {
    $debug = print_r($var, TRUE);
    $output = 'DEBUG: <pre>' . $debug . '</pre>';
    \Drupal::logger('rest_toolkit')->debug($output);
  }*/

  /**
   * Gets the shipment type for the current order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order.
   *
   * @return string
   *   The shipment type.
   */
  protected function getShipmentType(OrderInterface $order) {
    $order_type_storage = $this->entityTypeManager->getStorage('commerce_order_type');
    /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $order_type */
    $order_type = $order_type_storage->load($order->bundle());

    return $order_type->getThirdPartySetting('commerce_shipping', 'shipment_type');
  }
}
