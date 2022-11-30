<?php

namespace Drupal\rest_toolkit_commerce\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * RTC Custom Adjustment form.
 *
 * @property \Drupal\rest_toolkit_commerce\RtcCustomAdjustmentInterface $entity
 */
class RtcCustomAdjustmentForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {

    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#description' => $this->t('Label shown to Customer\'s Order as an Adjustment.'),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\rest_toolkit_commerce\Entity\RtcCustomAdjustment::load',
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
      '#description' => $this->t('Description of the rtc custom adjustment.'),
    ];

    $form['adjustment_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Adjustment Value'),
      '#default_value' => $this->entity->get('adjustment_value'),
      '#description' => $this->t('The percentage added to Order as an Adjustment.'),
      '#step' => 0.1,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $message = $result == SAVED_NEW
      ? $this->t('Created new rtc custom adjustment %label.', $message_args)
      : $this->t('Updated rtc custom adjustment %label.', $message_args);
    $this->messenger()->addStatus($message);
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }

}
