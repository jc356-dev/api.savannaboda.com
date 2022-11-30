<?php

namespace Drupal\rest_toolkit_commerce;

/**
 * Interface RTCommerceServiceInterface.
 */
interface RTCommerceServiceInterface {

    /**
   * Based on how we are using the system.  Any time we start a new order,
   * delete the previous draft(s).
   *
   * @param  [type] $uid [description]
   * @return [type]      [description]
   */
  public function deleteAllDraftOrders($uid, $type = 'default');

  /**
   * Logic to ensure the user account whether anonymous or authenticated.
   *
   * @param $postData
   *   The POSTed values.
   *
   * @return $user
   *   The user to attach to the order as customer.
   */
  public function ensureOrderUser($postData);

  /**
   * Determine shipping address.
   *
   * @param  [type] $postData [description]
   * @param  [type] $user     [description]
   * @return [type]           [description]
   */
  public function discernShippingAddress($postData, $user);

  /**
   * Determine billing address.
   *
   * @param  [type] $postData [description]
   * @param  [type] $user     [description]
   * @return [type]           [description]
   */
  public function discernBillingAddress($postData, $user);

  /**
   * Prepare shipments using commerce_shipping.packer_manager.
   *
   * @param      <type>  $order            The order
   * @param      <type>  $shippingProfile  The shipping profile
   */
  public function prepareShipments(&$order, $shippingProfile);

  /**
   * Loads all applicable commerce_shipping_methods for this shipment.
   *
   * @param ShipmentInterface $shipment
   *   The shipment.
   *
   * @return array $shipping_methods
   *   The available shipping_methods.
   */
  public function loadAllShippingMethodsAvailable(\Drupal\commerce_shipping\Entity\ShipmentInterface $shipment);

  /**
   * Determines the payment gateway.
   *
   * @todo Needs some way to decide what gateway to use.  Could be setting.
   *
   * @return CommercePaymentGateway
   */
  public function determinePaymentGateway();

  /**
   * { function_description }
   *
   * $postData needs:
   * 'payment': {
      'number': '4444424444444440',
      'expiration': {
        'month': '12',
        'year': '2026'
       },
      'name_on_account': 'Jeff Testerson',
      'security_code': '999'
      'mail': 'OPTIONALemail@example.com'
   }
   *
   * @param      <type>  $order            The order
   * @param      <type>  $merchantAccount  The merchant account
   * @param      <type>  $postData         The post data
   * @param      <type>  $reusable         The reusable
   *
   * @return     <type>  ( description_of_the_return_value )
   */
  public function preparePayment($order, $paymentGateway, $postData, $reusable = TRUE);

}
