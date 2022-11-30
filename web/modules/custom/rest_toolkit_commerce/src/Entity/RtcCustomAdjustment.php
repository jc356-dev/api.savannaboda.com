<?php

namespace Drupal\rest_toolkit_commerce\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\rest_toolkit_commerce\RtcCustomAdjustmentInterface;

/**
 * Defines the rtc custom adjustment entity type.
 *
 * @ConfigEntityType(
 *   id = "rtc_custom_adjustment",
 *   label = @Translation("RTC Custom Adjustment"),
 *   label_collection = @Translation("RTC Custom Adjustments"),
 *   label_singular = @Translation("rtc custom adjustment"),
 *   label_plural = @Translation("rtc custom adjustments"),
 *   label_count = @PluralTranslation(
 *     singular = "@count rtc custom adjustment",
 *     plural = "@count rtc custom adjustments",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\rest_toolkit_commerce\RtcCustomAdjustmentListBuilder",
 *     "form" = {
 *       "add" = "Drupal\rest_toolkit_commerce\Form\RtcCustomAdjustmentForm",
 *       "edit" = "Drupal\rest_toolkit_commerce\Form\RtcCustomAdjustmentForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm"
 *     }
 *   },
 *   config_prefix = "rtc_custom_adjustment",
 *   admin_permission = "administer rtc_custom_adjustment",
 *   links = {
 *     "collection" = "/admin/structure/rtc-custom-adjustment",
 *     "add-form" = "/admin/structure/rtc-custom-adjustment/add",
 *     "edit-form" = "/admin/structure/rtc-custom-adjustment/{rtc_custom_adjustment}",
 *     "delete-form" = "/admin/structure/rtc-custom-adjustment/{rtc_custom_adjustment}/delete"
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "description",
 *     "adjustment_value"
 *   }
 * )
 */
class RtcCustomAdjustment extends ConfigEntityBase implements RtcCustomAdjustmentInterface {

  /**
   * The rtc custom adjustment ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The rtc custom adjustment label.
   *
   * @var string
   */
  protected $label;

  /**
   * The rtc custom adjustment status.
   *
   * @var bool
   */
  protected $status;

  /**
   * The rtc_custom_adjustment description.
   *
   * @var string
   */
  protected $description;

  /**
   * The rtc_custom_adjustment adjustment_value.
   *
   * @var string
   */
  protected $adjustment_value;

  public function getAdjustmentValue() {
    return $this->adjustment_value;
  }

  public function getAdjustmentValueNumber() {
    return doubleval($this->adjustment_value);
  }

}
