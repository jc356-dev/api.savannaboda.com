<?php

namespace Drupal\rest_toolkit_commerce\Controller;

use Drupal\commerce_order\Adjustment;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\CreditCard;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentMethod;
use Drupal\Core\Controller\ControllerBase;
use Drupal\rest_toolkit\RestToolkitEndpointTrait;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Class RTCommerceController.
 */
class RTCommerceController extends ControllerBase {
	use RestToolkitEndpointTrait {
		RestToolkitEndpointTrait::__construct as private __rtConstruct;
	}

	/**
	 * Drupal\rest_toolkit_commerce\RTCommerce definition.
	 *
	 * @var Drupal\rest_toolkit_commerce\RTCommerceServiceInterface
	 */
	protected $rtCommerce;

	public function __construct(Request $request) {
		// Call the RestToolkit constructor.
		$this->__rtConstruct($request);

		// Load Commerce service.
		$this->rtCommerce = \Drupal::service('rest_toolkit_commerce.commerce');
	}

	/**
	 * {@inheritdoc}
	 */
	/*public static function create(ContainerInterface $container) {
		        $instance = parent::create($container);
		        $instance->request = $container->get('request_stack')->getCurrentRequest();
		        $instance->rtCommerce = $container->get('rest_toolkit_commerce.commerce');
		        return $instance;
	*/

