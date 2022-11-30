<?php

namespace Drupal\rest_toolkit_commerce\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\rest_toolkit_commerce\RTCommerceServiceInterface;

/**
 * Provides automated tests for the rest_toolkit_commerce module.
 */
class RTCommerceControllerTest extends WebTestBase {

  /**
   * Drupal\rest_toolkit_commerce\RTCommerce definition.
   *
   * @var Drupal\rest_toolkit_commerce\RTCommerceServiceInterface
   */
  protected $rtCommerce;


  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return [
      'name' => "rest_toolkit_commerce RTCommerceController's controller functionality",
      'description' => 'Test Unit for module rest_toolkit_commerce and controller RTCommerceController.',
      'group' => 'Other',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
  }

  /**
   * Tests rest_toolkit_commerce functionality.
   */
  public function testRTCommerceController() {
    // Check that the basic functions of module rest_toolkit_commerce.
    $this->assertEquals(TRUE, TRUE, 'Test Unit Generated via Drupal Console.');
  }

}
