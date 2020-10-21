<?php

namespace Drupal\ids\Plugin\Field\FieldType;

use Drupal\Core\Url;
use Drupal\link\Plugin\Field\FieldType\LinkItem as LinkItemCore;

/**
 * Override the Core Link field item to support entity URIs with extra params.
 *
 * Some entity types have canonical links which contain more parameters than
 * the entity ID, and cannot be built using the
 * \Drupal\Core\Url::fromEntityUri() method as it assume only a single URL
 * parameter (the entity ID). This class overrides the
 * \Drupal\link\Plugin\Field\FieldType\LinkItem::getUrl() method to check if the
 * canonical link template requires additional parameters and uses the
 * \Drupal\Core\Entity\EntityInterface::toUrl() method when needed.
 *
 * @see \Drupal\Core\Url::fromEntityUri()
 * @see \Drupal\Core\Entity\EntityIinterface::toUrl()
 */
class LinkItem extends LinkItemCore {

  /**
   * {@inheritdoc}
   */
  public function getUrl() {
    $uri = $this->uri;

    if (preg_match('#entity:([a-z_]+)/(\d+|[a-z_]+)$#', $uri, $matches)) {
      try {
        $entityTypeId = $matches[1];
        $entityId = $matches[2];

        $entityTypeManager = \Drupal::entityTypeManager();
        $entityType = $entityTypeManager->getDefinition($entityTypeId);

        // Get the link template and check if there are any placeholders in the
        // URI, that we suspect are not just the entity ID, and will need a
        // a fully loaded entity to build the URL with.
        $template = $entityType->getLinkTemplate('canonical');
        $count = $template ? preg_match_all('#/{([a-z_]+)}(/|$)#', $template, $matches) : 0;

        // If additional placeholders are needed, or if the placeholder does
        // not match the entity type, assume we need to load the full entity.
        // A truer test would be to load the route and check the parameter
        // definitions to verify if the full entity resolution is needed, but
        // that seems like a lot of overhead, which is probably unnecessary.
        if ($count > 1 || array_search($entityTypeId, $matches[1]) === FALSE) {
          $entity = $entityTypeManager->getStorage($entityTypeId)->load($entityId);

          if ($entity) {
            return $entity->toUrl('canonical', (array) $this->options);
          }
        }
      }
      catch (PluginNotFoundException $e) {
        // Entity type is not available, possibly because module providing it
        // has been removed, catch the exception, and let Url class take an
        // attempt at resolving it.
      }
    }

    return Url::fromUri($uri, (array) $this->options);
  }

}