	/**
	 * Create an Order.
	 *
	 * Proposed:
	 *

	mail: "something@example.com",  // Optional, for guest checkout.
	uid: 59,  // Optional, for guest checkout.
	billing_profile: 5,  // Profile id if using existing address, OR:
	// If creating new billing_address:
	'billing_address': {
	'country_code' => 'us',
	'address_line1' => '1234 Some Street',
	'address_line2' => 'Suite 202',
	'locality' => 'Carlsbad',
	'administrative_area' => 'CA',
	'postal_code' => '92008',
	},
	shipping_same_as_billing: true/false,
	shipping_profile: 5,  // Profile id if using existing address, OR:
	shipping_address: {},  // If creating new address.
	orders: [
	{
	store: 222,
	variations: [
	{
	id: 123,
	qty: 1
	}
	]
	}
	]

	 *
	 * @return array
	 *   Return serialized Order created.
	 */
	public function orderPost() {
		// To create entity.
		// $entity = $this->deNormalizePostData(TRUE);

		// If no orders to process, return empty.
		if (empty($this->postData['orders'])) {
			return $this->send400('No orders to process.');
		}

		// Determine user for this order.
		$user = $this->rtCommerce->ensureOrderUser($this->postData);

		// Anonymous cannot create order.
		if (!$user->id()) {
			return $this->send400('You must log in or provide an email address to checkout.');
		}

		$createdOrders = [];
		// This allows the Order email address to be returned.
		$this->normalizerContext['is_owner'] = TRUE;

		// Load this user's draft orders and delete before proceeding.
		$this->rtCommerce->deleteAllDraftOrders($user->id(), 'default');

		// If we have a shipping address, this should be a shippable order.
		// Look at all products of type shippable, and create a Shipment.
		$shippingProfile = $this->rtCommerce->discernShippingAddress($this->postData, $user);
		// If neither hit, it's not a shippable order.

		// If billing_profile id is sent.
		$billingProfile = $this->rtCommerce->discernBillingAddress($this->postData, $user);

		if (empty($billingProfile)) {
			$this->send400('No billing address could be determined.');
		}

		// If set to be the same.
		if (!empty($this->postData['shipping_same_as_billing'])) {
			$shippingProfile = $billingProfile;
		}

		// Here could potentially be a scenario where we didn't want shipping same
		// as billing, but also appropriate shipping fields were not provided.
		// We could stop here, or handle later when we determine if this is a
		// shippable Order.  The latter is what I'm currently doing.
		// dd('Post Data', $this->postData['orders']);
		// There is a separate Order per Store.
		foreach ($this->postData['orders'][0] as $storeOrder) {

			// dd('variations', $storeOrder['variations']);
			if (empty($storeOrder['variations'])) {
				continue;
			}

			// Set up scaffolding for creating the Order.
			$shippable = FALSE;
			// @todo This needs to be based on the Order or product settings!
			// $shippable = TRUE;
			$order = Order::create([
				'type' => 'default',
				'state' => 'draft',
				'mail' => $user->getEmail(),
				'uid' => $user->id(),
				//'ip_address' => '127.0.0.1',
				'store_id' => $storeOrder['store'],
				// This will need to be set based on payment details.
				'billing_profile' => $billingProfile,
			]);

			// Add the variations as order_items for each Order.
			foreach ($storeOrder['variations'] as $productVariation) {
				if (!empty($productVariation['id'])) {
					$varID = $productVariation['id'];
				} elseif (!empty($productVariation['variation_id'])) {
					$varID = $productVariation['variation_id'];
				}

				if (empty($varID)) {
					$this->debug('Error for variation in order NO id or variation_id, for email: ' . $user->getEmail() . ' CHECK NEXT LOG variation: ');
					// $this->debug($productVariation);
					continue;
				}

				$variation = \Drupal::entityTypeManager()->getStorage('commerce_product_variation')->load($varID);
				// Debug section.
				if (empty($variation)) {
					$this->debug('Error finding a variation in order, for email: ' . $user->getEmail() . ' CHECK NEXT LOG variation: ');
					// $this->debug($productVariation);
					continue;
				}
				// End debug section.
				try {
					$order_item = \Drupal::entityTypeManager()
						->getStorage('commerce_order_item')
						->createFromPurchasableEntity($variation);
					$order_item->setQuantity($productVariation['qty']);
					$order_item->save();
					$order->addItem($order_item);
				} catch (\Throwable $e) {
					\Drupal::logger('rest_toolkit_commerce')->error($e->getMessage());
					return $this->send400('There was a problem with one or more products: ' . $e->getMessage());
				}

				// If there are any shippable items on this order.
				// This follows the same logic check as ShippingInformation::isVisible().
				if ($variation->hasField('weight')) {
					$shippable = TRUE;
				}
				// @todo Base on variation settings!
				/*if ($variation->bundle() == 'default') {
	            $shippable = TRUE;
*/
			}

			// This order should be shipped.
			if ($shippable) {
				if (empty($shippingProfile)) {
					// @todo Determine if we need to clean up saved order_items here.
					$this->send400('No shipping address could be determined.');
				}

				// $order->field_shipping_profile = $shippingProfile;
				// Save the order first so it has an order_id.
				$order->setRefreshState(OrderInterface::REFRESH_SKIP);
				$order->save();

				/*$order->addAdjustment(new Adjustment([
					                    // 'type' => 'shipping',
					                    'type' => 'custom',
					                    'label' => t('Shipping'),
					                    'amount' => $shipmentAmount,
					                    // 'source_id' => $shipment->id(),
				*/
				// $order->setRefreshState(Order::REFRESH_ON_SAVE);

				// Create shipment entity.
				// JW - 12-16-2020:
				// Now we're not actually doing shipments, but a pseudo system based
				// just on a price field on products and customer shipping province.
				//
				/*$this->debug($shippingProfile->toArray());
        inspect($shippingProfile);*/
				$shipments = $this->rtCommerce->prepareShipments($order, $shippingProfile);
				// $this->debug($shipments);
				// At this point, there should be shipments.  Error otherwise.
				if (empty($shipments)) {
					// One of your orders cannot by fulfilled by our current shipment options.
					$this->send400('There was a problem creating shipments for this order.');
				}

/*        $order->setRefreshState(OrderInterface::REFRESH_SKIP);
$order->save();
 */

				/*$event = new ShippingRatesEvent($rates, $shipping_method, $shipment);
					                  $this->eventDispatcher->dispatch(ShippingEvents::SHIPPING_RATES, $event);
				*/

				// Reload the order?
				// This is to solve for what was done in commerce_printful,
				// to add Tax adjustment in shipment calc event.
				$order = Order::load($order->id());

				// Now that Shipments have ran, some are adding tax in there.
				// THIS IS TEMPORARY FOR commerce_printful that adds tax during shipping
				// rates event.  Should be done another way!!!
				// It is fine though, in case Taxes may be coming from multiple sources,
				// we can clean it up here into just 1 line item.
				$orderAdjustments = $order->getAdjustments(['tax']);
				if (!empty($orderAdjustments)) {
					// Only a problem if there is more than 1.
					if (count($orderAdjustments) > 1) {
						$keepAdjustment = array_pop($orderAdjustments);
						foreach ($orderAdjustments as $remainingAdjustment) {
							/*$this->debug('REMOVING 2ND TAX: ');
              $this->debug($remainingAdjustment);*/
							$order->removeAdjustment($remainingAdjustment);
						}
					}
				}

				// Check for extra Adjustment config options.
				// $config_factory = \Drupal::service('config.factory');
				$rtCustomAdjustments = \Drupal::entityTypeManager()
					->getStorage('rtc_custom_adjustment')
					->loadMultiple();
				if (!empty($rtCustomAdjustments)) {
					$subtotal = $order->getSubtotalPrice();
					foreach ($rtCustomAdjustments as $rtCustomAdjustment) {
						$adjustmentFactor = $rtCustomAdjustment->getAdjustmentValueNumber() / 100;
						$adjustmentAmount = $subtotal->multiply($adjustmentFactor);

						$order->addAdjustment(new Adjustment([
							'type' => 'custom',
							'label' => $rtCustomAdjustment->label(),
							'amount' => $adjustmentAmount,
							// 'percentage' => $adjustmentFactor,
							'source_id' => $rtCustomAdjustment->id(),
							'included' => FALSE,
						]));
					}
				}

				// Assign the shipments to the order.
				$order->set('shipments', $shipments);

				foreach ($shipments as $shipment) {
					$order->addAdjustment(new Adjustment([
						'type' => 'shipping',
						// 'label' => t('Shipping'),
						'label' => $shipment->getTitle(),
						'amount' => $shipment->getAmount(),
						'source_id' => $shipment->id(),
					]));
					// $order->setRefreshState(Order::REFRESH_ON_SAVE);
					$order->setRefreshState(Order::REFRESH_SKIP);
				}

			}

			// Finalize the order.
			$order->save();

			$this->normalizerContext['expand_entities'][] = 'billing_profile';
			$flattenedOrder = $this->normalizeEntity($order, TRUE);
			// Should no longer need to do this, using field_shipping_profile so that
			// this data is actually saved with the Order.
			/*$flattenedShippingProfile = $this->normalizeEntity($shippingProfile, TRUE);

      $flattenedOrder['shipping_profile'] = $flattenedShippingProfile;*/
			// @todo Now normalizer is finding order.adjustments correctly.
			// Continue to montior, to see if it correctly catches all Adjustments
			// to determine if this is still required.
			$fetchedAdjustments = $order->getAdjustments();
			foreach ($fetchedAdjustments as $anAdjustment) {
				$adjustmentArray = $anAdjustment->toArray();
				$adjustmentArray['amount'] = $adjustmentArray['amount']->toArray();
				$adjustmentArray['amount']['number'] = round((float) $adjustmentArray['amount']['number'], 2);
				$flattenedOrder['adjustments_rtapi'][] = $adjustmentArray;
			}

			$createdOrders[] = $flattenedOrder;
		}

		return $this->sendResponse($createdOrders);
	}

