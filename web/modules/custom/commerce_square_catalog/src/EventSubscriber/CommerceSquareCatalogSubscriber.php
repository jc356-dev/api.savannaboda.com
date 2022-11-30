<?php

namespace Drupal\commerce_square_catalog\EventSubscriber;

use Drupal\commerce_order\Event\OrderItemEvent;
use Drupal\commerce_test\Plugin\Action\ThrowException;
use Drupal\Core\Messenger\MessengerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
//use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
//use Symfony\Component\HttpKernel\Event\GetResponseEvent;
//use Symfony\Component\HttpKernel\KernelEvents;
use Drupal\commerce_order\Event\OrderEvents;

/**
 * Commerce Square Catalog event subscriber.
 */
class CommerceSquareCatalogSubscriber implements EventSubscriberInterface {

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs event subscriber.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(MessengerInterface $messenger) {
    $this->messenger = $messenger;
  }

  /**
   * Kernel request event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   Response event.
   */
  /*public function onKernelRequest(GetResponseEvent $event) {
    $this->messenger->addStatus(__FUNCTION__);
  }*/

  /**
   * Kernel response event handler.
   *
   * @param \Symfony\Component\HttpKernel\Event\FilterResponseEvent $event
   *   Response event.
   */
  /*public function onKernelResponse(FilterResponseEvent $event) {
    $this->messenger->addStatus(__FUNCTION__);
  }*/

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [];
    // JW - 3-30-2022, Commenting since these are no longer being unpublished.
    // Prevents error when out of stock added to order.
    // return [
    //   OrderEvents::ORDER_ITEM_INSERT => 'OnCommerceOrderItemInsert'
    // ];
  }


  /**
   * @param \Drupal\commerce_order\Event\OrderItemEvent $event
   *
   * @return void
   * @throws \Exception
   */
  public function OnCommerceOrderItemInsert(OrderItemEvent $event) {
    $orderItem = $event->getOrderItem();
    $order = $orderItem->getOrder();

    // Nothing to do if there isn't a product_variation attached.
    if (!$orderItem->hasPurchasedEntity()) {
      return;
    }

    $variation = $orderItem->getPurchasedEntity();
    if (!$variation->hasField('square_catalog_id') || $variation->square_catalog_id->isEmpty()) {
      return;
    }

    $catalogID = $variation->square_catalog_id->value;
    $squareSDK = \Drupal::service('commerce_square_catalog.sdk');
    /**
     * @var \Square\SquareClient $client
     */
    // Need toggle for $testMode.
    $client = $squareSDK->getClient(FALSE);
    $inventoryApi = $client->getInventoryApi();

    $response = $inventoryApi->retrieveInventoryCount($catalogID);
    if (!$response->isSuccess()) {
      return;
    }

    /**
     * @var \Square\Models\RetrieveInventoryCountResponse $result
     */
    $result = $response->getResult();
    /**
     * @var \Square\Models\InventoryCount $count
     */
    $counts = $result->getCounts();

    foreach ($counts as $count) {
      /*inspect('ANOTHER COUNT: ' . $count->getState());
      inspect($count->getQuantity());*/

      // If there aren't enough in inventory, need to do a few things:
      // - Remove from current Order (how do we find it?) - Could just remove from
      // all Orders in draft state.
      // Inform the user somehow...  Throw exception to prevent a response that
      // creates an Order sending it to client.
      if ($count->getQuantity() < 1) {
        throw new \Exception("Product out of stock: {$orderItem->getTitle()}.");
      }
    }

  }


}
