<?php

namespace Drupal\ids\Plugin\Field\FieldWidget;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\Element\EntityAutocomplete;
use Drupal\link_attributes\LinkAttributesManager;
use Drupal\link_attributes\Plugin\Field\FieldWidget\LinkWithAttributesWidget;

/**
 * An implementation of the link widget that can reference all entity types.
 *
 * @FieldWidget(
 *   id = "link_entity_types",
 *   label = @Translation("Link widget - all entity types (with attributes)"),
 *   field_types = {
 *     "link",
 *   }
 * )
 */
class LinkEntityTypesWidget extends LinkWithAttributesWidget implements ContainerFactoryPluginInterface {

  /**
   * An array of content entities which support canonical links.
   *
   * @var array
   */
  protected static $linkableEntities;

  /**
   * The link attributes manager.
   *
   * @var \Drupal\link_attributes\LinkAttributesManager
   */
  protected $linkAttributesManager;

  /**
   * The entity storage handler for loading user_role config entity instances.
   *
   * @var Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a LinkEntityTypesWidget object.
   *
   * @param string $plugin_id
   *   The plugin_id for the widget.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the widget is associated.
   * @param array $settings
   *   The widget settings.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\link_attributes\LinkAttributesManager $link_attributes_manager
   *   The link attributes manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The Drupal entity type manager service.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, array $third_party_settings, LinkAttributesManager $link_attributes_manager, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings, $link_attributes_manager);

    $this->linkAttributesManager = $link_attributes_manager;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['third_party_settings'],
      $container->get('plugin.manager.link_attributes'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'allowed_entity_types' => ['node'],
    ] + parent::defaultSettings();
  }

  /**
   * Returns a list of content entity types that have a canonical link template.
   *
   * @return array
   *   An array of content entity types which support canonical links. The key
   *   is the entity machine name and the value is the entity type label.
   */
  protected function getLinkableEntities() {
    if (!self::$linkableEntities) {
      self::$linkableEntities = [];
      $entity_types = $this->entityTypeManager->getDefinitions();

      foreach ($entity_types as $key => $type) {
        if ($type instanceof ContentEntityTypeInterface && $type->hasLinkTemplate('canonical')) {
          self::$linkableEntities[$key] = $type->getLabel();
        }
      }
    }

    return self::$linkableEntities;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element = parent::formElement($items, $delta, $element, $form, $form_state);
    $allowed_entity_types = array_filter($this->getSetting('allowed_entity_types'));

    if ($this->supportsInternalLinks() && $allowed_entity_types) {
      $linkable_entity_types = $this->getLinkableEntities();
      $options = array_intersect_key($linkable_entity_types, $allowed_entity_types);

      $uri = $items[$delta]->uri;

      if (!empty($uri) && preg_match('#entity:([a-z_]+)/(\d+|[a-z_]+)$#', $uri, $matches)) {
        $defaultType = $matches[1];
      }
      elseif (empty($options) || isset($options['node'])) {
        $defaultType = 'node';
      }
      else {
        reset($options);
        $defaultType = key($options);
      }

      if (count($options) > 1) {
        $element['entity_type'] = [
          '#type' => 'select',
          '#title' => $this->t('Select entity type to search for'),
          '#weight' => -50,
          '#options' => $options,
          '#default_value' => $defaultType,
        ];
      }
      else {
        $element['entity_type'] = [
          '#type' => 'value',
          '#value' => $defaultType,
          '#default_value' => $defaultType,
        ];
      }

      $element['#process'] = [static::class . '::processWidget'];
    }

    // Hide attributes details if none enabled.
    if (count(array_filter($this->getSetting('enabled_attributes'))) < 1) {
      unset($element['options']['attributes']);
    }

    return $element;
  }

  /**
   * Set up ajax on the entity type select element.
   *
   * @param array $element
   *   Reference to the form element array passed from the form definition.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   Current form build and state information.
   * @param array $complete_form
   *   Reference to the complete form definition.
   *
   * @return array
   *   The processed element.
   */
  public static function processWidget(array &$element, FormStateInterface $form_state, array &$complete_form) {
    $htmlId = Html::cleanCssIdentifier(implode('-', $element['#array_parents']) . '-link-entity-types-wrapperer');

    $element['entity_type']['#ajax'] = [
      'callback' => static::class . '::changeEntityTypeAjax',
      'event' => 'change',
      'wrapper' => $htmlId,
    ];

    $parents = array_merge($element['#parents'], ['entity_type']);
    $entity_type = $form_state->getValue($parents) ?: $element['entity_type']['#default_value'];

    $element['uri']['#prefix'] = '<div id="' . $htmlId . '" class="form-wrapper">';
    $element['uri']['#suffix'] = '</div>';
    $element['uri']['#target_type'] = $entity_type;
    return $element;
  }

  /**
   * AJAX Callback to change the entity type targeted by the autocomplete.
   *
   * @param array $form
   *   The form structure and elements of the full form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   THe form state, build information and form values.
   */
  public static function changeEntityTypeAjax(array &$form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    $parents = array_slice($trigger['#array_parents'], 0, -1);

    $elements = NestedArray::getValue($form, $parents);
    $elements['uri']['#value'] = '';

    return $elements['uri'];
  }