	/**
	 * $this->postData must include:


	PATCH /rtapi/v1/commerce/order/{order_id}
	{
	"payment": {
	"number": "4444424444444440",
	"expiration": {
	"month": "12",
	"year": "2024"
	},
	"name_on_account": "Jeff Testerson",
	"security_code": "222",
	}
	}

	 *
	 * @param      <type>  $order  The order
	 *
	 * @return     <type>  ( description_of_the_return_value )
	 */
	public function finalizeOrderPatch(Order $order) {
		$return = [];
		// If order is already paid.
		// @todo Handle what to do if total is zero to begin with?
		// if ($order->isPaid() || $order->getTotalPrice()->isZero()) {
		if ($order->isPaid()) {
			return $this->send400('This order is already paid.');
		}

		// Set some Order metadata.
		$order->setOrderNumber($order->id());
		$order->setPlacedTime(time());

		$gateway = $this->rtCommerce->determinePaymentGateway();
		if (empty($gateway)) {
			return $this->send400('Problem determining a gateway.');
		}

		$paymentGatewayPlugin = $gateway->getPlugin();

		// Create the PaymentMethod and prepare the Payment.
		// Can throw HardDeclineException.
		try {
			$payment = $this->rtCommerce->preparePayment($order, $gateway, $this->postData, TRUE);
		} catch (\Throwable $e) {
			inspect($e);
			\Drupal::logger('rest_toolkit_commerce')->error($e->getMessage());
			return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}

		// Send the payment to the processor to attempt to pay and ->save().
		try {
			$paymentGatewayPlugin->createPayment($payment, TRUE);
		} catch (\Throwable $e) {
			// $this->debug($e);

			$this->send400('There was a problem with payment, no charge has occurred: ' . $e->getMessage());
		}

		// Send the response.
		$return = [
			'order' => $order,
			'payment' => $payment,
		];
		$this->normalizerContext['expand_entities'][] = 'order_items';
		$this->normalizerContext['expand_entities'][] = 'purchased_entity';

		return $this->sendNormalizedResponse($return);
	}

