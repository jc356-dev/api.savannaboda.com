<?php

namespace Drupal\aquila\EventSubscriber;

use Drupal\commerce_order\Event\OrderEvent;
use Drupal\commerce_order\Event\OrderEvents;
use Drupal\commerce_order\OrderTotalSummaryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
// use Drupal\state_machine\Event\WorkflowTransitionEvent;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\Renderer;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Class aquilaOrderPaidSubscriber.
 */
class AquilaOrderPaidSubscriber implements EventSubscriberInterface {

	use StringTranslationTrait;

	/**
	 * The order total summary.
	 *
	 * @var \Drupal\commerce_order\OrderTotalSummaryInterface
	 */
	protected $orderTotalSummary;

	/**
	 * The entity type manager.
	 *
	 * @var \Drupal\Core\Entity\EntityTypeManagerInterface
	 */
	protected $entityTypeManager;

	/**
	 * The entity view builder for profiles.
	 *
	 * @var \Drupal\profile\ProfileViewBuilder
	 */
	protected $profileViewBuilder;

	/**
	 * The renderer.
	 *
	 * @var \Drupal\Core\Render\RendererInterface
	 */
	protected $renderer;

	public function __construct(OrderTotalSummaryInterface $order_total_summary, EntityTypeManagerInterface $entity_type_manager, Renderer $renderer) {
		$this->orderTotalSummary = $order_total_summary;
		$this->entityTypeManager = $entity_type_manager;
		$this->profileViewBuilder = $entity_type_manager->getViewBuilder('profile');
		$this->renderer = $renderer;
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents() {
		// $events['commerce_order.order.paid'] = ['onPaid'];
		$events[OrderEvents::ORDER_PAID] = ['onPaid'];

		return $events;
	}

	/**
	 * Places the order after it has been fully paid through an off-site gateway.
	 *
	 * Off-site payments can only be made at checkout.
	 * If the gateway supports notifications, these two scenarios are possible:
	 *
	 * 1) The onNotify() method is called before the customer returns to the
	 *    site. A payment is created, the order is now considered fully paid,
	 *    causing the "payment" step to no longer be visible, sending the
	 *    customer back to the first checkout step.
	 * 2) The customer never returns to the site. The onNotify() method completed
	 *    the payment, but the order is still unplaced and stuck in checkout.
	 *
	 * To avoid both problems, this subscriber ensures that the order is placed,
	 * which also ensures that the customer is sent to the checkout complete
	 * page once they (eventually) return.
	 *
	 * @param \Drupal\commerce_order\Event\OrderEvent $event
	 *   The event.
	 */
	public function onPaid(OrderEvent $event) {
		$order = $event->getOrder();
		if ($order->getState()->getId() != 'draft') {
			// The order has already been placed.
			return;
		}
		/** @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface $payment_gateway */
		// $payment_gateway = $order->get('payment_gateway')->entity;
		// if (!$payment_gateway) {
		//   // The payment gateway is unknown. This is okay here, especially if they
		//   add order in UI.
		//   return;
		// }
		// \Drupal::logger('onPaid')->debug("<pre>onPaid Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');

		$order->getState()->applyTransitionById('place');
		// A placed order should never be locked.
		$order->unlock();

		// \Drupal::logger('aquila')->debug("Order Paid");
		// \Drupal::logger('onPaid')->debug("<pre>onPaid unlock Adjustment: " . print_r($order->getAdjustments(), TRUE) . '</pre>');
		// \Drupal::logger('aquila')->debug($order->id() . "was paid");
		// \Drupal::logger('aquila')->debug("<pre>" . print_r($order->getState(), TRUE) . "</pre>");
		// dpm($order);
		// dd($order);

		// Customer receipt email.
		// $store = $order->getStore();
		// $storeOwner = $store->getOwner();
		$customer = $order->getCustomer();
		$subject = $this->t('Order #@number confirmed', ['@number' => $order->getOrderNumber() ?? $order->id()]);

		$customerEmail = $customer->getEmail();
		// $state = $order->getState()->getId();

		// Build the version for customer.
		$build = [
			'#theme' => 'aquila_commerce_order_receipt',
			'#order_entity' => $order,
			'#customerEmail' => $customerEmail,
			'#totals' => $this->orderTotalSummary->buildTotals($order),
		];

		// Billing profile details.
		if ($billing_profile = $order->getBillingProfile()) {
			$build['#billing_information'] = $this->profileViewBuilder->view($billing_profile);
		}

		// Shipment details.
		$summary = \Drupal::service('commerce_shipping.order_shipment_summary')->build($order);
		if (!empty($summary)) {
			$build['#shipping_information'] = $summary;
		}

		// Now a version for customer email.
		$customerEmail = $this->renderer->executeInRenderContext(new RenderContext(), function () use ($build) {
			return $this->renderer->render($build);
		});

		// Replicated logic from EmailAction and contact's MailHandler.
		if ($customer->isAuthenticated()) {
			$langcode = $customer->getPreferredLangcode();
		} else {
			$langcode = $this->languageManager->getDefaultLanguage()->getId();
		}

		// Build a $message and $subject, and send mail.
		aquila_send_mail($customer, $customerEmail, $subject, 'order_receipt', $order->getEmail());
	}

}