  /**
   * {@inheritdoc}
   */
  protected static function getUriAsDisplayableString($uri) {
    $scheme = parse_url($uri, PHP_URL_SCHEME);

    // By default, the displayable string is the URI.
    $displayable_string = $uri;

    // A different displayable string may be chosen in case of the 'internal:'
    // or 'entity:' built-in schemes.
    if ($scheme === 'internal') {
      $uri_reference = explode(':', $uri, 2)[1];

      // @todo '<front>' is valid input for BC reasons, may be removed by
      //   https://www.drupal.org/node/2421941
      $path = parse_url($uri, PHP_URL_PATH);
      if ($path === '/') {
        $uri_reference = '<front>' . substr($uri_reference, 1);
      }

      $displayable_string = $uri_reference;
    }
    elseif ($scheme === 'entity') {
      list($entity_type, $entity_id) = explode('/', substr($uri, 7), 2);

      if ($entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id)) {
        $displayable_string = EntityAutocomplete::getEntityLabels([$entity]);
      }
    }
    elseif ($scheme === 'route') {
      $displayable_string = ltrim($displayable_string, 'route:');
    }

    return $displayable_string;
  }

  /**
   * {@inheritdoc}
   */
  protected static function getUserEnteredStringAsUri($string, $entity_type_id = NULL) {
    // By default, assume the entered string is an URI.
    $uri = trim($string);

    // Detect entity autocomplete string, map to 'entity:' URI.
    $entity_id = EntityAutocomplete::extractEntityIdFromAutocompleteInput($string);
    if ($entity_id !== NULL) {
      // @todo Watch for Drupal issue for fix in
      // https://www.drupal.org/node/2423093.
      $entityPrefix = 'entity:' . ($entity_type_id ?? 'node');
      $uri = $entityPrefix . '/' . $entity_id;
    }
    // Support linking to nothing.
    elseif (in_array($uri, ['<nolink>', '<none>'], TRUE)) {
      $uri = 'route:' . $uri;
    }
    // Detect a schemeless string, map to 'internal:' URI.
    elseif (!empty($uri) && parse_url($uri, PHP_URL_SCHEME) === NULL) {
      // @todo '<front>' is valid input for BC reasons, may be removed by
      //   https://www.drupal.org/node/2421941
      // - '<front>' -> '/'
      // - '<front>#foo' -> '/#foo'
      if (strpos($uri, '<front>') === 0) {
        $uri = '/' . substr($uri, strlen('<front>'));
      }
      $uri = 'internal:' . $uri;
    }

    return $uri;
  }

  /**
   * {@inheritdoc}
   */
  public static function validateUriElement($element, FormStateInterface $form_state, $form) {
    $typeElement = $element['#parents'];
    array_pop($typeElement);
    $typeElement[] = 'entity_type';

    $uri = static::getUserEnteredStringAsUri($element['#value'], $form_state->getValue($typeElement));
    $form_state->setValueForElement($element, $uri);

    // If getUserEnteredStringAsUri() mapped the entered value to a 'internal:'
    // URI , ensure the raw value begins with '/', '?' or '#'.
    // @todo '<front>' is valid input for BC reasons, may be removed by
    //   https://www.drupal.org/node/2421941
    if (
      parse_url($uri, PHP_URL_SCHEME) === 'internal'
      && !in_array($element['#value'][0], ['/', '?', '#'], TRUE)
      && substr($element['#value'], 0, 7) !== '<front>'
    ) {
      $form_state->setError($element, t('Manually entered paths should start with one of the following characters: / ? #'));
      return;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    // There should always be entity types which are available for this,
    // however doing the extra check is probably a good precaution.
    if ($linkableEntities = $this->getLinkableEntities()) {
      $element['allowed_entity_types'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Allow linking to these entity types'),
        '#options' => $linkableEntities,
        '#default_value' => $this->getSetting('allowed_entity_types') ?: ['node'],
      ];

      $element['#element_validate'] = [static::class . '::validateSettingsForm'];
    }

    return $element;
  }

  /**
   * Validate the allowed entity types settings (also filters types for saving).
   *
   * @param array $element
   *   The form element being validated.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current form state, build information and form values.
   * @param array $form
   *   The complete form structure and elements.
   */
  public static function validateSettingsForm(array $element, FormStateInterface $form_state, array $form) {
    $values = NestedArray::getValue($form_state->getValues(), $element['#parents']);
    $values['allowed_entity_types'] = array_filter($values['allowed_entity_types']);

    $form_state->setValueForElement($element['allowed_entity_types'], $values['allowed_entity_types']);
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();
    $allowedTypes = array_filter($this->getSetting('allowed_entity_types'));

    if ($allowedTypes) {
      $typeNames = array_intersect_key($this->getLinkableEntities(), $allowedTypes);
      $summary[] = $this->t('Allowed Entity Types: @attributes', [
        '@attributes' => implode(', ', $typeNames),
      ]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state) {
    foreach ($values as &$value) {
      $value['uri'] = static::getUserEnteredStringAsUri($value['uri'], $value['entity_type'] ?? 'node');
      $value += ['options' => []];
    }

    return $values;
  }

}