	/**
	 * $this->postData must include:


	PATCH /rtapi/v1/commerce/order/{order_id}
	{
	"payment": {
	"number": "4444424444444440",
	"expiration": {
	"month": "12",
	"year": "2024"
	},
	"name_on_account": "Jeff Testerson",
	"security_code": "222",
	}
	}

	 *
	 * @param      <type>  $order  The order
	 *
	 * @return     <type>  ( description_of_the_return_value )
	 */
	public function finalizeStripeOrderPatch(Order $order, Request $request) {
		// \Drupal::logger('finalizeStripeOrderPatch')->debug("<pre>Original Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');
		$encoders = [new JsonEncoder()];
		$normalizers = [new GetSetMethodNormalizer()];

		$serializer = new Serializer($normalizers, $encoders);

		$content = $request->getContent();
		$postData = $serializer->decode($content, 'json');
		$postData = (object) $postData;
		// dd($postData, $postData->payment['stripe_payment_method_id']);
		$return = [];
		// If order is already paid.
		// @todo Handle what to do if total is zero to begin with?
		// if ($order->isPaid() || $order->getTotalPrice()->isZero()) {
		if ($order->isPaid()) {
			return $this->send400('This order is already paid.');
		}

		// Set some Order metadata.
		$order->setOrderNumber($order->id());
		$order->setPlacedTime(time());

		// \Drupal::logger('finalizeStripeOrderPatch')->debug("<pre>setPlacedTime Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');

		$gateway = $this->rtCommerce->determinePaymentGateway();
		if (empty($gateway)) {
			return $this->send400('Problem determining a gateway.');
		}

		$paymentGatewayPlugin = $gateway->getPlugin();

		// // Create the payment method.
		try {
			$paymentMethodType = $paymentGatewayPlugin->getDefaultPaymentMethodType();
			$paymentDetails = [
				'stripe_payment_method_id' => $postData->payment['stripe_payment_method_id'],
				'brand' => $postData->payment['card_type'],
				'last4' => $postData->payment['last4'],
				'exp_month' => $postData->payment['exp_month'],
				'exp_year' => $postData->payment['exp_year'],
			];
			// // Create the inital (unsaved) PaymentMethod.
			$expires = CreditCard::calculateExpirationTimestamp($postData->payment['exp_month'], $postData->payment['exp_year']);

			$paymentMethod = PaymentMethod::create([
				'payment_gateway' => $gateway->id(),
				'type' => $paymentMethodType->getPluginId(),
				'uid' => $order->getCustomer(),
				'reusable' => TRUE,
				'remote_id' => $postData->payment['stripe_payment_method_id'],
				'card_type' => $postData->payment['card_type'],
				'card_number' => $postData->payment['last4'],
				'card_exp_month' => $postData->payment['exp_month'],
				'card_exp_year' => $postData->payment['exp_year'],
				'billing_profile' => $order->getBillingProfile(),
				//'is_default' => TRUE,
				//'expires' => get timestamp from card exp date,
			]);

			$paymentMethod->setExpiresTime($expires);
			$paymentMethod->save();

			// $paymentGatewayPlugin->createPaymentMethod($paymentMethod, $paymentDetails);
			// $paymentGatewayPlugin->createPaymentMethod('card', $paymentDetails);
		} catch (\Throwable $e) {
			// $this->debug($e);
			$this->send400('There was a problem creating the payment method, no charge has occurred: ' . $e->getMessage());
		}

		try {
			$order->set('payment_gateway', $gateway);
			$order->set('payment_method', $paymentMethod);
			// \Drupal::logger('finalizeStripeOrderPatch')->debug("<pre>payment_method Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');
		} catch (\Throwable $e) {
			inspect($e);
			// Now throw it for the parent.
			// @todo Even bother catching it here?
			throw $e;
			//return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}

		// Create the PaymentMethod and prepare the Payment.
		// Can throw HardDeclineException.
		try {
			// $payment = $this->rtCommerce->preparePayment($order, $gateway, $this->postData, TRUE);

			$payment = Payment::create([
				'state' => 'new',
				'type' => 'payment_default',
				'amount' => $order->getTotalPrice(),
				'payment_gateway' => $gateway->id(),
				'order_id' => $order->id(),
				'remote_id' => $postData->payment['stripe_payment_method_id'],
				'payment_method' => $paymentMethod,
				'payment_gateway_mode' => $gateway->getPlugin()->getMode(),
				// 'expires' => 0,
				// 'uid' => $order->getCustomerId(),
			]);
			$payment->save();

			// \Drupal::logger('finalizeStripeOrderPatch')->debug("<pre>after payment Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');
		} catch (\Throwable $e) {
			inspect($e);
			\Drupal::logger('rest_toolkit_commerce')->error($e->getMessage());
			return $this->send400('There was a problem using this card.  Please double check the values you input and try again.');
		}

		// Set order to fullfiled.
		try {
			$payment->setAuthorizedTime(strtotime('Now'));
			$payment->setState('completed');
			$payment->save();

			// $order->set('state', 'Fulfillment')->save();

			// \Drupal::logger('finalizeStripeOrderPatch')->debug("<pre>Fulfillment Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');
		} catch (\Throwable $e) {
			// $this->debug($e);

			$this->send400('There was a problem with payment, no charge has occurred: ' . $e->getMessage());
		}

		// try {
		// 	$this->rtCommerce->send_receipt($order);
		// } catch (\Throwable $e) {
		// 	dd($e);
		// }
		// Send the response.
		$return = [
			'order' => $order,
			'payment' => $payment,
		];
		$this->normalizerContext['expand_entities'][] = 'order_items';
		$this->normalizerContext['expand_entities'][] = 'purchased_entity';

		return $this->sendNormalizedResponse($return);
	}

	/**
	 *
	 * POST JSON:
	 * {
	 *   "promo_code": "ABZ123"
	 * }
	 *
	 * @param \Drupal\commerce_order\Entity\Order $order
	 *
	 * @return mixed
	 * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
	 * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
	 * @throws \Drupal\Core\Entity\EntityStorageException
	 */
	public function applyPromoCodeToOrder(Order $order) {
		// If already paid, we can't apply a coupon to it...
		if ($order->isPaid() || $order->getTotalPrice()->isZero()) {
			return $this->send400('This order is already paid.');
		}

		if (empty($this->postData['promo_code'])) {
			return $this->send400('Empty promo code.');
		}

		$couponCheck = \Drupal::entityTypeManager()->getStorage('commerce_promotion_coupon')
			->loadByProperties(['code' => $this->postData['promo_code']]);

		if (empty($couponCheck)) {
			return $this->send400('Promo code not found.');
		}

		$coupon = reset($couponCheck);

		// Make sure this coupon (discount) isn't already applied.
		$apply = TRUE;
		$orderCoupons = $order->coupons->getValue();

		if (!empty($orderCoupons)) {
			foreach ($orderCoupons as $orderCoupon) {
				if ($orderCoupon['target_id'] == $coupon->id()) {
					$apply = FALSE;
					return $this->send400('That coupon has already been applied to this order.');
					break;
				}
			}
		}

		// Apply the coupon.
		if ($apply) {
			$order->get('coupons')->appendItem($coupon);
		}

		$order->save();

		return $this->sendResponse($order);
	}

}
